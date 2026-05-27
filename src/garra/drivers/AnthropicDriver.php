<?php
/**
 * Anthropic Driver
 * ----------------
 * Targets the /v1/messages endpoint (Claude 3+ models).
 *
 * Key differences from OpenAI:
 *  - Auth header is "x-api-key" not "Authorization: Bearer"
 *  - Requires "anthropic-version" header
 *  - System prompt is a top-level field, not a message role
 *  - Tool results are sent as a "user" message with content type "tool_result"
 *  - Tool call blocks live inside the "content" array of an assistant message
 *
 * Docs: https://docs.anthropic.com/en/api/messages
 */
if (!defined('GARRA_EXEC')) exit;

require_once __DIR__ . '/LLMDriver.php';

class AnthropicDriver extends LLMDriver
{
    private const API_VERSION = '2023-06-01';

    public function chat(array $messages, array $tools = []): array
    {
        // Anthropic requires system prompt as a separate top-level field.
        [$system, $converted] = $this->convertMessages($messages);

        $payload = [
            'model'      => $this->config['model'],
            'max_tokens' => 4096,
            'messages'   => $converted,
        ];

        if ($system !== null) {
            $payload['system'] = $system;
        }

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        $raw = $this->curlPost(
            rtrim($this->config['base_url'], '/') . '/messages',
            $payload,
            [
                'Content-Type: application/json',
                'x-api-key: ' . $this->config['api_key'],
                'anthropic-version: ' . self::API_VERSION,
            ]
        );

        return $this->normalise($raw);
    }

    public function formatTools(array $tools): array
    {
        // Anthropic's schema is very close to Garra's internal format.
        return array_map(fn($tool) => [
            'name'         => $tool['name'],
            'description'  => $tool['description'],
            'input_schema' => $tool['parameters'], // Anthropic uses "input_schema"
        ], $tools);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Split out the system message and convert the rest into Anthropic format.
     * Returns [$systemText|null, $convertedMessages].
     */
    private function convertMessages(array $messages): array
    {
        $system    = null;
        $converted = [];

        foreach ($messages as $msg) {
            switch ($msg['role']) {
                case 'system':
                    $system = $msg['content'];
                    break;

                case 'user':
                    $converted[] = ['role' => 'user', 'content' => $msg['content']];
                    break;

                case 'assistant':
                    $converted[] = ['role' => 'assistant', 'content' => $msg['content']];
                    break;

                // Assistant message that contained tool_use blocks
                case 'assistant_tool_call':
                    $converted[] = [
                        'role'    => 'assistant',
                        'content' => $msg['tool_calls_raw'], // array of content blocks
                    ];
                    break;

                // Tool result — must be a "user" message in Anthropic's format
                case 'tool':
                    $converted[] = [
                        'role'    => 'user',
                        'content' => [[
                            'type'        => 'tool_result',
                            'tool_use_id' => $msg['tool_call_id'],
                            'content'     => is_string($msg['content'])
                                                ? $msg['content']
                                                : json_encode($msg['content']),
                        ]],
                    ];
                    break;
            }
        }

        return [$system, $converted];
    }

    /**
     * Map Anthropic's raw response into Garra's normalised shape.
     */
    private function normalise(array $raw): array
    {
        $content = $raw['content'] ?? [];

        // Collect tool_use blocks
        $toolUseBlocks = array_filter($content, fn($b) => ($b['type'] ?? '') === 'tool_use');

        if (!empty($toolUseBlocks)) {
            $toolCalls = array_map(fn($b) => [
                'id'        => $b['id'],
                'name'      => $b['name'],
                'arguments' => $b['input'] ?? [],
            ], array_values($toolUseBlocks));

            return [
                'content'        => null,
                'tool_calls'     => $toolCalls,
                'tool_calls_raw' => $content, // full content block array for history
                'raw'            => $raw,
            ];
        }

        // Text-only response — grab the first text block
        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = $block['text'];
                break;
            }
        }

        return [
            'content'    => $text,
            'tool_calls' => null,
            'raw'        => $raw,
        ];
    }
}
