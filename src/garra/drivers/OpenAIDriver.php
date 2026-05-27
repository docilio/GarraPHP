<?php
/**
 * OpenAI Driver
 * -------------
 * Supports the /v1/chat/completions endpoint used by OpenAI and any
 * compatible provider (e.g. Groq, Together AI, Azure OpenAI with minor tweaks).
 *
 * Tool calling follows the OpenAI "tools" format introduced in 2023:
 * https://platform.openai.com/docs/guides/function-calling
 */
if (!defined('GARRA_EXEC')) exit;

require_once __DIR__ . '/LLMDriver.php';

class OpenAIDriver extends LLMDriver
{
    public function chat(array $messages, array $tools = []): array
    {
        $payload = [
            'model'    => $this->config['model'],
            'messages' => $this->convertMessages($messages),
        ];

        if (!empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $raw = $this->curlPost(
            rtrim($this->config['base_url'], '/') . '/chat/completions',
            $payload,
            [
                'Content-Type: application/json',
                $this->bearerHeader(),
            ]
        );

        return $this->normalise($raw);
    }

    public function formatTools(array $tools): array
    {
        // OpenAI wraps each tool in a {"type":"function","function":{...}} envelope.
        return array_map(fn($tool) => [
            'type'     => 'function',
            'function' => [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'parameters'  => $tool['parameters'],
            ],
        ], $tools);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert Garra's internal message format to OpenAI's expected shape.
     * Garra uses a superset; we strip/remap what OpenAI doesn't understand.
     */
    private function convertMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $msg) {
            switch ($msg['role']) {
                case 'user':
                case 'system':
                case 'assistant':
                    $out[] = ['role' => $msg['role'], 'content' => $msg['content']];
                    break;

                case 'tool':
                    // OpenAI expects tool results with the call ID and function name.
                    $out[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $msg['tool_call_id'],
                        'name'         => $msg['name'],
                        'content'      => is_string($msg['content'])
                                            ? $msg['content']
                                            : json_encode($msg['content']),
                    ];
                    break;

                // assistant message that contained tool_calls
                case 'assistant_tool_call':
                    $out[] = [
                        'role'       => 'assistant',
                        'content'    => null,
                        'tool_calls' => $msg['tool_calls_raw'],
                    ];
                    break;
            }
        }

        return $out;
    }

    /**
     * Map the raw OpenAI response into Garra's normalised response shape.
     */
    private function normalise(array $raw): array
    {
        $message = $raw['choices'][0]['message'] ?? [];

        // Tool call response
        if (!empty($message['tool_calls'])) {
            $toolCalls = array_map(fn($tc) => [
                'id'        => $tc['id'],
                'name'      => $tc['function']['name'],
                'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
            ], $message['tool_calls']);

            return [
                'content'        => null,
                'tool_calls'     => $toolCalls,
                'tool_calls_raw' => $message['tool_calls'], // preserved for history
                'raw'            => $raw,
            ];
        }

        // Final text response
        return [
            'content'    => $message['content'] ?? '',
            'tool_calls' => null,
            'raw'        => $raw,
        ];
    }
}
