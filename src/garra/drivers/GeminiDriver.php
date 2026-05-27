<?php
/**
 * Gemini Driver
 * -------------
 * Targets the Google Generative Language API (v1beta).
 *
 * Key differences from OpenAI:
 *  - API key passed as query param (?key=), not Authorization header
 *  - No system message role — system prompt goes in systemInstruction field
 *  - Messages use "parts" arrays, not "content" strings
 *  - Roles are "user" and "model" (not "assistant")
 *  - Tool definitions use "functionDeclarations" inside a "tools" array
 *  - Tool calls come back as "functionCall" parts
 *  - Tool results are sent as "functionResponse" parts in a "user" turn
 *
 * Supported models: gemini-2.0-flash, gemini-1.5-pro, gemini-1.5-flash
 * Docs: https://ai.google.dev/api/generate-content
 */
if (!defined('GARRA_EXEC')) exit;

require_once __DIR__ . '/LLMDriver.php';

class GeminiDriver extends LLMDriver
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function chat(array $messages, array $tools = []): array
    {
        $model  = $this->config['model'] ?? 'gemini-2.0-flash';
        $apiKey = $this->config['api_key'] ?? '';
        $url    = self::BASE . $model . ':generateContent?key=' . urlencode($apiKey);

        [$systemText, $contents] = $this->convertMessages($messages);

        $payload = ['contents' => $contents];

        if ($systemText) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemText]],
            ];
        }

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        // Safety settings — relax to avoid blocking legitimate agent calls
        $payload['safetySettings'] = [
            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ];

        $raw = $this->curlPost($url, $payload, ['Content-Type: application/json']);

        return $this->normalise($raw);
    }

    public function formatTools(array $tools): array
    {
        // Gemini wraps all functions in a single "tools" object with functionDeclarations
        if (empty($tools)) return [];

        return [[
            'functionDeclarations' => array_map(fn($tool) => [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'parameters'  => $this->convertSchema($tool['parameters']),
            ], $tools),
        ]];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert Garra's internal message format to Gemini's contents array.
     * Returns [$systemText|null, $contentsArray].
     */
    private function convertMessages(array $messages): array
    {
        $system   = null;
        $contents = [];

        $i = 0;
        while ($i < count($messages)) {
            $msg = $messages[$i];

            switch ($msg['role']) {
                case 'system':
                    $system = $msg['content'];
                    $i++;
                    break;

                case 'user':
                    $contents[] = [
                        'role'  => 'user',
                        'parts' => [['text' => $msg['content'] ?? '']],
                    ];
                    $i++;
                    break;

                case 'assistant':
                    $contents[] = [
                        'role'  => 'model',
                        'parts' => [['text' => $msg['content'] ?? '']],
                    ];
                    $i++;
                    break;

                // Replay the full original parts array verbatim (preserves thought_signature)
                case 'assistant_tool_call':
                    $rawParts = $msg['tool_calls_raw'] ?? [];
                    if ($rawParts) {
                        $contents[] = ['role' => 'model', 'parts' => $rawParts];
                    }
                    $i++;
                    break;

                // Batch ALL consecutive tool results into one user turn
                // Gemini requires all functionResponse parts in a single message
                case 'tool':
                    $parts = [];
                    while ($i < count($messages) && $messages[$i]['role'] === 'tool') {
                        $m       = $messages[$i];
                        $decoded = json_decode($m['content'] ?? '', true);
                        $parts[] = [
                            'functionResponse' => [
                                'name'     => $m['name'] ?? 'tool',
                                'response' => is_array($decoded) ? $decoded : ['result' => ($m['content'] ?? '')],
                            ],
                        ];
                        $i++;
                    }
                    if ($parts) {
                        $contents[] = ['role' => 'user', 'parts' => $parts];
                    }
                    break;

                default:
                    $i++;
                    break;
            }
        }

        return [$system, $contents];
    }

    /**
     * Normalise Gemini's raw response into Garra's standard shape.
     */
    private function normalise(array $raw): array
    {
        $candidate = $raw['candidates'][0] ?? [];
        $parts     = $candidate['content']['parts'] ?? [];

        // Check for function call parts
        $functionCallParts = array_values(array_filter($parts, fn($p) => isset($p['functionCall'])));

        if (!empty($functionCallParts)) {
            // Normalised tool calls for Garra's engine
            $toolCalls = array_map(fn($p) => [
                'id'        => uniqid('gemini_', true),
                'name'      => $p['functionCall']['name'],
                'arguments' => (array)($p['functionCall']['args'] ?? []),
            ], $functionCallParts);

            // Store the FULL parts array verbatim — Gemini 2.x requires thought_signature
            // to be replayed exactly as received, otherwise it rejects the next turn.
            return [
                'content'        => null,
                'tool_calls'     => $toolCalls,
                'tool_calls_raw' => $parts, // full parts array, not just functionCall parts
                'raw'            => $raw,
            ];
        }

        // Text response — join all text parts
        $text = implode('', array_map(
            fn($p) => $p['text'] ?? '',
            array_filter($parts, fn($p) => isset($p['text']))
        ));

        // Surface finish reason errors
        $finishReason = $candidate['finishReason'] ?? '';
        if (in_array($finishReason, ['SAFETY', 'RECITATION', 'OTHER'], true) && !$text) {
            throw new RuntimeException("Gemini blocked response: finishReason={$finishReason}");
        }

        return [
            'content'    => $text,
            'tool_calls' => null,
            'raw'        => $raw,
        ];
    }

    /**
     * Gemini's JSON schema format is nearly identical to JSON Schema but
     * doesn't support 'enum' inside 'items' on arrays, and doesn't use
     * 'const'. Strip unsupported keywords to avoid API errors.
     */
    private function convertSchema(array $schema): array
    {
        // Remove keys Gemini doesn't accept at the top level
        unset($schema['$schema'], $schema['$id'], $schema['additionalProperties']);

        if (!empty($schema['properties'])) {
            foreach ($schema['properties'] as $k => $v) {
                $schema['properties'][$k] = $this->convertSchema($v);
            }
        }

        if (!empty($schema['items'])) {
            $schema['items'] = $this->convertSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * Override curlPost to handle Gemini's non-Bearer auth (key in URL).
     * No Authorization header needed — key is already in the URL.
     */
    protected function curlPost(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers, // No auth header
            CURLOPT_TIMEOUT        => $this->config['settings']['timeout'] ?? 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) throw new RuntimeException("cURL error [{$errno}]: {$error}");

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON from Gemini (HTTP {$http}): " . substr($raw, 0, 300));
        }

        if ($http >= 400) {
            $msg = $decoded['error']['message'] ?? "HTTP {$http}";
            throw new RuntimeException("Gemini error: {$msg}");
        }

        return $decoded;
    }
}
