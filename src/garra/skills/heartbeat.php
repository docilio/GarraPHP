<?php
/**
 * Skill: heartbeat
 * ================
 * Monitor URLs for availability, response time, HTTP status, and keyword presence.
 * History is stored in storage/heartbeat/ as newline-delimited JSON.
 *
 * Actions:
 *   check   — ping URLs right now
 *   history — return past results for a URL
 *   summary — latest status of all config-defined targets
 */
if (!defined('GARRA_EXEC')) exit;

// ---------------------------------------------------------------------------
// Definition
// ---------------------------------------------------------------------------

function heartbeat_definition(): array
{
    return [
        'name'        => 'heartbeat',
        'description' => 'Monitor one or more URLs for availability, HTTP status code, response time in milliseconds, and optional keyword presence. Use to check if websites or APIs are up, slow, or returning errors. Can also retrieve historical uptime data.',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'enum'        => ['check', 'history', 'summary'],
                    'description' => '"check" pings URLs now. "history" returns past results for a URL. "summary" returns latest status of all configured targets.',
                ],
                'urls' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'URLs to check. Required for action=check if no targets are configured.',
                ],
                'keyword' => [
                    'type'        => 'string',
                    'description' => 'Optional string to search for in the response body.',
                ],
                'history_url' => [
                    'type'        => 'string',
                    'description' => 'URL to retrieve history for. Required for action=history.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Max history entries to return (default 10, max 50).',
                ],
            ],
            'required' => ['action'],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Execution
// ---------------------------------------------------------------------------

function heartbeat_execute(array $args): array
{
    $action = $args['action'] ?? 'check';
    $config = heartbeat_load_config();

    switch ($action) {
        case 'check':   return heartbeat_do_check($args, $config);
        case 'history': return heartbeat_do_history($args, $config);
        case 'summary': return heartbeat_do_summary($config);
        default:        return ['error' => "Unknown action '{$action}'."];
    }
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

function heartbeat_do_check(array $args, array $config): array
{
    $urls    = $args['urls'] ?? [];
    $keyword = $args['keyword'] ?? null;
    $timeout = (int)($config['timeout'] ?? 10);
    $slow    = (int)($config['alert_threshold'] ?? 2000);

    if (empty($urls)) {
        foreach ($config['targets'] ?? [] as $t) {
            $urls[] = is_array($t) ? $t['url'] : $t;
        }
    }

    if (empty($urls)) {
        return ['error' => 'No URLs provided and no targets configured in config.php heartbeat.targets.'];
    }

    $results    = [];
    $storageDir = heartbeat_ensure_storage($config);

    foreach ($urls as $url) {
        $result         = heartbeat_ping($url, $timeout, $keyword);
        $result['slow'] = $result['response_ms'] > $slow;
        $result['ts']   = date('c');

        if ($storageDir) {
            heartbeat_append($storageDir, $url, $result);
        }

        $results[] = $result;
    }

    $allUp = array_reduce($results, fn($c, $r) => $c && $r['up'], true);

    return [
        'all_up'  => $allUp,
        'checked' => count($results),
        'results' => $results,
    ];
}

function heartbeat_do_history(array $args, array $config): array
{
    $url   = trim($args['history_url'] ?? '');
    $limit = min((int)($args['limit'] ?? 10), 50);

    if (!$url) return ['error' => 'history_url is required for action=history.'];

    $dir  = heartbeat_ensure_storage($config);
    $file = $dir . '/' . heartbeat_key($url) . '.jsonl';

    if (!$dir || !file_exists($file)) {
        return ['url' => $url, 'total' => 0, 'history' => []];
    }

    $lines   = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $total   = count($lines);
    $entries = [];

    foreach (array_reverse($lines) as $line) {
        $row = json_decode($line, true);
        if ($row) $entries[] = $row;
        if (count($entries) >= $limit) break;
    }

    $upCount = count(array_filter(
        array_map(fn($l) => json_decode($l, true), $lines),
        fn($e) => !empty($e['up'])
    ));

    return [
        'url'        => $url,
        'total'      => $total,
        'uptime_pct' => $total > 0 ? round(($upCount / $total) * 100, 2) : null,
        'history'    => $entries,
    ];
}

function heartbeat_do_summary(array $config): array
{
    $targets = $config['targets'] ?? [];

    if (empty($targets)) {
        return ['message' => 'No targets in config.php heartbeat.targets. Pass urls to action=check instead.'];
    }

    $dir     = heartbeat_ensure_storage($config);
    $summary = [];

    foreach ($targets as $target) {
        $url  = is_array($target) ? $target['url']  : $target;
        $name = is_array($target) ? ($target['name'] ?? $url) : $url;
        $file = $dir . '/' . heartbeat_key($url) . '.jsonl';
        $last = null;

        if ($dir && file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!empty($lines)) {
                $last = json_decode(end($lines), true);
            }
        }

        $summary[] = [
            'name'    => $name,
            'url'     => $url,
            'up'      => $last ? $last['up'] : null,
            'status'  => $last['status'] ?? null,
            'ms'      => $last['response_ms'] ?? null,
            'slow'    => $last['slow'] ?? null,
            'checked' => $last['ts'] ?? 'never',
        ];
    }

    return ['targets' => $summary];
}

// ---------------------------------------------------------------------------
// Core ping
// ---------------------------------------------------------------------------

function heartbeat_ping(string $url, int $timeout, ?string $keyword): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_USERAGENT      => 'GarraPHP-Heartbeat/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER         => false,
    ]);

    $start  = microtime(true);
    $body   = curl_exec($ch);
    $ms     = (int)round((microtime(true) - $start) * 1000);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    curl_close($ch);

    $result = [
        'url'         => $url,
        'up'          => !$errno && $status >= 200 && $status < 400,
        'status'      => $status,
        'response_ms' => $ms,
        'error'       => $error ?: null,
    ];

    if ($keyword !== null) {
        $result['keyword']       = $keyword;
        $result['keyword_found'] = is_string($body) && str_contains($body, $keyword);
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function heartbeat_load_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $file = __DIR__ . '/../config.php';
    if (file_exists($file)) {
        $full = require $file;
        $cfg  = $full['heartbeat'] ?? [];
        $cfg['_storage_base'] = $full['settings']['storage_dir'] ?? (__DIR__ . '/../storage');
    } else {
        $cfg = [];
    }

    return $cfg;
}

function heartbeat_ensure_storage(array $config): ?string
{
    $base = rtrim($config['_storage_base'] ?? (__DIR__ . '/../storage'), '/');
    $dir  = $base . '/heartbeat';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return is_writable($dir) ? $dir : null;
}

function heartbeat_key(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST) ?? $url;
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    return preg_replace('/[^a-z0-9_-]/', '_', strtolower($host . $path));
}

function heartbeat_append(string $dir, string $url, array $entry): void
{
    $file  = $dir . '/' . heartbeat_key($url) . '.jsonl';
    $limit = 1000;

    file_put_contents($file, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

    // Trim file to last $limit lines
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($lines) > $limit) {
            file_put_contents($file, implode("\n", array_slice($lines, -$limit)) . "\n", LOCK_EX);
        }
    }
}
