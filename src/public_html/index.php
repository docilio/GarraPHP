<?php
/**
 * GarraPHP — Public Entry Point
 * ==============================
 * POST /index.php              — run the agent
 * GET  /index.php?action=ping  — health check
 * GET  /index.php?action=skills — list skills
 *
 * Auth:  X-Garra-Key header (when config auth.enabled = true)
 * Rate:  per-IP or per-key sliding window
 */
define('GARRA_EXEC', true);

// ── Path to the garra/ engine folder (one level above public_html) ────────
define('GARRA_ROOT', dirname(__DIR__) . '/garra');

$config = require GARRA_ROOT . '/config.php';
require  GARRA_ROOT . '/garra.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Garra-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ─── Auth check ───────────────────────────────────────────────────────────
$authCfg = $config['auth'] ?? [];
$keyInfo  = null;  // populated if key-based auth passes

if (!empty($authCfg['enabled'])) {
    $isPing = ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'ping');

    // Ping and UI are optionally exempt
    $skipAuth = ($isPing && !empty($authCfg['ping_exempt']));

    if (!$skipAuth) {
        $providedKey = $_SERVER['HTTP_X_GARRA_KEY']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? ($_GET['key'] ?? '');

        // Strip "Bearer " prefix if present
        $providedKey = preg_replace('/^Bearer\s+/i', '', trim($providedKey));

        $validKeys = $authCfg['keys'] ?? [];
        $matched   = false;

        foreach ($validKeys as $key => $meta) {
            if (hash_equals($key, $providedKey)) {
                $matched = true;
                $keyInfo = $meta;
                break;
            }
        }

        if (!$matched) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'response' => 'Unauthorized. Provide a valid X-Garra-Key header.']);
            exit;
        }
    }
}

// ─── Rate limiting ────────────────────────────────────────────────────────
$rlCfg = $config['rate_limit'] ?? [];

if (!empty($rlCfg['enabled']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rlKey    = $keyInfo ? ('key_' . substr(md5(json_encode($keyInfo)), 0, 12))
                         : ('ip_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $window   = (int)($rlCfg['window'] ?? 60);
    $limit    = (int)($keyInfo['rate_limit'] ?? $rlCfg['limit'] ?? 20);
    $backend  = $rlCfg['backend'] ?? 'file';
    $storageDir = rtrim($config['settings']['storage_dir'], '/');

    $count = ratelimit_check($rlKey, $window, $storageDir, $backend, $config);

    if ($count > $limit) {
        http_response_code(429);
        header("Retry-After: {$window}");
        echo json_encode(['status' => 'error', 'response' => "Rate limit exceeded. Max {$limit} requests per {$window}s."]);
        exit;
    }
}

// ─── GET endpoints ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'ping':
            echo json_encode([
                'status'   => 'success',
                'response' => 'GarraPHP is running.',
                'provider' => $config['provider'],
                'model'    => $config['model'],
            ]);
            exit;

        case 'skills':
            try {
                $agent = new Garra($config);
                echo json_encode(['status' => 'success', 'response' => $agent->getSkillNames()]);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'response' => $e->getMessage()]);
            }
            exit;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'response' => 'GET requires ?action=ping or ?action=skills.']);
            exit;
    }
}

// ─── POST — agent ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'response' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'response' => 'Invalid JSON body.']);
    exit;
}

$goal = trim($input['goal'] ?? '');
if (!$goal) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'response' => 'Missing field: goal.']);
    exit;
}

// ─── Session ──────────────────────────────────────────────────────────────
$history     = [];
$sessionId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['session_id'] ?? '');
$storageDir  = rtrim($config['settings']['storage_dir'], '/');
$sessionFile = $sessionId ? "{$storageDir}/session_{$sessionId}.json" : null;

if ($sessionFile && file_exists($sessionFile)) {
    $saved   = json_decode(file_get_contents($sessionFile), true);
    $history = is_array($saved) ? $saved : [];
}

// ─── Run ──────────────────────────────────────────────────────────────────
try {
    $agent  = new Garra($config);
    $result = $agent->run($goal, $history);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'response' => 'Agent failed: ' . $e->getMessage()]);
    exit;
}

// ─── Persist session ──────────────────────────────────────────────────────
if ($sessionFile && is_dir($storageDir) && is_writable($storageDir)) {
    file_put_contents($sessionFile, json_encode($result['history'], JSON_PRETTY_PRINT), LOCK_EX);
}

// ─── Respond ──────────────────────────────────────────────────────────────
if (!$result['success']) http_response_code(500);

echo json_encode([
    'status'     => $result['success'] ? 'success' : 'error',
    'response'   => $result['response'],
    'session_id' => $sessionId ?: null,
]);

// ─── Rate limit helper ────────────────────────────────────────────────────

function ratelimit_check(string $key, int $window, string $storageDir, string $backend, array $config): int
{
    $dir  = $storageDir . '/ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $file = $dir . '/' . preg_replace('/[^a-z0-9_]/', '_', strtolower($key)) . '.json';
    $now  = time();

    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?? ['hits' => []];
    } else {
        $data = ['hits' => []];
    }

    // Drop hits outside the window
    $data['hits'] = array_filter($data['hits'], fn($ts) => $ts > $now - $window);
    $data['hits'][] = $now;

    file_put_contents($file, json_encode($data), LOCK_EX);

    return count($data['hits']);
}
