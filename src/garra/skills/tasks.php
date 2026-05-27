<?php
/**
 * Skill: tasks
 * ============
 * Decompose a goal into subtasks, store them in MySQL, and track completion.
 * The agent can create a task plan, mark items done, and report progress.
 *
 * Works standalone or combined with the scheduler skill for automated pipelines:
 *   "Break down 'weekly site audit' into tasks, then schedule each one."
 *
 * Actions:
 *   breakdown — ask the agent to decompose a goal into subtasks (returns list)
 *   create    — manually create a task list from provided items
 *   list      — list tasks for a project
 *   complete  — mark a task as done
 *   update    — update a task's status or notes
 *   delete    — delete a task or entire project
 *   progress  — summary of completion % for a project
 */
if (!defined('GARRA_EXEC')) exit;

// ---------------------------------------------------------------------------
// Definition
// ---------------------------------------------------------------------------

function tasks_definition(): array
{
    return [
        'name'        => 'tasks',
        'description' => 'Break down a complex goal into subtasks, create project task lists, track completion, and report progress. Use when a goal is too large for one step — decompose it first, then work through each subtask. Stores everything in MySQL for persistence.',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'enum'        => ['breakdown', 'create', 'list', 'complete', 'update', 'delete', 'progress'],
                    'description' => 'breakdown: decompose a goal into subtasks. create: store a task list. list: show tasks. complete: mark done. update: change status/notes. delete: remove task or project. progress: show completion %.',
                ],
                'goal' => [
                    'type'        => 'string',
                    'description' => 'The high-level goal to break down. Required for action=breakdown.',
                ],
                'project' => [
                    'type'        => 'string',
                    'description' => 'Project name to group tasks under. Required for create, list, progress, delete (without task_id).',
                ],
                'tasks' => [
                    'type'        => 'array',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'title'    => ['type' => 'string'],
                            'notes'    => ['type' => 'string'],
                            'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                            'due_at'   => ['type' => 'string', 'description' => 'Optional due datetime'],
                        ],
                        'required' => ['title'],
                    ],
                    'description' => 'List of task objects to create. Required for action=create.',
                ],
                'task_id' => [
                    'type'        => 'integer',
                    'description' => 'Task ID. Required for complete, update, and targeted delete.',
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['pending', 'in_progress', 'done', 'blocked', 'cancelled'],
                    'description' => 'New status for action=update.',
                ],
                'notes' => [
                    'type'        => 'string',
                    'description' => 'Notes or result to attach to a task when updating or completing.',
                ],
            ],
            'required' => ['action'],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Execution
// ---------------------------------------------------------------------------

function tasks_execute(array $args): array
{
    $action = $args['action'] ?? '';

    // breakdown doesn't need DB — it returns a structured list for the agent
    // to then pass to create
    if ($action === 'breakdown') {
        return tasks_breakdown($args);
    }

    try {
        $pdo = tasks_connect();
        tasks_ensure_table($pdo);

        switch ($action) {
            case 'create':   return tasks_create($pdo, $args);
            case 'list':     return tasks_list($pdo, $args);
            case 'complete': return tasks_complete($pdo, $args);
            case 'update':   return tasks_update($pdo, $args);
            case 'delete':   return tasks_delete($pdo, $args);
            case 'progress': return tasks_progress($pdo, $args);
            default:         return ['error' => "Unknown action '{$action}'."];
        }
    } catch (PDOException $e) {
        return ['error' => 'Database error: ' . $e->getMessage()];
    } catch (RuntimeException $e) {
        return ['error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

/**
 * breakdown — returns a structured subtask list.
 * The agent calling this skill will receive the list and can immediately
 * follow up with action=create to persist it, or present it directly.
 */
function tasks_breakdown(array $args): array
{
    $goal    = trim($args['goal'] ?? '');
    $project = trim($args['project'] ?? '');

    if (!$goal) return ['error' => 'goal is required for action=breakdown.'];

    // Return a structured prompt result — the LLM (the agent itself) will
    // have already decomposed the goal to call this skill with a task list.
    // This action serves as the bridge: receive goal, return scaffold.
    return [
        'goal'    => $goal,
        'project' => $project ?: 'Untitled Project',
        'instruction' => 'Use action=create with the tasks array to persist these subtasks. '
                       . 'Break the goal into 3-10 concrete, actionable subtasks with clear titles. '
                       . 'Each task should be completable independently.',
        'suggested_fields' => [
            ['title' => 'Example subtask 1', 'priority' => 'high'],
            ['title' => 'Example subtask 2', 'priority' => 'medium'],
        ],
        'next_action' => 'Call tasks skill with action=create, project="' . ($project ?: $goal) . '", and your tasks array.',
    ];
}

function tasks_create(PDO $pdo, array $args): array
{
    $project = trim($args['project'] ?? '');
    $items   = $args['tasks'] ?? [];

    if (!$project) throw new RuntimeException('project is required for action=create.');
    if (empty($items)) throw new RuntimeException('tasks array is required and must not be empty.');

    $maxTasks = tasks_config()['max_subtasks'] ?? 20;
    $items    = array_slice($items, 0, $maxTasks);

    $stmt = $pdo->prepare("
        INSERT INTO garra_tasks (project, title, notes, priority, status, due_at, created_at)
        VALUES (?, ?, ?, ?, 'pending', ?, NOW())
    ");

    $created = 0;
    foreach ($items as $item) {
        $title    = trim($item['title'] ?? '');
        $notes    = trim($item['notes'] ?? '');
        $priority = in_array($item['priority'] ?? '', ['low', 'medium', 'high']) ? $item['priority'] : 'medium';
        $dueAt    = !empty($item['due_at']) ? date('Y-m-d H:i:s', strtotime($item['due_at'])) : null;

        if (!$title) continue;

        $stmt->execute([$project, $title, $notes ?: null, $priority, $dueAt]);
        $created++;
    }

    return [
        'success' => true,
        'project' => $project,
        'created' => $created,
    ];
}

function tasks_list(PDO $pdo, array $args): array
{
    $project = trim($args['project'] ?? '');
    $status  = $args['status'] ?? null;

    if (!$project) throw new RuntimeException('project is required for action=list.');

    $sql    = "SELECT * FROM garra_tasks WHERE project = ?";
    $params = [$project];

    if ($status) {
        $sql    .= " AND status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY FIELD(priority,'high','medium','low'), created_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['project' => $project, 'count' => count($tasks), 'tasks' => $tasks];
}

function tasks_complete(PDO $pdo, array $args): array
{
    $id    = (int)($args['task_id'] ?? 0);
    $notes = trim($args['notes'] ?? '');

    if (!$id) throw new RuntimeException('task_id is required for action=complete.');

    $stmt = $pdo->prepare("
        UPDATE garra_tasks
        SET status = 'done', completed_at = NOW(), notes = COALESCE(NULLIF(?, ''), notes)
        WHERE id = ?
    ");
    $stmt->execute([$notes ?: null, $id]);

    return ['success' => true, 'completed_task_id' => $id];
}

function tasks_update(PDO $pdo, array $args): array
{
    $id     = (int)($args['task_id'] ?? 0);
    $status = $args['status'] ?? null;
    $notes  = $args['notes'] ?? null;

    if (!$id) throw new RuntimeException('task_id is required for action=update.');

    $validStatuses = ['pending', 'in_progress', 'done', 'blocked', 'cancelled'];

    $sets   = [];
    $params = [];

    if ($status && in_array($status, $validStatuses, true)) {
        $sets[]   = 'status = ?';
        $params[] = $status;
        if ($status === 'done') {
            $sets[] = 'completed_at = NOW()';
        }
    }

    if ($notes !== null) {
        $sets[]   = 'notes = ?';
        $params[] = $notes;
    }

    if (empty($sets)) return ['error' => 'Nothing to update. Provide status and/or notes.'];

    $params[] = $id;
    $stmt = $pdo->prepare("UPDATE garra_tasks SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);

    return ['success' => true, 'updated_task_id' => $id];
}

function tasks_delete(PDO $pdo, array $args): array
{
    $id      = (int)($args['task_id'] ?? 0);
    $project = trim($args['project'] ?? '');

    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM garra_tasks WHERE id = ?");
        $stmt->execute([$id]);
        return ['success' => true, 'deleted_task_id' => $id];
    }

    if ($project) {
        $stmt = $pdo->prepare("DELETE FROM garra_tasks WHERE project = ?");
        $stmt->execute([$project]);
        return ['success' => true, 'deleted_project' => $project, 'deleted_rows' => $stmt->rowCount()];
    }

    return ['error' => 'Provide task_id to delete one task, or project to delete all tasks in a project.'];
}

function tasks_progress(PDO $pdo, array $args): array
{
    $project = trim($args['project'] ?? '');
    if (!$project) throw new RuntimeException('project is required for action=progress.');

    $rows = $pdo->prepare("
        SELECT status, COUNT(*) as count
        FROM garra_tasks
        WHERE project = ?
        GROUP BY status
    ");
    $rows->execute([$project]);
    $counts = $rows->fetchAll(PDO::FETCH_KEY_PAIR);

    $total = array_sum($counts);
    $done  = (int)($counts['done'] ?? 0);
    $pct   = $total > 0 ? round(($done / $total) * 100, 1) : 0;

    return [
        'project'      => $project,
        'total'        => $total,
        'done'         => $done,
        'pct_complete' => $pct,
        'by_status'    => $counts,
    ];
}

// ---------------------------------------------------------------------------
// DB helpers
// ---------------------------------------------------------------------------

function tasks_connect(): PDO
{
    $file = __DIR__ . '/../config.php';
    if (!file_exists($file)) throw new RuntimeException('config.php not found.');

    $full = require $file;
    $db   = $full['database'] ?? [];

    if (!empty($db['wp_config_path']) && file_exists($db['wp_config_path'])) {
        $content = file_get_contents($db['wp_config_path']);
        foreach (['name' => 'DB_NAME', 'user' => 'DB_USER', 'pass' => 'DB_PASSWORD', 'host' => 'DB_HOST'] as $k => $c) {
            if (preg_match("/define\s*\(\s*['\"]" . $c . "['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
                $db[$k] = $m[1];
            }
        }
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'] ?? 'localhost', $db['name'] ?? '');
    return new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function tasks_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `garra_tasks` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `project`      VARCHAR(255) NOT NULL,
            `title`        VARCHAR(500) NOT NULL,
            `notes`        TEXT NULL,
            `priority`     ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
            `status`       ENUM('pending','in_progress','done','blocked','cancelled') NOT NULL DEFAULT 'pending',
            `due_at`       DATETIME NULL,
            `completed_at` DATETIME NULL,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_project` (`project`),
            INDEX `idx_status`  (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

function tasks_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = __DIR__ . '/../config.php';
    $cfg  = file_exists($file) ? (require $file)['tasks'] ?? [] : [];
    return $cfg;
}
