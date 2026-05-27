<?php
/**
 * GarraPHP — Cron Runner
 * ======================
 * Hit this URL every 5 minutes via your server cron manager.
 *
 * cPanel > Cron Jobs > every 5 minutes:
 *   curl -s "https://yourdomain.com/cron.php?secret=YOUR_CRON_SECRET" > /dev/null
 *
 * Protected by a secret token + file lock to prevent overlapping runs.
 */
define('GARRA_EXEC', true);

// ── Path to the garra/ engine folder (one level above public_html) ────────
// Adjust this if your layout differs.
define('GARRA_ROOT', dirname(__DIR__) . '/garra');

$config = require GARRA_ROOT . '/config.php';
require  GARRA_ROOT . '/garra.php';

header('Content-Type: application/json');

// ─── Auth ─────────────────────────────────────────────────────────────────
$expectedSecret = $config['scheduler']['cron_secret'] ?? '';
$givenSecret    = $_GET['secret'] ?? '';

if (!$expectedSecret || !hash_equals($expectedSecret, $givenSecret)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'response' => 'Forbidden.']);
    exit;
}

// ─── File lock — prevent overlapping runs ─────────────────────────────────
$storageDir = rtrim($config['settings']['storage_dir'], '/');
$lockFile   = $storageDir . '/cron.lock';
$lock       = fopen($lockFile, 'c');

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'skipped', 'response' => 'Another cron run is active.']);
    exit;
}

// ─── DB ───────────────────────────────────────────────────────────────────
$pdo = cron_connect($config);

if (!$pdo) {
    flock($lock, LOCK_UN); fclose($lock);
    echo json_encode(['status' => 'error', 'response' => 'DB connection failed.']);
    exit;
}

// ─── Fetch due jobs ───────────────────────────────────────────────────────
set_time_limit((int)($config['scheduler']['max_runtime'] ?? 50) + 10);

try {
    $jobs = $pdo->query("
        SELECT * FROM garra_jobs
        WHERE status = 'pending' AND run_at <= NOW()
        ORDER BY run_at ASC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    flock($lock, LOCK_UN); fclose($lock);
    echo json_encode(['status' => 'error', 'response' => 'Scheduler table missing — run scheduler_setup.php first.']);
    exit;
}

if (empty($jobs)) {
    flock($lock, LOCK_UN); fclose($lock);
    echo json_encode(['status' => 'success', 'ran' => 0, 'response' => 'No jobs due.']);
    exit;
}

// ─── Run each job ─────────────────────────────────────────────────────────
$agent   = new Garra($config);
$results = [];

foreach ($jobs as $job) {
    $id = (int)$job['id'];

    $pdo->prepare("UPDATE garra_jobs SET status='running', last_run_at=NOW() WHERE id=?")
        ->execute([$id]);

    $pdo->prepare("INSERT INTO garra_job_runs (job_id, started_at) VALUES (?, NOW())")
        ->execute([$id]);
    $runId = (int)$pdo->lastInsertId();

    $t0 = microtime(true);

    try {
        $result   = $agent->run($job['goal']);
        $success  = $result['success'];
        $response = $result['response'];
        $errMsg   = null;
    } catch (Throwable $e) {
        $success  = false;
        $response = null;
        $errMsg   = $e->getMessage();
    }

    $elapsed = round(microtime(true) - $t0, 2);

    $pdo->prepare("
        UPDATE garra_job_runs
        SET finished_at=NOW(), success=?, response=?, error=?
        WHERE id=?
    ")->execute([$success ? 1 : 0, $response, $errMsg, $runId]);

    // Recurring or one-off?
    if (empty($job['recur']) || $job['recur'] === 'none') {
        $nextStatus = $success ? 'done' : 'failed';
        $nextRunAt  = null;
    } else {
        $nextStatus = 'pending';
        $nextRunAt  = cron_next_run($job['run_at'], $job['recur']);
    }

    $pdo->prepare("UPDATE garra_jobs SET status=?, run_at=COALESCE(?,run_at) WHERE id=?")
        ->execute([$nextStatus, $nextRunAt, $id]);

    // Email notification
    if (!empty($job['notify_email'])) {
        cron_notify($job, $success, $response ?? $errMsg, $config);
    }

    $results[] = [
        'job_id'  => $id,
        'label'   => $job['label'] ?: "Job #{$id}",
        'success' => $success,
        'elapsed' => "{$elapsed}s",
    ];
}

flock($lock, LOCK_UN);
fclose($lock);

echo json_encode([
    'status'    => 'success',
    'ran'       => count($results),
    'timestamp' => date('c'),
    'jobs'      => $results,
]);

// ─── Helpers ──────────────────────────────────────────────────────────────

function cron_connect(array $config): ?PDO
{
    $db = $config['database'] ?? [];

    if (!empty($db['wp_config_path']) && file_exists($db['wp_config_path'])) {
        $c = file_get_contents($db['wp_config_path']);
        foreach (['name'=>'DB_NAME','user'=>'DB_USER','pass'=>'DB_PASSWORD','host'=>'DB_HOST'] as $k=>$const) {
            if (preg_match("/define\s*\(\s*['\"]" . $const . "['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $c, $m)) {
                $db[$k] = $m[1];
            }
        }
    }

    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'] ?? 'localhost', $db['name'] ?? '');
        return new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        error_log('GarraPHP cron DB: ' . $e->getMessage());
        return null;
    }
}

function cron_next_run(string $lastRun, string $recur): string
{
    $base = strtotime($lastRun);
    return date('Y-m-d H:i:s', match($recur) {
        'hourly'  => strtotime('+1 hour',  $base),
        'daily'   => strtotime('+1 day',   $base),
        'weekly'  => strtotime('+1 week',  $base),
        'monthly' => strtotime('+1 month', $base),
        default   => strtotime('+1 day',   $base),
    });
}

function cron_notify(array $job, bool $success, ?string $response, array $config): void
{
    $skillFile = rtrim($config['settings']['skills_dir'], '/') . '/email.php';
    if (!file_exists($skillFile)) return;
    require_once $skillFile;

    $label = $job['label'] ?: "Job #{$job['id']}";
    try {
        email_execute([
            'action'  => 'send',
            'to'      => $job['notify_email'],
            'subject' => 'GarraPHP: ' . $label . ' — ' . ($success ? '✓ Done' : '✗ Failed'),
            'body'    => "Job: {$label}\nGoal: {$job['goal']}\nStatus: " . ($success ? 'Completed' : 'Failed')
                       . "\nRan: " . date('c') . "\n\nResult:\n" . ($response ?? '(none)'),
        ]);
    } catch (Throwable $e) {
        error_log('GarraPHP cron notify: ' . $e->getMessage());
    }
}
