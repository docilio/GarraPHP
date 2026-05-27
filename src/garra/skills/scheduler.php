<?php
/**
 * Skill: scheduler
 * ================
 * MySQL-backed job queue for scheduling recurring and one-off agent goals.
 * Jobs are picked up by cron.php hitting a public URL every N minutes.
 *
 * The jobs table is created by running scheduler_setup.php once.
 *
 * Actions:
 *   create  — schedule a new job
 *   list    — list scheduled jobs
 *   cancel  — cancel a job by ID
 *   results — return execution log for a job
 *   status  — summary of the queue (pending, running, done counts)
 */
if (!defined('GARRA_EXEC')) exit;

// ---------------------------------------------------------------------------
// Definition
// ---------------------------------------------------------------------------

function scheduler_definition(): array
{
    return [
        'name'        => 'scheduler',
        'description' => 'Schedule agent goals to run automatically at a future time or on a recurring interval. Jobs are stored in MySQL and executed when cron.php fires. Use to automate monitoring, reporting, email alerts, or any periodic task.',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'enum'        => ['create', 'list', 'cancel', 'results', 'status'],
                    'description' => 'Operation: create a job, list jobs, cancel a job, see results, or get queue status.',
                ],
                'goal' => [
                    'type'        => 'string',
                    'description' => 'The agent goal to run on schedule. Required for action=create.',
                ],
                'run_at' => [
                    'type'        => 'string',
                    'description' => 'When to run the job. ISO 8601 datetime or relative like "in 1 hour", "tomorrow 09:00", "every day at 08:00". Required for create.',
                ],
                'recur' => [
                    'type'        => 'string',
                    'enum'        => ['none', 'hourly', 'daily', 'weekly', 'monthly'],
                    'description' => 'Recurrence pattern. Default: none (one-off).',
                ],
                'label' => [
                    'type'        => 'string',
                    'description' => 'Human-friendly name for the job.',
                ],
                'notify_email' => [
                    'type'        => 'string',
                    'description' => 'Email address to notify when the job completes or fails.',
                ],
                'job_id' => [
                    'type'        => 'integer',
                    'description' => 'Job ID. Required for cancel and results.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Max results to return (default 20).',
                ],
            ],
            'required' => ['action'],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Execution
// ---------------------------------------------------------------------------

function scheduler_execute(array $args): array
{
    $action = $args['action'] ?? '';

    try {
        $pdo = scheduler_connect();

        switch ($action) {
            case 'create':  return scheduler_create($pdo, $args);
            case 'list':    return scheduler_list($pdo, $args);
            case 'cancel':  return scheduler_cancel($pdo, $args);
            case 'results': return scheduler_results($pdo, $args);
            case 'status':  return scheduler_status($pdo);
            default:        return ['error' => "Unknown action '{$action}'."];
        }
    } catch (PDOException $e) {
        // Table probably doesn't exist yet
        if (str_contains($e->getMessage(), "doesn't exist")) {
            return ['error' => 'Scheduler table not found. Please run scheduler_setup.php once to create it.'];
        }
        return ['error' => 'Database error: ' . $e->getMessage()];
    } catch (RuntimeException $e) {
        return ['error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

function scheduler_create(PDO $pdo, array $args): array
{
    $goal  = trim($args['goal'] ?? '');
    $runAt = trim($args['run_at'] ?? '');
    $recur = $args['recur'] ?? 'none';
    $label = trim($args['label'] ?? '');
    $email = trim($args['notify_email'] ?? '');

    if (!$goal)  throw new RuntimeException('goal is required for action=create.');
    if (!$runAt) throw new RuntimeException('run_at is required for action=create.');

    $ts = scheduler_parse_time($runAt);
    if (!$ts) throw new RuntimeException("Could not parse run_at value: '{$runAt}'. Try ISO format like '2025-12-01 09:00:00'.");

    $validRecur = ['none', 'hourly', 'daily', 'weekly', 'monthly'];
    if (!in_array($recur, $validRecur, true)) $recur = 'none';

    $stmt = $pdo->prepare("
        INSERT INTO garra_jobs (goal, label, run_at, recur, notify_email, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$goal, $label ?: null, date('Y-m-d H:i:s', $ts), $recur, $email ?: null]);

    return [
        'success'  => true,
        'job_id'   => (int)$pdo->lastInsertId(),
        'goal'     => $goal,
        'run_at'   => date('c', $ts),
        'recur'    => $recur,
    ];
}

function scheduler_list(PDO $pdo, array $args): array
{
    $limit = min((int)($args['limit'] ?? 20), 100);

    $stmt = $pdo->prepare("
        SELECT id, label, goal, run_at, recur, status, notify_email, created_at, last_run_at
        FROM garra_jobs
        WHERE status != 'cancelled'
        ORDER BY run_at ASC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['count' => count($jobs), 'jobs' => $jobs];
}

function scheduler_cancel(PDO $pdo, array $args): array
{
    $id = (int)($args['job_id'] ?? 0);
    if (!$id) throw new RuntimeException('job_id is required for action=cancel.');

    $stmt = $pdo->prepare("UPDATE garra_jobs SET status = 'cancelled' WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        return ['error' => "Job #{$id} not found."];
    }

    return ['success' => true, 'cancelled_job_id' => $id];
}

function scheduler_results(PDO $pdo, array $args): array
{
    $id    = (int)($args['job_id'] ?? 0);
    $limit = min((int)($args['limit'] ?? 10), 50);

    if (!$id) throw new RuntimeException('job_id is required for action=results.');

    // Job info
    $jStmt = $pdo->prepare("SELECT * FROM garra_jobs WHERE id = ?");
    $jStmt->execute([$id]);
    $job = $jStmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) return ['error' => "Job #{$id} not found."];

    // Execution log
    $rStmt = $pdo->prepare("
        SELECT id, started_at, finished_at, success, response, error
        FROM garra_job_runs
        WHERE job_id = ?
        ORDER BY started_at DESC
        LIMIT ?
    ");
    $rStmt->execute([$id, $limit]);
    $runs = $rStmt->fetchAll(PDO::FETCH_ASSOC);

    return ['job' => $job, 'runs' => $runs];
}

function scheduler_status(PDO $pdo): array
{
    $counts = $pdo->query("
        SELECT status, COUNT(*) as count
        FROM garra_jobs
        GROUP BY status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    $due = (int)$pdo->query("
        SELECT COUNT(*) FROM garra_jobs
        WHERE status = 'pending' AND run_at <= NOW()
    ")->fetchColumn();

    $nextJob = $pdo->query("
        SELECT id, label, goal, run_at FROM garra_jobs
        WHERE status = 'pending' AND run_at > NOW()
        ORDER BY run_at ASC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    return [
        'counts'   => $counts,
        'due_now'  => $due,
        'next_job' => $nextJob ?: null,
    ];
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function scheduler_parse_time(string $input): ?int
{
    // Try strtotime first (handles ISO 8601 and many natural formats)
    $ts = strtotime($input);
    if ($ts !== false && $ts > 0) return $ts;

    // "in X hours/minutes/days"
    if (preg_match('/^in\s+(\d+)\s+(minute|hour|day|week)s?$/i', $input, $m)) {
        $mult = ['minute' => 60, 'hour' => 3600, 'day' => 86400, 'week' => 604800];
        return time() + (int)$m[1] * ($mult[strtolower($m[2])] ?? 60);
    }

    return null;
}

function scheduler_connect(): PDO
{
    $file = __DIR__ . '/../config.php';
    if (!file_exists($file)) throw new RuntimeException('config.php not found.');

    $full = require $file;
    $db   = $full['database'] ?? [];

    // WordPress auto-detection (reuse logic from database skill if loaded)
    if (!empty($db['wp_config_path']) && file_exists($db['wp_config_path'])) {
        $content = file_get_contents($db['wp_config_path']);
        $map = ['name' => 'DB_NAME', 'user' => 'DB_USER', 'pass' => 'DB_PASSWORD', 'host' => 'DB_HOST'];
        foreach ($map as $key => $const) {
            if (preg_match("/define\s*\(\s*['\"]" . $const . "['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
                $db[$key] = $m[1];
            }
        }
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s',
        $db['host'] ?? 'localhost',
        $db['name'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );

    return new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
