<?php
/**
 * Ollama Driver
 * -------------
 * Targets the Ollama REST API which mirrors the OpenAI /chat/completions
 * interface. No API key is required for local deployments.
 *
 * Default base_url in config.php: 'http://localhost:11434/v1'
 *
 * Tool calling support depends on the underlying model — llama3, mistral-nemo,
 * and qwen2.5 have solid support; older models may ignore tool definitions.
 *
 * Docs: https://github.com/ollama/ollama/blob/main/docs/openai.md
 */
if (!defined('GARRA_EXEC')) exit;

require_once __DIR__ . '/OpenAIDriver.php';

/**
 * Ollama's /v1/chat/completions endpoint is intentionally compatible with
 * OpenAI's, so we inherit OpenAIDriver and only override what differs.
 */
class OllamaDriver extends OpenAIDriver
{
    public function chat(array $messages, array $tools = []): array
    {
        // Ollama ignores the Bearer token but our parent sends it anyway — harmless.
        // The only behavioural difference: we disable SSL verification for localhost.
        return parent::chat($messages, $tools);
    }

    /**
     * Override the shared curlPost helper to relax SSL for local servers.
     * Production deployments behind a reverse proxy with TLS can remove this.
     */
    protected function curlPost(string $url, array $payload, array $headers): array
    {
        $isLocal = str_contains($url, 'localhost') || str_contains($url, '127.0.0.1');

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->config['settings']['timeout'] ?? 25,
            CURLOPT_SSL_VERIFYPEER => !$isLocal,
            CURLOPT_SSL_VERIFYHOST => $isLocal ? 0 : 2,
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
                "Invalid JSON from Ollama (HTTP {$http}): " . substr($raw, 0, 300)
            );
        }

        if ($http >= 400) {
            $msg = $decoded['error']['message'] ?? $decoded['error'] ?? "HTTP {$http}";
            throw new RuntimeException("Ollama error: {$msg}");
        }

        return $decoded;
    }
}
