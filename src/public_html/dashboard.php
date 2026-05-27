<?php
/**
 * GarraPHP — Control Room Dashboard
 * ===================================
 * Requires setup.php to have been completed (storage/setup.lock must exist).
 * All data is fetched via lightweight AJAX polling — no page reloads.
 */
define('GARRA_EXEC', true);

// ── Path to the garra/ engine folder (one level above public_html) ────────
define('GARRA_ROOT', dirname(__DIR__) . '/garra');

// ── Redirect to setup if not configured ───────────────────────────────────
if (!file_exists(GARRA_ROOT . '/storage/setup.lock')) {
    header('Location: setup.php');
    exit;
}

$config = require GARRA_ROOT . '/config.php';
require  GARRA_ROOT . '/garra.php';

// ── AJAX data endpoints ───────────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    switch ($_GET['api']) {

        case 'pulse':
            echo json_encode(dash_pulse($config));
            exit;

        case 'jobs':
            echo json_encode(dash_jobs($config));
            exit;

        case 'job_run':
            $id = (int)($_GET['id'] ?? 0);
            echo json_encode(dash_job_run($config, $id));
            exit;

        case 'tasks':
            $project = $_GET['project'] ?? '';
            echo json_encode(dash_tasks($config, $project));
            exit;

        case 'task_projects':
            echo json_encode(dash_task_projects($config));
            exit;

        case 'heartbeat':
            echo json_encode(dash_heartbeat($config));
            exit;

        case 'log':
            echo json_encode(dash_log($config));
            exit;

        case 'run_goal':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $body      = json_decode(file_get_contents('php://input'), true);
                $goal      = $body['goal'] ?? '';
                $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['session_id'] ?? '');
                $storageDir = rtrim($config['settings']['storage_dir'], '/');

                // Load history
                $history     = [];
                $sessionFile = $sessionId ? $storageDir . '/session_' . $sessionId . '.json' : null;
                if ($sessionFile && file_exists($sessionFile)) {
                    $saved   = json_decode(file_get_contents($sessionFile), true);
                    $history = is_array($saved) ? $saved : [];
                }

                $agent  = new Garra($config);
                $result = $agent->run($goal, $history);

                // Save history
                if ($sessionFile && is_dir($storageDir) && is_writable($storageDir)) {
                    file_put_contents($sessionFile, json_encode($result['history'], JSON_PRETTY_PRINT), LOCK_EX);
                }

                echo json_encode($result);
            }
            exit;

        case 'run_job_now':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $body  = json_decode(file_get_contents('php://input'), true);
                $jobId = (int)($body['job_id'] ?? 0);
                echo json_encode(dash_run_job_now($config, $jobId));
            }
            exit;

        case 'cancel_job':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $body  = json_decode(file_get_contents('php://input'), true);
                $jobId = (int)($body['job_id'] ?? 0);
                echo json_encode(dash_cancel_job($config, $jobId));
            }
            exit;

        case 'task_action':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $body = json_decode(file_get_contents('php://input'), true);
                echo json_encode(dash_task_action($config, $body));
            }
            exit;

        case 'ping_url':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $body = json_decode(file_get_contents('php://input'), true);
                echo json_encode(dash_ping_url($body['url'] ?? ''));
            }
            exit;
    }
    echo json_encode(['error' => 'Unknown api endpoint']);
    exit;
}

// ── Data functions ─────────────────────────────────────────────────────────

function dash_db(array $config): ?PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $db = $config['database'] ?? [];
    if (!empty($db['wp_config_path']) && file_exists($db['wp_config_path'])) {
        $c = file_get_contents($db['wp_config_path']);
        foreach (['name'=>'DB_NAME','user'=>'DB_USER','pass'=>'DB_PASSWORD','host'=>'DB_HOST'] as $k=>$const) {
            if (preg_match("/define\s*\(\s*['\"]".$const."['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $c, $m)) $db[$k] = $m[1];
        }
    }
    try {
        $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return $pdo;
    } catch (PDOException $e) { return null; }
}

function dash_pulse(array $config): array
{
    $pdo       = dash_db($config);
    $storageDir = rtrim($config['settings']['storage_dir'], '/');

    // LLM ping
    $llmOk = false;
    $llmMs = 0;
    try {
        $drivers = GARRA_ROOT . '/drivers/' . ucfirst($config['provider']) . 'Driver.php';
        if (file_exists($drivers)) {
            require_once GARRA_ROOT . '/drivers/LLMDriver.php';
            require_once $drivers;
            $t0 = microtime(true);
            $class = ucfirst($config['provider']) . 'Driver';
            $drv = new $class($config);
            $resp = $drv->chat([['role'=>'user','content'=>'ping']], []);
            $llmMs = (int)round((microtime(true) - $t0) * 1000);
            $llmOk = !empty($resp['content']);
        }
    } catch (Throwable $e) { $llmOk = false; }

    // Cron health
    $lockInfo   = file_exists($l = GARRA_ROOT . '/storage/setup.lock') ? file_get_contents($l) : '';
    $cronLock   = file_exists(GARRA_ROOT . '/storage/cron.lock');
    $cronLog    = [];
    $jobStats   = ['pending'=>0,'done'=>0,'failed'=>0,'running'=>0];

    if ($pdo) {
        try {
            $rows = $pdo->query("SELECT status, COUNT(*) c FROM garra_jobs GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $k=>$v) $jobStats[$k] = (int)$v;

            $cronLog = $pdo->query("
                SELECT r.id, j.label, r.started_at, r.finished_at, r.success, r.error
                FROM garra_job_runs r
                LEFT JOIN garra_jobs j ON j.id = r.job_id
                ORDER BY r.started_at DESC LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    // Heartbeat summary
    $hbTargets  = $config['heartbeat']['targets'] ?? [];
    $hbDir      = $storageDir . '/heartbeat';
    $hbSummary  = [];
    foreach ($hbTargets as $t) {
        $url  = is_array($t) ? $t['url'] : $t;
        $name = is_array($t) ? ($t['name'] ?? $url) : $url;
        $key  = preg_replace('/[^a-z0-9_-]/', '_', strtolower(parse_url($url, PHP_URL_HOST).parse_url($url, PHP_URL_PATH)));
        $file = $hbDir . '/' . $key . '.jsonl';
        $last = null;
        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!empty($lines)) $last = json_decode(end($lines), true);
        }
        $hbSummary[] = ['name'=>$name,'url'=>$url,'up'=>$last['up']??null,'ms'=>$last['response_ms']??null,'ts'=>$last['ts']??null];
    }

    // Skills
    $agent      = new Garra($config);
    $skillNames = $agent->getSkillNames();

    return [
        'llm'       => ['ok'=>$llmOk, 'ms'=>$llmMs, 'provider'=>$config['provider'], 'model'=>$config['model']],
        'db'        => ['ok'=>(bool)$pdo],
        'jobs'      => $jobStats,
        'heartbeat' => $hbSummary,
        'cron_log'  => $cronLog,
        'skills'    => $skillNames,
        'ts'        => date('c'),
    ];
}

function dash_jobs(array $config): array
{
    $pdo = dash_db($config);
    if (!$pdo) return ['error'=>'No database connection'];
    try {
        $jobs = $pdo->query("SELECT * FROM garra_jobs ORDER BY run_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        return ['jobs' => $jobs];
    } catch (Throwable $e) { return ['error'=>$e->getMessage()]; }
}

function dash_job_run(array $config, int $jobId): array
{
    $pdo = dash_db($config);
    if (!$pdo) return ['error'=>'No database'];
    try {
        $runs = $pdo->prepare("SELECT * FROM garra_job_runs WHERE job_id=? ORDER BY started_at DESC LIMIT 10");
        $runs->execute([$jobId]);
        return ['runs' => $runs->fetchAll(PDO::FETCH_ASSOC)];
    } catch (Throwable $e) { return ['error'=>$e->getMessage()]; }
}

function dash_tasks(array $config, string $project): array
{
    $pdo = dash_db($config);
    if (!$pdo) return ['error'=>'No database'];
    if (!$project) return ['tasks'=>[]];
    try {
        $stmt = $pdo->prepare("SELECT * FROM garra_tasks WHERE project=? ORDER BY FIELD(priority,'high','medium','low'), created_at ASC");
        $stmt->execute([$project]);
        return ['tasks' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    } catch (Throwable $e) { return ['error'=>$e->getMessage()]; }
}

function dash_task_projects(array $config): array
{
    $pdo = dash_db($config);
    if (!$pdo) return ['projects'=>[]];
    try {
        $rows = $pdo->query("SELECT project, COUNT(*) total, SUM(status='done') done FROM garra_tasks GROUP BY project ORDER BY project")->fetchAll(PDO::FETCH_ASSOC);
        return ['projects' => $rows];
    } catch (Throwable $e) { return ['projects'=>[]]; }
}

function dash_heartbeat(array $config): array
{
    $targets = $config['heartbeat']['targets'] ?? [];
    $dir     = rtrim($config['settings']['storage_dir'],'/').'/heartbeat';
    $out     = [];
    foreach ($targets as $t) {
        $url  = is_array($t) ? $t['url'] : $t;
        $name = is_array($t) ? ($t['name']??$url) : $url;
        $key  = preg_replace('/[^a-z0-9_-]/','_',strtolower(parse_url($url,PHP_URL_HOST).parse_url($url,PHP_URL_PATH)));
        $file = $dir.'/'.$key.'.jsonl';
        $history = [];
        if (file_exists($file)) {
            $lines = array_slice(file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES), -20);
            foreach (array_reverse($lines) as $l) { $r=json_decode($l,true); if($r) $history[]=$r; }
        }
        $last = $history[0] ?? null;
        $out[] = ['name'=>$name,'url'=>$url,'up'=>$last['up']??null,'ms'=>$last['response_ms']??null,'ts'=>$last['ts']??null,'history'=>array_reverse($history)];
    }
    return ['targets' => $out];
}

function dash_log(array $config): array
{
    $logFile = rtrim($config['settings']['storage_dir'], '/') . '/activity.log';
    if (!file_exists($logFile)) return ['lines' => []];

    $raw   = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -80);
    $lines = [];

    foreach (array_reverse($raw) as $line) {
        // Parse: 2025-01-01 12:00:00 [TYPE] message
        $type = 'info';
        if (preg_match('/\[(OK|ERR|WARN|TOOL|INFO)\]/', $line, $m)) {
            $type = strtolower($m[1]);
        }
        $lines[] = ['text' => $line, 'type' => $type];
    }

    return ['lines' => $lines];
}

function dash_run_job_now(array $config, int $jobId): array
{
    $pdo = dash_db($config);
    if (!$pdo) return ['ok'=>false,'message'=>'No database'];
    try {
        $job = $pdo->prepare("SELECT * FROM garra_jobs WHERE id=?");
        $job->execute([$jobId]);
        $job = $job->fetch(PDO::FETCH_ASSOC);
        if (!$job) return ['ok'=>false,'message'=>'Job not found'];

        $agent = new Garra($config);
        $result = $agent->run($job['goal'], []);

        $pdo->prepare("INSERT INTO garra_job_runs (job_id,started_at,finished_at,success,response) VALUES (?,NOW(),NOW(),?,?)")
            ->execute([$jobId, $result['success']?1:0, $result['response']]);
        $pdo->prepare("UPDATE garra_jobs SET last_run_at=NOW() WHERE id=?")->execute([$jobId]);

        return ['ok'=>true,'response'=>$result['response'],'success'=>$result['success']];
    } catch (Throwable $e) { return ['ok'=>false,'message'=>$e->getMessage()]; }
}

function dash_cancel_job(array $config, int $jobId): array
{
    $pdo = dash_db($config);
    if (!$pdo) return ['ok'=>false];
    try {
        $pdo->prepare("UPDATE garra_jobs SET status='cancelled' WHERE id=?")->execute([$jobId]);
        return ['ok'=>true];
    } catch (Throwable $e) { return ['ok'=>false,'message'=>$e->getMessage()]; }
}

function dash_task_action(array $config, array $body): array
{
    $pdo = dash_db($config);
    if (!$pdo) return ['ok'=>false,'message'=>'No database'];
    try {
        $action = $body['action'] ?? '';
        if ($action === 'complete') {
            $pdo->prepare("UPDATE garra_tasks SET status='done', completed_at=NOW() WHERE id=?")->execute([$body['id']]);
        } elseif ($action === 'status') {
            $pdo->prepare("UPDATE garra_tasks SET status=? WHERE id=?")->execute([$body['status'], $body['id']]);
        }
        return ['ok'=>true];
    } catch (Throwable $e) { return ['ok'=>false,'message'=>$e->getMessage()]; }
}

function dash_ping_url(string $url): array
{
    if (!$url) return ['ok'=>false,'message'=>'No URL'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_USERAGENT=>'GarraPHP-Heartbeat/1.0']);
    $t0   = microtime(true);
    $body = curl_exec($ch);
    $ms   = (int)round((microtime(true)-$t0)*1000);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['ok'=>!$err&&$http>=200&&$http<400,'status'=>$http,'ms'=>$ms,'error'=>$err?:null];
}

// ── Read config values for display ────────────────────────────────────────
$provider   = $config['provider'] ?? 'openai';
$model      = $config['model'] ?? '';
$skillCount = count((new Garra($config))->getSkillNames());
$cronSecret = $config['scheduler']['cron_secret'] ?? '';
$baseUrl    = (isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'];
$cronUrl    = $baseUrl.'/cron.php?secret='.$cronSecret;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GarraPHP — Control Room</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #080808;
  --surface:   #101010;
  --surface2:  #141414;
  --surface3:  #191919;
  --border:    #1e1e1e;
  --border-hi: #2a2a2a;
  --text:      #c0c0c0;
  --muted:     #404040;
  --muted2:    #5e5e5e;
  --accent:    #f5a623;
  --accent-dim:#4a3008;
  --green:     #3dba6f;
  --green-dim: #0a2818;
  --red:       #e05252;
  --red-dim:   #2a0a0a;
  --blue:      #4d9de0;
  --blue-dim:  #0a1e30;
  --yellow:    #e8c840;
  --mono:      'IBM Plex Mono', monospace;
}

html,body { height:100%; overflow:hidden; background:var(--bg); color:var(--text); font-family:var(--mono); font-size:12px; line-height:1.6; }

/* ── Scanlines ── */
body::after {
  content:''; position:fixed; inset:0; pointer-events:none; z-index:9999;
  background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(0,0,0,.04) 3px,rgba(0,0,0,.04) 4px);
}

/* ── Shell ── */
#shell { display:grid; grid-template-columns:200px 1fr 240px; grid-template-rows:42px 1fr; height:100vh; }

/* ── Topbar ── */
#topbar {
  grid-column:1/-1;
  display:flex; align-items:center; gap:10px;
  padding:0 14px;
  background:var(--surface); border-bottom:1px solid var(--border);
}

.logo { font-size:14px; font-weight:600; letter-spacing:.12em; color:var(--accent); text-transform:uppercase; }
.logo span { color:var(--muted2); font-weight:300; }

.topbar-sep { width:1px; height:20px; background:var(--border); margin:0 4px; }

.pill {
  font-size:9px; padding:2px 8px; border:1px solid var(--border-hi);
  border-radius:12px; color:var(--muted2); letter-spacing:.08em; text-transform:uppercase;
}
.pill.live { border-color:var(--green); color:var(--green); }
.pill.warn { border-color:var(--yellow); color:var(--yellow); }
.pill.err  { border-color:var(--red);   color:var(--red); }

.pulse-dot {
  width:6px; height:6px; border-radius:50%; background:var(--green);
  animation:pulse 2.5s ease infinite; margin-right:3px; display:inline-block;
}
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.2} }

.spacer { flex:1; }

.topbar-time { font-size:10px; color:var(--muted2); }
.topbar-btn {
  padding:4px 10px; background:transparent; border:1px solid var(--border-hi);
  color:var(--muted2); font-family:var(--mono); font-size:9px; letter-spacing:.08em;
  text-transform:uppercase; cursor:pointer; border-radius:2px;
  transition:border-color .15s,color .15s;
}
.topbar-btn:hover { border-color:var(--accent-dim); color:var(--accent); }

/* ── Sidebar ── */
#sidebar {
  background:var(--surface); border-right:1px solid var(--border);
  display:flex; flex-direction:column; overflow:hidden;
}

.nav-section { padding:16px 0 8px; }
.nav-label { padding:0 14px 6px; font-size:9px; letter-spacing:.15em; text-transform:uppercase; color:var(--muted); }

.nav-item {
  display:flex; align-items:center; gap:8px;
  padding:7px 14px; cursor:pointer; color:var(--muted2);
  font-size:11px; letter-spacing:.04em;
  border-left:2px solid transparent;
  transition:color .15s, border-color .15s, background .15s;
  user-select:none;
}
.nav-item:hover { color:var(--text); background:var(--surface2); }
.nav-item.active { color:var(--accent); border-left-color:var(--accent); background:rgba(245,166,35,.05); }
.nav-item .nav-icon { font-size:12px; width:16px; text-align:center; }
.nav-item .nav-badge { margin-left:auto; font-size:9px; padding:1px 5px; background:var(--accent-dim); color:var(--accent); border-radius:8px; }

.sidebar-footer {
  margin-top:auto; padding:12px 14px; border-top:1px solid var(--border);
  font-size:9px; color:var(--muted); line-height:2;
}

/* ── Main panel ── */
#main { overflow:hidden; display:flex; flex-direction:column; }

.panel { display:none; flex-direction:column; height:100%; overflow:hidden; }
.panel.active { display:flex; }

.panel-head {
  padding:14px 20px 12px; border-bottom:1px solid var(--border);
  display:flex; align-items:center; gap:10px; flex-shrink:0;
}
.panel-title { font-size:13px; font-weight:500; letter-spacing:.08em; text-transform:uppercase; color:var(--text); }
.panel-sub   { font-size:10px; color:var(--muted2); }
.panel-actions { margin-left:auto; display:flex; gap:8px; }

.panel-body { flex:1; overflow-y:auto; padding:16px 20px; }
.panel-body::-webkit-scrollbar { width:3px; }
.panel-body::-webkit-scrollbar-thumb { background:var(--border-hi); }

/* ── Live log (right column) ── */
#logpanel {
  border-left:1px solid var(--border); background:var(--surface);
  display:flex; flex-direction:column; overflow:hidden;
}

.log-head {
  padding:10px 12px; border-bottom:1px solid var(--border);
  font-size:9px; letter-spacing:.12em; text-transform:uppercase;
  color:var(--muted2); display:flex; align-items:center; gap:6px; flex-shrink:0;
}

#log-feed {
  flex:1; overflow-y:auto; padding:8px;
  font-size:10px; line-height:1.8;
}
#log-feed::-webkit-scrollbar { width:2px; }
#log-feed::-webkit-scrollbar-thumb { background:var(--muted); }

.log-line { color:var(--muted2); padding:1px 4px; border-radius:1px; white-space:pre-wrap; word-break:break-all; }
.log-line.ok  { color:var(--green); }
.log-line.err { color:var(--red); }
.log-line.info{ color:var(--blue); }
.log-line.warn{ color:var(--yellow); }

/* ── Cards / widgets ── */
.widget-grid { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:10px; margin-bottom:14px; }
.widget-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px; }

.widget {
  background:var(--surface); border:1px solid var(--border); border-radius:2px; padding:14px;
}

.w-label { font-size:9px; letter-spacing:.12em; text-transform:uppercase; color:var(--muted2); margin-bottom:8px; }
.w-val   { font-size:22px; font-weight:500; color:var(--text); line-height:1; }
.w-sub   { font-size:10px; color:var(--muted2); margin-top:4px; }
.w-dot   { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:6px; }

/* ── Status indicator ── */
.status-ok   { color:var(--green); }
.status-err  { color:var(--red); }
.status-warn { color:var(--yellow); }
.status-null { color:var(--muted2); }

/* ── Tables ── */
.data-table { width:100%; border-collapse:collapse; }
.data-table th {
  text-align:left; padding:7px 10px; font-size:9px; letter-spacing:.1em;
  text-transform:uppercase; color:var(--muted2); border-bottom:1px solid var(--border);
  font-weight:400;
}
.data-table td { padding:8px 10px; border-bottom:1px solid var(--border); font-size:11px; color:var(--text); vertical-align:middle; }
.data-table tr:hover td { background:var(--surface2); }
.data-table tr:last-child td { border-bottom:none; }

/* ── Status badges ── */
.badge {
  display:inline-block; padding:2px 7px; border-radius:10px;
  font-size:9px; letter-spacing:.06em; text-transform:uppercase; border:1px solid;
}
.badge-done      { background:var(--green-dim); border-color:var(--green); color:var(--green); }
.badge-pending   { background:var(--accent-dim); border-color:#6b470f; color:var(--accent); }
.badge-failed    { background:var(--red-dim); border-color:var(--red); color:var(--red); }
.badge-running   { background:var(--blue-dim); border-color:var(--blue); color:var(--blue); }
.badge-cancelled { background:var(--surface2); border-color:var(--muted); color:var(--muted2); }
.badge-blocked   { background:var(--red-dim); border-color:var(--red); color:var(--red); }
.badge-in_progress { background:var(--blue-dim); border-color:var(--blue); color:var(--blue); }
.badge-high   { background:rgba(224,82,82,.1); border-color:var(--red); color:var(--red); }
.badge-medium { background:var(--accent-dim); border-color:#6b470f; color:var(--accent); }
.badge-low    { background:var(--green-dim); border-color:var(--green); color:var(--green); }

/* ── Buttons ── */
.btn { padding:6px 14px; font-family:var(--mono); font-size:10px; font-weight:500; letter-spacing:.08em; text-transform:uppercase; border-radius:2px; cursor:pointer; border:1px solid; transition:opacity .15s; }
.btn-primary { background:var(--accent); border-color:var(--accent); color:#000; }
.btn-primary:hover { opacity:.85; }
.btn-ghost { background:transparent; border-color:var(--border-hi); color:var(--muted2); }
.btn-ghost:hover { border-color:var(--text); color:var(--text); }
.btn-danger { background:transparent; border-color:var(--red-dim); color:var(--red); }
.btn-danger:hover { background:var(--red-dim); }
.btn-sm { padding:3px 8px; font-size:9px; }
.btn:disabled { opacity:.3; cursor:not-allowed; }

/* ── Forms ── */
.form-row { display:grid; gap:10px; margin-bottom:12px; }
.form-row-2 { grid-template-columns:1fr 1fr; }
.form-row-3 { grid-template-columns:1fr 1fr 1fr; }

label.field-label {
  display:block; font-size:9px; letter-spacing:.1em; text-transform:uppercase;
  color:var(--muted2); margin-bottom:4px;
}

input[type=text],input[type=url],input[type=email],input[type=number],input[type=password],select,textarea {
  width:100%; background:var(--bg); border:1px solid var(--border); color:var(--text);
  font-family:var(--mono); font-size:11px; padding:7px 9px; border-radius:2px; outline:none;
  transition:border-color .15s;
}
input:focus,select:focus,textarea:focus { border-color:var(--accent-dim); }
input::placeholder,textarea::placeholder { color:var(--muted); }
select option { background:var(--surface); }
textarea { resize:vertical; min-height:60px; line-height:1.5; }

/* ── Divider ── */
.divider { border:none; border-top:1px solid var(--border); margin:16px 0; }

/* ── Section header ── */
.section-head { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.section-label { font-size:9px; letter-spacing:.15em; text-transform:uppercase; color:var(--muted2); }
.section-line  { flex:1; border-top:1px solid var(--border); }

/* ── Heartbeat bar ── */
.hb-bar { display:flex; gap:2px; align-items:flex-end; height:28px; }
.hb-tick { width:6px; border-radius:1px; min-height:2px; }

/* ── Task kanban ── */
.kanban { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }
.kanban-col { background:var(--surface); border:1px solid var(--border); border-radius:2px; padding:10px; min-height:120px; }
.kanban-col-title { font-size:9px; letter-spacing:.12em; text-transform:uppercase; color:var(--muted2); margin-bottom:8px; }
.task-card { background:var(--surface2); border:1px solid var(--border); border-radius:2px; padding:8px 10px; margin-bottom:6px; cursor:pointer; transition:border-color .15s; }
.task-card:hover { border-color:var(--border-hi); }
.task-card-title { font-size:11px; color:var(--text); margin-bottom:4px; line-height:1.4; }
.task-card-meta  { font-size:9px; color:var(--muted2); }

/* ── CLI ── */
#cli-log {
  background:var(--bg); border:1px solid var(--border); border-radius:2px;
  height:320px; overflow-y:auto; padding:12px; font-size:11px; line-height:1.7; margin-bottom:12px;
}
#cli-log::-webkit-scrollbar { width:3px; }
#cli-log::-webkit-scrollbar-thumb { background:var(--border-hi); }

.cli-entry { margin-bottom:8px; }
.cli-goal  { color:var(--accent); }
.cli-result{ color:var(--text); white-space:pre-wrap; }
.cli-tool  { color:var(--blue); font-size:10px; }
.cli-err   { color:var(--red); }
.cli-meta  { color:var(--muted2); font-size:10px; }

.cli-input-row { display:flex; gap:8px; align-items:flex-start; }
#cli-input { flex:1; }

/* ── Empty state ── */
.empty-state { text-align:center; padding:50px 20px; color:var(--muted2); }
.empty-icon  { font-size:28px; margin-bottom:10px; opacity:.3; }
.empty-label { font-size:11px; letter-spacing:.08em; }

/* ── Spinner ── */
.spin { display:inline-block; width:10px; height:10px; border:1px solid var(--border-hi); border-top-color:var(--accent); border-radius:50%; animation:_spin .5s linear infinite; vertical-align:middle; margin-right:4px; }
@keyframes _spin { to { transform:rotate(360deg); } }

/* ── Modal ── */
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:1000; align-items:center; justify-content:center; }
.modal-bg.open { display:flex; }
.modal { background:var(--surface); border:1px solid var(--border-hi); border-radius:2px; padding:20px; min-width:480px; max-width:640px; width:90%; }
.modal-title { font-size:12px; font-weight:500; letter-spacing:.1em; text-transform:uppercase; color:var(--text); margin-bottom:16px; }
.modal-footer { display:flex; gap:8px; justify-content:flex-end; margin-top:16px; }
</style>
</head>
<body>

<div id="shell">

<!-- ── Topbar ── -->
<header id="topbar">
  <div class="logo">Garra<span>PHP</span></div>
  <div class="topbar-sep"></div>
  <div class="pill live" id="llm-pill"><span class="pulse-dot"></span><?= htmlspecialchars($provider) ?></div>
  <div class="pill" id="model-pill"><?= htmlspecialchars($model) ?></div>
  <div class="pill" id="db-pill">DB –</div>
  <div class="pill" id="skills-pill"><?= $skillCount ?> skills</div>
  <div class="topbar-sep"></div>
  <div class="spacer"></div>
  <div class="topbar-time" id="clock"></div>
  <div class="topbar-sep"></div>
  <button class="topbar-btn" onclick="navTo('cli')">▶ Run Goal</button>
  <button class="topbar-btn" onclick="openJobModal()">+ New Job</button>
  <a href="setup.php?unlock=yes" class="topbar-btn" style="text-decoration:none">⚙ Setup</a>
</header>

<!-- ── Sidebar ── -->
<nav id="sidebar">
  <div class="nav-section">
    <div class="nav-label">Overview</div>
    <div class="nav-item active" data-panel="pulse" onclick="navTo('pulse')">
      <span class="nav-icon">◈</span> Pulse
    </div>
  </div>
  <div class="nav-section">
    <div class="nav-label">Automation</div>
    <div class="nav-item" data-panel="jobs" onclick="navTo('jobs')">
      <span class="nav-icon">⏱</span> Scheduler
      <span class="nav-badge" id="jobs-badge">–</span>
    </div>
    <div class="nav-item" data-panel="tasks" onclick="navTo('tasks')">
      <span class="nav-icon">◫</span> Tasks
    </div>
    <div class="nav-item" data-panel="heartbeat" onclick="navTo('heartbeat')">
      <span class="nav-icon">♥</span> Heartbeat
    </div>
  </div>
  <div class="nav-section">
    <div class="nav-label">Tools</div>
    <div class="nav-item" data-panel="cli" onclick="navTo('cli')">
      <span class="nav-icon">$</span> CLI
    </div>
  </div>
  <div class="sidebar-footer">
    <div style="color:var(--muted2)">Cron URL:</div>
    <div style="word-break:break-all;color:var(--muted);font-size:9px"><?= htmlspecialchars($cronUrl) ?></div>
  </div>
</nav>

<!-- ── Main area ── -->
<main id="main">

  <!-- Pulse panel -->
  <div class="panel active" id="panel-pulse">
    <div class="panel-head">
      <div>
        <div class="panel-title">Pulse</div>
        <div class="panel-sub">System overview &amp; health</div>
      </div>
      <div class="panel-actions">
        <button class="btn btn-ghost btn-sm" onclick="loadPulse()">↻ Refresh</button>
      </div>
    </div>
    <div class="panel-body" id="pulse-body">
      <div class="empty-state"><div class="spin"></div> Loading…</div>
    </div>
  </div>

  <!-- Scheduler panel -->
  <div class="panel" id="panel-jobs">
    <div class="panel-head">
      <div>
        <div class="panel-title">Scheduler</div>
        <div class="panel-sub">Scheduled &amp; recurring jobs</div>
      </div>
      <div class="panel-actions">
        <button class="btn btn-ghost btn-sm" onclick="loadJobs()">↻ Refresh</button>
        <button class="btn btn-primary btn-sm" onclick="openJobModal()">+ New Job</button>
      </div>
    </div>
    <div class="panel-body" id="jobs-body">
      <div class="empty-state"><div class="spin"></div> Loading…</div>
    </div>
  </div>

  <!-- Tasks panel -->
  <div class="panel" id="panel-tasks">
    <div class="panel-head">
      <div>
        <div class="panel-title">Tasks</div>
        <div class="panel-sub">Project task boards</div>
      </div>
      <div class="panel-actions">
        <select id="project-select" onchange="loadTasks(this.value)" style="max-width:180px;font-size:10px;padding:4px 8px;">
          <option value="">— select project —</option>
        </select>
        <button class="btn btn-ghost btn-sm" onclick="loadTaskProjects()">↻</button>
      </div>
    </div>
    <div class="panel-body" id="tasks-body">
      <div class="empty-state"><div class="empty-icon">◫</div><div class="empty-label">Select a project above</div></div>
    </div>
  </div>

  <!-- Heartbeat panel -->
  <div class="panel" id="panel-heartbeat">
    <div class="panel-head">
      <div>
        <div class="panel-title">Heartbeat</div>
        <div class="panel-sub">URL monitoring &amp; uptime</div>
      </div>
      <div class="panel-actions">
        <button class="btn btn-ghost btn-sm" onclick="loadHeartbeat()">↻ Refresh</button>
        <button class="btn btn-primary btn-sm" onclick="openPingModal()">+ Ping URL</button>
      </div>
    </div>
    <div class="panel-body" id="hb-body">
      <div class="empty-state"><div class="spin"></div> Loading…</div>
    </div>
  </div>

  <!-- CLI panel -->
  <div class="panel" id="panel-cli">
    <div class="panel-head">
      <div>
        <div class="panel-title">CLI</div>
        <div class="panel-sub">Run a goal manually &amp; inspect the agent loop</div>
      </div>
      <div class="panel-actions">
        <span id="cli-session-label" style="font-size:10px;color:var(--muted2)"></span>
        <button class="btn btn-ghost btn-sm" onclick="resetCliSession()">↺ New Session</button>
      </div>
    </div>
    <div class="panel-body">
      <div id="cli-log"></div>
      <div class="cli-input-row">
        <textarea id="cli-input" rows="2" placeholder="Enter a goal and press Ctrl+Enter…"></textarea>
        <button class="btn btn-primary" id="cli-run-btn" onclick="runCliGoal()">▶ Run</button>
      </div>
      <div style="margin-top:6px;font-size:10px;color:var(--muted2)">Ctrl+Enter to run · Shows full agent loop trace</div>
    </div>
  </div>

</main>

<!-- ── Live Log ── -->
<aside id="logpanel">
  <div class="log-head">
    <span class="pulse-dot" style="background:var(--blue)"></span>
    Activity Log
    <span style="margin-left:auto;cursor:pointer;color:var(--muted)" onclick="clearLog()">✕</span>
  </div>
  <div id="log-feed"></div>
</aside>

</div><!-- /shell -->

<!-- ── New Job Modal ── -->
<div class="modal-bg" id="job-modal">
  <div class="modal">
    <div class="modal-title">Schedule New Job</div>
    <div class="form-row"><div>
      <label class="field-label">Label (optional)</label>
      <input type="text" id="jm-label" placeholder="Daily site check">
    </div></div>
    <div class="form-row"><div>
      <label class="field-label">Goal</label>
      <textarea id="jm-goal" rows="3" placeholder="Check if https://yoursite.com is up and email me a report"></textarea>
    </div></div>
    <div class="form-row form-row-2">
      <div>
        <label class="field-label">Run At</label>
        <input type="text" id="jm-run-at" placeholder="tomorrow 09:00 or 2025-12-01 08:00">
      </div>
      <div>
        <label class="field-label">Recurrence</label>
        <select id="jm-recur">
          <option value="none">One-off</option>
          <option value="hourly">Hourly</option>
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
          <option value="monthly">Monthly</option>
        </select>
      </div>
    </div>
    <div class="form-row"><div>
      <label class="field-label">Notify Email (optional)</label>
      <input type="email" id="jm-email" placeholder="you@example.com">
    </div></div>
    <div id="jm-result" style="font-size:11px;margin-top:8px;"></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeJobModal()">Cancel</button>
      <button class="btn btn-primary" onclick="saveJob()">Schedule Job</button>
    </div>
  </div>
</div>

<!-- ── Ping URL Modal ── -->
<div class="modal-bg" id="ping-modal">
  <div class="modal">
    <div class="modal-title">Ping a URL</div>
    <div class="form-row"><div>
      <label class="field-label">URL</label>
      <input type="url" id="pm-url" placeholder="https://yoursite.com">
    </div></div>
    <div id="pm-result" style="font-size:11px;margin-top:8px;"></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closePingModal()">Close</button>
      <button class="btn btn-primary" onclick="doPing()">Ping</button>
    </div>
  </div>
</div>

<script>
// ── Clock ──────────────────────────────────────────────────────────────────
function tick() {
  document.getElementById('clock').textContent = new Date().toLocaleTimeString('en-GB',{hour12:false});
}
tick(); setInterval(tick,1000);

// ── Navigation ─────────────────────────────────────────────────────────────
var currentPanel = 'pulse';
function navTo(name) {
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  document.getElementById('panel-'+name).classList.add('active');
  var navEl = document.querySelector('[data-panel="'+name+'"]');
  if (navEl) navEl.classList.add('active');
  currentPanel = name;
  if (name==='pulse')     loadPulse();
  if (name==='jobs')      loadJobs();
  if (name==='tasks')     loadTaskProjects();
  if (name==='heartbeat') loadHeartbeat();
}

// ── Helpers ────────────────────────────────────────────────────────────────
async function api(endpoint, method='GET', body=null) {
  var opts = {method, headers:{'Content-Type':'application/json'}};
  if (body) opts.body = JSON.stringify(body);
  var r = await fetch('dashboard.php?api='+endpoint, opts);
  return r.json();
}

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function badge(status) {
  return '<span class="badge badge-'+esc(status)+'">'+esc(status)+'</span>';
}

function ts(s) {
  if (!s) return '—';
  try { return new Date(s).toLocaleString('en-GB',{hour12:false,dateStyle:'short',timeStyle:'short'}); }
  catch(e) { return s; }
}

function addLog(text, type='') {
  var feed = document.getElementById('log-feed');
  var line = document.createElement('div');
  line.className = 'log-line'+(type?' '+type:'');
  line.textContent = '['+new Date().toLocaleTimeString('en-GB',{hour12:false})+'] '+text;
  feed.prepend(line);
  while(feed.children.length>80) feed.removeChild(feed.lastChild);
}

async function pollLog() {
  try {
    var d = await api('log');
    var feed = document.getElementById('log-feed');
    if (!d.lines || !d.lines.length) return;
    // Only repopulate if content changed (compare first line)
    var first = feed.firstChild;
    if (first && first.dataset.key === d.lines[0].text) return;
    feed.innerHTML = '';
    d.lines.forEach(function(l) {
      var div = document.createElement('div');
      var type = l.type || 'info';
      // map log types to CSS classes
      var cssType = {ok:'ok', err:'err', warn:'warn', tool:'info', info:'info'}[type] || '';
      div.className = 'log-line' + (cssType ? ' '+cssType : '');
      div.textContent = l.text;
      div.dataset.key = l.text;
      feed.appendChild(div);
    });
  } catch(e) {}
}

function clearLog() { document.getElementById('log-feed').innerHTML=''; }

// ── Pulse ──────────────────────────────────────────────────────────────────
async function loadPulse() {
  var d = await api('pulse');

  document.getElementById('llm-pill').className = 'pill '+(d.llm.ok?'live':'err');
  document.getElementById('db-pill').textContent = d.db.ok ? 'DB ✓' : 'DB ✗';
  document.getElementById('db-pill').className   = 'pill '+(d.db.ok?'live':'err');
  document.getElementById('jobs-badge').textContent = d.jobs.pending||0;

  var jobTotal = Object.values(d.jobs).reduce((a,b)=>a+b,0);

  var hbHtml = '';
  (d.heartbeat||[]).forEach(h=>{
    var dot = h.up===null?'⬤':h.up?'<span style="color:var(--green)">⬤</span>':'<span style="color:var(--red)">⬤</span>';
    hbHtml += `<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border)">
      ${dot} <span style="flex:1">${esc(h.name)}</span>
      <span style="color:var(--muted2)">${h.ms!=null?h.ms+'ms':'—'}</span>
      <span style="color:var(--muted);font-size:10px">${ts(h.ts)}</span>
    </div>`;
  });
  if (!hbHtml) hbHtml = '<div style="color:var(--muted2);font-size:11px;padding:8px 0">No heartbeat targets configured. Add them in config.php → heartbeat.targets</div>';

  var logHtml = '';
  (d.cron_log||[]).forEach(r=>{
    var ok = r.success==1;
    logHtml += `<tr>
      <td>${esc(r.label||'Job #'+r.id)}</td>
      <td>${badge(ok?'done':'failed')}</td>
      <td style="color:var(--muted2)">${ts(r.started_at)}</td>
      <td style="color:var(--muted2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.error||'')}</td>
    </tr>`;
  });

  var skillsHtml = (d.skills||[]).map(s=>`<span style="padding:3px 8px;background:var(--surface2);border:1px solid var(--border);border-radius:2px;font-size:10px;color:var(--muted2)">${esc(s)}</span>`).join('');

  document.getElementById('pulse-body').innerHTML = `
    <div class="widget-grid">
      <div class="widget">
        <div class="w-label">LLM</div>
        <div class="w-val ${d.llm.ok?'status-ok':'status-err'}">${d.llm.ok?'Online':'Offline'}</div>
        <div class="w-sub">${esc(d.llm.provider)} / ${esc(d.llm.model)} ${d.llm.ms?'· '+d.llm.ms+'ms':''}</div>
      </div>
      <div class="widget">
        <div class="w-label">Jobs</div>
        <div class="w-val">${d.jobs.pending||0}</div>
        <div class="w-sub">pending · ${d.jobs.done||0} done · ${d.jobs.failed||0} failed</div>
      </div>
      <div class="widget">
        <div class="w-label">Skills Loaded</div>
        <div class="w-val">${(d.skills||[]).length}</div>
        <div class="w-sub">auto-discovered from /skills</div>
      </div>
      <div class="widget">
        <div class="w-label">Database</div>
        <div class="w-val ${d.db.ok?'status-ok':'status-err'}">${d.db.ok?'Connected':'Offline'}</div>
        <div class="w-sub">${d.db.ok?'MySQL ready':'Check config.php database settings'}</div>
      </div>
    </div>

    <div class="widget-grid-2">
      <div class="widget">
        <div class="section-head"><div class="section-label">Heartbeat</div><div class="section-line"></div></div>
        ${hbHtml}
      </div>
      <div class="widget">
        <div class="section-head"><div class="section-label">Recent Cron Runs</div><div class="section-line"></div></div>
        ${logHtml ? '<table class="data-table"><thead><tr><th>Job</th><th>Status</th><th>Ran</th><th>Error</th></tr></thead><tbody>'+logHtml+'</tbody></table>' : '<div style="color:var(--muted2);font-size:11px;padding:8px 0">No cron runs yet. Add a cron job to trigger cron.php every 5 minutes.</div>'}
      </div>
    </div>

    <div class="widget">
      <div class="section-head"><div class="section-label">Loaded Skills</div><div class="section-line"></div></div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px">${skillsHtml||'<span style="color:var(--muted2);font-size:11px">No skills found in /skills</span>'}</div>
    </div>
  `;

  addLog('Pulse refreshed — LLM '+(d.llm.ok?'online':'offline'), d.llm.ok?'ok':'err');
}

// ── Jobs ───────────────────────────────────────────────────────────────────
async function loadJobs() {
  var d = await api('jobs');
  var jobs = d.jobs||[];
  document.getElementById('jobs-badge').textContent = jobs.filter(j=>j.status==='pending').length;

  if (!jobs.length) {
    document.getElementById('jobs-body').innerHTML = '<div class="empty-state"><div class="empty-icon">⏱</div><div class="empty-label">No jobs scheduled yet</div></div>';
    return;
  }

  var rows = jobs.map(j=>`<tr>
    <td>${esc(j.id)}</td>
    <td>${esc(j.label||'—')}</td>
    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted2)">${esc(j.goal)}</td>
    <td>${badge(j.status)}</td>
    <td style="color:var(--muted2)">${ts(j.run_at)}</td>
    <td><span style="color:var(--muted2);font-size:10px">${esc(j.recur||'none')}</span></td>
    <td style="color:var(--muted2)">${ts(j.last_run_at)}</td>
    <td style="white-space:nowrap">
      <button class="btn btn-ghost btn-sm" onclick="runJobNow(${j.id})">▶ Now</button>
      ${j.status!=='cancelled'?'<button class="btn btn-danger btn-sm" onclick="cancelJob('+j.id+')" style="margin-left:4px">✕</button>':''}
    </td>
  </tr>`).join('');

  document.getElementById('jobs-body').innerHTML = `
    <table class="data-table">
      <thead><tr>
        <th>#</th><th>Label</th><th>Goal</th><th>Status</th>
        <th>Next Run</th><th>Recur</th><th>Last Run</th><th>Actions</th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
}

async function runJobNow(id) {
  addLog('Running job #'+id+' manually…','info');
  var d = await api('run_job_now','POST',{job_id:id});
  addLog('Job #'+id+': '+(d.ok?'done':'failed')+' — '+(d.response||d.message||''), d.ok?'ok':'err');
  loadJobs();
}

async function cancelJob(id) {
  if (!confirm('Cancel job #'+id+'?')) return;
  await api('cancel_job','POST',{job_id:id});
  addLog('Job #'+id+' cancelled','warn');
  loadJobs();
}

// ── New Job Modal ──────────────────────────────────────────────────────────
function openJobModal() { document.getElementById('job-modal').classList.add('open'); }
function closeJobModal() { document.getElementById('job-modal').classList.remove('open'); document.getElementById('jm-result').textContent=''; }

async function saveJob() {
  var goal = document.getElementById('jm-goal').value.trim();
  if (!goal) { document.getElementById('jm-result').innerHTML='<span style="color:var(--red)">Goal is required.</span>'; return; }
  var runAt = document.getElementById('jm-run-at').value.trim() || 'in 1 minute';

  // Use the scheduler skill
  require_once_workaround: {
    var agent = await api('run_goal','POST',{goal:
      `Schedule a job with the scheduler skill. action=create, goal="${goal.replace(/"/g,"'")}", run_at="${runAt}", recur="${document.getElementById('jm-recur').value}", label="${document.getElementById('jm-label').value.replace(/"/g,"'")}", notify_email="${document.getElementById('jm-email').value}"`
    });
    var ok = agent.success!==false;
    document.getElementById('jm-result').innerHTML = ok
      ? '<span style="color:var(--green)">✓ Job scheduled via agent.</span>'
      : '<span style="color:var(--red)">'+esc(agent.response)+'</span>';
    if (ok) { addLog('Job scheduled: '+goal.slice(0,40),'ok'); setTimeout(closeJobModal,1200); loadJobs(); }
  }
}

// ── Tasks ──────────────────────────────────────────────────────────────────
async function loadTaskProjects() {
  var d = await api('task_projects');
  var sel = document.getElementById('project-select');
  var cur = sel.value;
  sel.innerHTML = '<option value="">— select project —</option>';
  (d.projects||[]).forEach(p=>{
    var pct = p.total>0?Math.round((p.done/p.total)*100):0;
    var opt = document.createElement('option');
    opt.value = p.project;
    opt.textContent = p.project+' ('+pct+'%)';
    if (p.project===cur) opt.selected=true;
    sel.appendChild(opt);
  });
  if (cur) loadTasks(cur);
}

async function loadTasks(project) {
  if (!project) return;
  var d = await api('tasks&project='+encodeURIComponent(project));
  var tasks = d.tasks||[];

  var cols = {pending:[],in_progress:[],blocked:[],done:[]};
  tasks.forEach(t=>{ if(cols[t.status]) cols[t.status].push(t); else cols['pending'].push(t); });

  var colHtml = Object.entries({pending:'Pending',in_progress:'In Progress',blocked:'Blocked',done:'Done'}).map(([k,label])=>`
    <div class="kanban-col">
      <div class="kanban-col-title">${label} <span style="color:var(--muted)">(${cols[k].length})</span></div>
      ${cols[k].map(t=>`
        <div class="task-card" onclick="cycleTaskStatus(${t.id},'${k}')">
          <div class="task-card-title">${esc(t.title)}</div>
          <div class="task-card-meta">${badge(t.priority)} ${t.due_at?'· due '+ts(t.due_at):''}</div>
          ${t.notes?'<div style="font-size:10px;color:var(--muted2);margin-top:4px">'+esc(t.notes)+'</div>':''}
        </div>
      `).join('')}
    </div>
  `).join('');

  var total = tasks.length;
  var done  = cols['done'].length;
  var pct   = total>0?Math.round((done/total)*100):0;

  document.getElementById('tasks-body').innerHTML = `
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
      <div style="flex:1;height:3px;background:var(--border);border-radius:2px;overflow:hidden">
        <div style="width:${pct}%;height:100%;background:var(--green);border-radius:2px;transition:width .4s"></div>
      </div>
      <div style="font-size:11px;color:var(--muted2)">${done}/${total} done (${pct}%)</div>
    </div>
    <div class="kanban">${colHtml}</div>
    <div style="margin-top:10px;font-size:10px;color:var(--muted2)">Click a task card to cycle its status</div>
  `;
}

var statusCycle = {pending:'in_progress',in_progress:'done',done:'pending',blocked:'pending'};
async function cycleTaskStatus(id, current) {
  var next = statusCycle[current]||'pending';
  await api('task_action','POST',{action:'status',id,status:next});
  loadTasks(document.getElementById('project-select').value);
}

// ── Heartbeat ──────────────────────────────────────────────────────────────
async function loadHeartbeat() {
  var d = await api('heartbeat');
  var targets = d.targets||[];

  if (!targets.length) {
    document.getElementById('hb-body').innerHTML = `
      <div class="empty-state">
        <div class="empty-icon">♥</div>
        <div class="empty-label">No targets configured</div>
        <div style="margin-top:8px;font-size:11px;color:var(--muted2)">Add targets to config.php → heartbeat.targets<br>Then use the "Ping URL" button to test manually</div>
      </div>`;
    return;
  }

  var html = targets.map(t=>{
    var up = t.up===null ? null : t.up;
    var statusColor = up===null?'var(--muted2)':up?'var(--green)':'var(--red)';
    var statusText  = up===null?'Unknown':up?'Up':'Down';

    var sparkHtml = '';
    if (t.history&&t.history.length) {
      var maxMs = Math.max(...t.history.map(h=>h.response_ms||0))||1;
      sparkHtml = t.history.slice(0,20).map(h=>{
        var pct = Math.round(((h.response_ms||0)/maxMs)*100);
        var col = h.up?'var(--green)':'var(--red)';
        return `<div class="hb-tick" style="height:${Math.max(4,pct)}%;background:${col};flex:1" title="${h.response_ms}ms ${h.ts}"></div>`;
      }).join('');
    }

    return `
      <div class="widget" style="margin-bottom:10px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
          <div style="font-size:18px;color:${statusColor}">⬤</div>
          <div>
            <div style="font-size:12px;font-weight:500">${esc(t.name)}</div>
            <div style="font-size:10px;color:var(--muted2)">${esc(t.url)}</div>
          </div>
          <div style="margin-left:auto;text-align:right">
            <div style="font-size:18px;font-weight:500;color:${statusColor}">${statusText}</div>
            <div style="font-size:10px;color:var(--muted2)">${t.ms!=null?t.ms+'ms':'—'} · ${ts(t.ts)}</div>
          </div>
        </div>
        ${sparkHtml?`<div class="hb-bar">${sparkHtml}</div>`:''}
      </div>`;
  }).join('');

  document.getElementById('hb-body').innerHTML = html;
}

// ── Ping Modal ─────────────────────────────────────────────────────────────
function openPingModal()  { document.getElementById('ping-modal').classList.add('open'); }
function closePingModal() { document.getElementById('ping-modal').classList.remove('open'); document.getElementById('pm-result').textContent=''; }

async function doPing() {
  var url = document.getElementById('pm-url').value.trim();
  if (!url) return;
  document.getElementById('pm-result').innerHTML = '<span class="spin"></span> Pinging…';
  var d = await api('ping_url','POST',{url});
  document.getElementById('pm-result').innerHTML = d.ok
    ? `<span style="color:var(--green)">✓ Up — HTTP ${d.status} in ${d.ms}ms</span>`
    : `<span style="color:var(--red)">✗ Down — HTTP ${d.status} ${esc(d.error||'')}</span>`;
  addLog('Ping '+url+': '+(d.ok?'up '+d.ms+'ms':'down'), d.ok?'ok':'err');
}

// ── CLI ────────────────────────────────────────────────────────────────────

// Persistent session ID per browser tab
var cliSessionId = sessionStorage.getItem('garra_cli_session');
if (!cliSessionId) {
  cliSessionId = 'cli_' + Math.random().toString(36).slice(2, 10);
  sessionStorage.setItem('garra_cli_session', cliSessionId);
}

function updateCliSessionLabel() {
  var el = document.getElementById('cli-session-label');
  if (el) el.textContent = 'session: ' + cliSessionId;
}

function resetCliSession() {
  cliSessionId = 'cli_' + Math.random().toString(36).slice(2, 10);
  sessionStorage.setItem('garra_cli_session', cliSessionId);
  updateCliSessionLabel();
  document.getElementById('cli-log').innerHTML = '';
  addLog('New CLI session started: ' + cliSessionId, 'info');
}

document.addEventListener('DOMContentLoaded',()=>{
  var inp = document.getElementById('cli-input');
  if (inp) inp.addEventListener('keydown',e=>{ if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){e.preventDefault();runCliGoal();} });
  updateCliSessionLabel();
});

async function runCliGoal() {
  var inp  = document.getElementById('cli-input');
  var goal = inp.value.trim();
  if (!goal) return;

  var btn = document.getElementById('cli-run-btn');
  btn.disabled = true;
  btn.textContent = '…';
  inp.value = '';

  var log = document.getElementById('cli-log');
  var entry = document.createElement('div');
  entry.className = 'cli-entry';
  entry.innerHTML = `<div class="cli-goal">▶ ${esc(goal)}</div><div class="cli-result" style="color:var(--muted2)"><span class="spin"></span> Running…</div>`;
  log.prepend(entry);

  addLog('CLI goal: '+goal.slice(0,50),'info');
  var t0 = Date.now();

  try {
    var d = await api('run_goal','POST',{goal, session_id: cliSessionId});
    var elapsed = ((Date.now()-t0)/1000).toFixed(1);
    var traceHtml = '';

    if (d.history&&d.history.length) {
      d.history.slice(1,-1).forEach(m=>{
        if (m.role==='assistant_tool_call') {
          traceHtml += `<div class="cli-tool">⬡ tool call: ${esc(JSON.stringify(m.tool_calls_raw).slice(0,120))}</div>`;
        } else if (m.role==='tool') {
          traceHtml += `<div class="cli-tool" style="color:var(--green)">← ${esc(m.name)}: ${esc(String(m.content||'').slice(0,120))}</div>`;
        }
      });
    }

    entry.innerHTML = `
      <div class="cli-goal">▶ ${esc(goal)}</div>
      ${traceHtml}
      <div class="${d.success===false?'cli-err':'cli-result'}">${esc(d.response||d.message||'')}</div>
      <div class="cli-meta">${elapsed}s · ${d.history?d.history.length:0} messages</div>`;

    addLog('CLI done in '+elapsed+'s', d.success===false?'err':'ok');
  } catch(e) {
    entry.querySelector('.cli-result').textContent = 'Error: '+e.message;
    addLog('CLI error: '+e.message,'err');
  }

  btn.disabled = false;
  btn.textContent = '▶ Run';
  log.scrollTop = 0;
}

// ── Auto-poll ──────────────────────────────────────────────────────────────
loadPulse();
setInterval(()=>{ if(currentPanel==='pulse') loadPulse(); }, 30000);
setInterval(()=>{ if(currentPanel==='heartbeat') loadHeartbeat(); }, 15000);
setInterval(pollLog, 5000);
pollLog();
</script>

</body>
</html>
