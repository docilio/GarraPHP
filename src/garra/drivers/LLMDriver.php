<?php
/**
 * LLMDriver — Base contract for all provider drivers.
 *
 * Every driver must implement two things:
 *   1. chat()  — send messages (+optional tools) and return a normalised response.
 *   2. formatTools() — convert Garra's internal tool schema into the format the
 *                      provider expects inside the API request payload.
 *
 * Normalised response shape (what Garra's engine works with):
 * [
 *   'content'    => string|null,   // Final text answer (null when a tool is called)
 *   'tool_calls' => array|null,    // Normalised tool call list (null when no tool)
 *   'raw'        => array,         // Full raw provider response for debugging
 * ]
 *
 * Normalised tool_call item shape:
 * [
 *   'id'        => string,   // Provider-supplied call ID (used in tool result messages)
 *   'name'      => string,   // Skill name to execute
 *   'arguments' => array,    // Decoded JSON arguments
 * ]
 */
if (!defined('GARRA_EXEC')) exit;

abstract class LLMDriver
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Send a conversation and return a normalised response array.
     *
     * @param  array $messages  Full conversation history in Garra's internal format.
     * @param  array $tools     Tool definitions already formatted by formatTools().
     * @return array            Normalised response.
     */
    abstract public function chat(array $messages, array $tools = []): array;

    /**
     * Convert Garra's internal tool schema into the provider-specific format.
     * Called once per agent loop; the result is passed directly into chat().
     *
     * @param  array $tools  Garra's internal tool definitions.
     * @return array         Provider-formatted tool list.
     */
    abstract public function formatTools(array $tools): array;

    // -------------------------------------------------------------------------
    // Shared helpers available to all drivers
    // -------------------------------------------------------------------------

    /**
     * Execute a cURL POST request and return the decoded JSON response.
     * Throws a RuntimeException on network or JSON errors so the engine
     * can catch and surface a clean error message.
     */
    protected function curlPost(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->config['settings']['timeout'] ?? 25,
            // Basic SSL verification — keep ON in production.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException("cURL error [{$errno}]: {$error}");
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Invalid JSON from provider (HTTP {$http}): " . substr($raw, 0, 300)
            );
        }

        // Surface provider-level error messages cleanly.
        if ($http >= 400) {
            $msg = $decoded['error']['message']
                ?? $decoded['error']
                ?? "HTTP {$http} error from provider.";
            throw new RuntimeException("Provider error: {$msg}");
        }

        return $decoded;
    }

    /**
     * Build the Authorization header string for Bearer-token APIs.
     */
    protected function bearerHeader(): string
    {
        return 'Authorization: Bearer ' . $this->config['api_key'];
    }
}
