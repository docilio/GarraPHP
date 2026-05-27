<?php
/**
 * Skill: webhook
 * ==============
 * Send outbound HTTP requests to external services.
 * Works with Zapier, Make (Integromat), n8n, Slack, Discord,
 * WordPress REST API, or any HTTP endpoint.
 *
 * Security:
 *   - Outbound hosts are validated against config.php webhook.allowed_hosts.
 *   - An empty allowlist permits any host (development only).
 *   - Requests are capped at config timeout and support up to max_retries.
 *
 * Actions:
 *   post    — POST JSON payload to a URL
 *   get     — GET a URL and return the response
 *   slack   — Post a formatted message to a Slack webhook URL
 *   discord — Post a message to a Discord webhook URL
 */
if (!defined('GARRA_EXEC')) exit;

// ---------------------------------------------------------------------------
// Definition
// ---------------------------------------------------------------------------

function webhook_definition(): array
{
    return [
        'name'        => 'webhook',
        'description' => 'Send HTTP requests to external services and automation platforms. Use to trigger Zapier zaps, Make scenarios, n8n workflows, post Slack/Discord messages, call WordPress REST API endpoints, or push data to any HTTP webhook. Supports GET and POST with JSON payloads.',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'enum'        => ['post', 'get', 'slack', 'discord'],
                    'description' => '"post" sends JSON to a URL. "get" fetches a URL. "slack" sends a Slack message. "discord" sends a Discord message.',
                ],
                'url' => [
                    'type'        => 'string',
                    'description' => 'The full URL to call. Required for all actions.',
                ],
                'payload' => [
                    'type'        => 'object',
                    'description' => 'JSON payload to POST. Used for action=post. Any key-value structure.',
                ],
                'headers' => [
                    'type'        => 'object',
                    'description' => 'Optional HTTP headers as key-value pairs.',
                ],
                'message' => [
                    'type'        => 'string',
                    'description' => 'Message text. Used for action=slack and action=discord.',
                ],
                'title' => [
                    'type'        => 'string',
                    'description' => 'Optional title or username override for Slack/Discord messages.',
                ],
            ],
            'required' => ['action', 'url'],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Execution
// ---------------------------------------------------------------------------

function webhook_execute(array $args): array
{
    $action = $args['action'] ?? 'post';
    $url    = trim($args['url'] ?? '');
    $config = webhook_load_config();

    if (!$url) return ['error' => 'url is required.'];

    // Validate host against allowlist
    $hostError = webhook_validate_host($url, $config);
    if ($hostError) return ['error' => $hostError];

    switch ($action) {
        case 'post':    return webhook_post($url, $args, $config);
        case 'get':     return webhook_get($url, $args, $config);
        case 'slack':   return webhook_slack($url, $args, $config);
        case 'discord': return webhook_discord($url, $args, $config);
        default:        return ['error' => "Unknown action '{$action}'."];
    }
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

function webhook_post(string $url, array $args, array $config): array
{
    $payload = $args['payload'] ?? new stdClass(); // {} if empty
    $headers = $args['headers'] ?? [];

    $defaultHeaders = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $defaultHeaders[] = "{$k}: {$v}";
    }

    $response = webhook_curl_post($url, json_encode($payload), $defaultHeaders, $config);

    return [
        'success'     => $response['http'] >= 200 && $response['http'] < 300,
        'http_status' => $response['http'],
        'body'        => webhook_parse_body($response['body']),
        'url'         => $url,
    ];
}

function webhook_get(string $url, array $args, array $config): array
{
    $headers = [];
    foreach ($args['headers'] ?? [] as $k => $v) {
        $headers[] = "{$k}: {$v}";
    }

    $timeout = (int)($config['timeout'] ?? 15);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'GarraPHP-Webhook/1.0',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => "cURL error: {$err}"];

    return [
        'success'     => $http >= 200 && $http < 300,
        'http_status' => $http,
        'body'        => webhook_parse_body($body),
        'url'         => $url,
    ];
}

function webhook_slack(string $url, array $args, array $config): array
{
    $message  = trim($args['message'] ?? '');
    $username = trim($args['title'] ?? 'GarraPHP Agent');

    if (!$message) return ['error' => 'message is required for action=slack.'];

    $payload = json_encode([
        'username' => $username,
        'text'     => $message,
    ]);

    $response = webhook_curl_post($url, $payload, ['Content-Type: application/json'], $config);
    $ok       = $response['body'] === 'ok' || ($response['http'] >= 200 && $response['http'] < 300);

    return [
        'success'     => $ok,
        'http_status' => $response['http'],
        'platform'    => 'slack',
    ];
}

function webhook_discord(string $url, array $args, array $config): array
{
    $message  = trim($args['message'] ?? '');
    $username = trim($args['title'] ?? 'GarraPHP Agent');

    if (!$message) return ['error' => 'message is required for action=discord.'];

    $payload = json_encode([
        'username' => $username,
        'content'  => $message,
    ]);

    $response = webhook_curl_post($url, $payload, ['Content-Type: application/json'], $config);

    return [
        'success'     => $response['http'] === 204 || ($response['http'] >= 200 && $response['http'] < 300),
        'http_status' => $response['http'],
        'platform'    => 'discord',
    ];
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function webhook_curl_post(string $url, string $body, array $headers, array $config): array
{
    $timeout  = (int)($config['timeout'] ?? 15);
    $retries  = (int)($config['max_retries'] ?? 2);
    $attempt  = 0;
    $lastErr  = '';

    while ($attempt <= $retries) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'GarraPHP-Webhook/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!$err && $http < 500) {
            return ['http' => $http, 'body' => $resp ?: ''];
        }

        $lastErr = $err ?: "HTTP {$http}";
        $attempt++;

        if ($attempt <= $retries) usleep(500000 * $attempt); // 0.5s, 1s backoff
    }

    return ['http' => 0, 'body' => '', 'error' => "Failed after {$retries} retries: {$lastErr}"];
}

function webhook_parse_body(string $body): mixed
{
    if ($body === '') return null;
    $decoded = json_decode($body, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
}

function webhook_validate_host(string $url, array $config): ?string
{
    $allowed = $config['allowed_hosts'] ?? [];
    if (empty($allowed)) return null; // open — dev mode

    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    if (!$host) return 'Invalid URL — cannot determine host.';

    foreach ($allowed as $pattern) {
        $pattern = strtolower($pattern);
        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1); // '.zapier.com'
            if (str_ends_with($host, $suffix) || $host === ltrim($suffix, '.')) return null;
        } else {
            if ($host === $pattern) return null;
        }
    }

    return "Host '{$host}' is not in the webhook allowed_hosts list in config.php.";
}

function webhook_load_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $file = __DIR__ . '/../config.php';
    $cfg  = file_exists($file) ? (require $file)['webhook'] ?? [] : [];
    return $cfg;
}
