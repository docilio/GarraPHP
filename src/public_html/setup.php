<?php
/**
 * GarraPHP — Setup & Configurator
 * ================================
 * One-time guided setup. Detects environment, collects config,
 * writes config.php, creates DB tables, and verifies connectivity.
 *
 * After setup completes successfully, this file locks itself.
 * Unlock by deleting storage/setup.lock
 */
define('GARRA_EXEC', true);

// ── Path to the garra/ engine folder (one level above public_html) ────────
define('GARRA_ROOT', dirname(__DIR__) . '/garra');

// ── Session for admin auth ─────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Lock check ────────────────────────────────────────────────────────────
$lockFile   = GARRA_ROOT . '/storage/setup.lock';
$isComplete = file_exists($lockFile);
$lockData   = [];
if ($isComplete) {
    $raw = file_get_contents($lockFile);
    // Lock file format: first line is date, second line is JSON
    $lines = explode("\n", trim($raw), 2);
    $lockData = count($lines) > 1 ? (json_decode($lines[1], true) ?? []) : [];
}

$wantsUnlock   = isset($_GET['unlock']) && $_GET['unlock'] === 'yes';
$isAuthed      = !empty($_SESSION['garra_setup_authed']);
$hasPassword   = !empty($lockData['password_hash']);

// Redirect completed setup unless trying to unlock
if ($isComplete && !$wantsUnlock) {
    header('Location: dashboard.php');
    exit;
}

// If completed and wants unlock — require password auth
if ($isComplete && $wantsUnlock && !$isAuthed) {
    // Handle login form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        $given = $_POST['admin_password'] ?? '';
        if ($hasPassword && password_verify($given, $lockData['password_hash'])) {
            $_SESSION['garra_setup_authed'] = true;
            header('Location: setup.php?unlock=yes');
            exit;
        } else {
            $loginError = 'Incorrect password.';
        }
    }

    // Show login gate
    $loginError = $loginError ?? '';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GarraPHP — Setup Login</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#090909;--surface:#111;--border:#222;--border-hi:#333;--text:#c0c0c0;--muted:#404040;--muted2:#5e5e5e;--accent:#f5a623;--accent-dim:#4a3008;--green:#3dba6f;--red:#e05252;--mono:'IBM Plex Mono',monospace}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);color:var(--text);font-family:var(--mono);font-size:13px}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,.04) 2px,rgba(0,0,0,.04) 4px);pointer-events:none}
.gate{background:var(--surface);border:1px solid var(--border);border-radius:2px;padding:36px 40px;width:360px}
.gate-logo{font-size:18px;font-weight:600;letter-spacing:.12em;color:var(--accent);text-transform:uppercase;margin-bottom:6px}
.gate-logo span{color:var(--muted2);font-weight:300}
.gate-sub{font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted2);margin-bottom:28px}
label{display:block;font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted2);margin-bottom:5px}
input[type=password]{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);font-family:var(--mono);font-size:12px;padding:9px 11px;border-radius:2px;outline:none;transition:border-color .15s}
input[type=password]:focus{border-color:var(--accent-dim)}
.error{color:var(--red);font-size:11px;margin-top:8px;min-height:18px}
button{margin-top:16px;width:100%;padding:10px;background:var(--accent);border:none;border-radius:2px;color:#000;font-family:var(--mono);font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;cursor:pointer;transition:opacity .15s}
button:hover{opacity:.85}
.back{display:block;text-align:center;margin-top:12px;font-size:10px;color:var(--muted2);text-decoration:none}
.back:hover{color:var(--text)}
</style>
</head>
<body>
<div class="gate">
  <div class="gate-logo">Garra<span>PHP</span></div>
  <div class="gate-sub">Setup — Admin Access Required</div>
  <form method="post" action="setup.php?unlock=yes">
    <label for="pw">Admin Password</label>
    <input type="password" id="pw" name="admin_password" autofocus placeholder="Enter admin password">
    <div class="error"><?= htmlspecialchars($loginError) ?></div>
    <button type="submit">Unlock Setup →</button>
  </form>
  <a class="back" href="dashboard.php">← Back to Dashboard</a>
</div>
</body>
</html><?php
    exit;
}

// Auth passed or first-time setup — clear session flag after use
if ($isAuthed && $isComplete) {
    // Keep session alive for the duration of setup editing
}

// ── Handle AJAX actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    ob_start(); // catch any stray output (warnings, notices) before JSON
    header('Content-Type: application/json');

    switch ($_GET['action']) {

        case 'verify_password':
            $data = json_decode(file_get_contents('php://input'), true);
            $given = $data['password'] ?? '';
            if ($hasPassword && password_verify($given, $lockData['password_hash'])) {
                $_SESSION['garra_setup_authed'] = true;
                ob_clean();
                echo json_encode(['ok' => true]);
            } else {
                ob_clean();
                echo json_encode(['ok' => false, 'message' => 'Incorrect password.']);
            }
            exit;

        case 'logout':
            unset($_SESSION['garra_setup_authed']);
            ob_clean();
            echo json_encode(['ok' => true]);
            exit;

        case 'test_llm':
            $data = json_decode(file_get_contents('php://input'), true);
            ob_clean();
            echo json_encode(setup_test_llm($data));
            exit;

        case 'test_db':
            $data = json_decode(file_get_contents('php://input'), true);
            ob_clean();
            echo json_encode(setup_test_db($data));
            exit;

        case 'test_email':
            $data = json_decode(file_get_contents('php://input'), true);
            ob_clean();
            echo json_encode(setup_test_email($data));
            exit;

        case 'save':
            $data = json_decode(file_get_contents('php://input'), true);
            ob_clean();
            echo json_encode(setup_save($data, $lockFile, $lockData));
            exit;

        case 'create_tables':
            $data = json_decode(file_get_contents('php://input'), true);
            ob_clean();
            echo json_encode(setup_create_tables($data));
            exit;
    }
    ob_clean();
    echo json_encode(['ok' => false, 'message' => 'Unknown action']);
    exit;
}

// ── Environment probe ─────────────────────────────────────────────────────
function setup_probe(): array
{
    $checks = [];
    $storageDir = GARRA_ROOT . '/storage';

    // Auto-create storage/ and subdirs if missing
    foreach ([$storageDir, $storageDir.'/heartbeat', $storageDir.'/ratelimit'] as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    }
    // Try chmod if exists but not writable
    if (is_dir($storageDir) && !is_writable($storageDir)) {
        @chmod($storageDir, 0775);
    }

    $checks['php_version'] = [
        'label' => 'PHP Version',
        'value' => PHP_VERSION,
        'ok'    => version_compare(PHP_VERSION, '8.0', '>='),
        'note'  => 'PHP 8.0+ required.',
        'fix'   => 'Contact your host to upgrade PHP. In cPanel go to MultiPHP Manager and select PHP 8.0 or higher for this domain.',
    ];
    $checks['curl'] = [
        'label' => 'cURL Extension',
        'value' => function_exists('curl_init') ? 'Available' : 'Missing',
        'ok'    => function_exists('curl_init'),
        'note'  => 'Required for all LLM and webhook calls.',
        'fix'   => 'Enable the curl extension in cPanel → Select PHP Version → Extensions. If unavailable, contact your host — cURL is standard on all modern shared hosts.',
    ];
    $checks['pdo_mysql'] = [
        'label' => 'PDO MySQL',
        'value' => extension_loaded('pdo_mysql') ? 'Available' : 'Missing',
        'ok'    => extension_loaded('pdo_mysql'),
        'warn'  => true, // non-fatal
        'note'  => 'Required for scheduler, tasks, and database skill. Agent still runs without it.',
        'fix'   => 'Enable pdo_mysql in cPanel → Select PHP Version → Extensions. GarraPHP works without it but scheduling and task features will be unavailable.',
    ];
    $checks['json'] = [
        'label' => 'JSON Extension',
        'value' => function_exists('json_encode') ? 'Available' : 'Missing',
        'ok'    => function_exists('json_encode'),
        'note'  => 'Required — built into PHP 8 by default.',
        'fix'   => 'JSON is bundled with PHP 8+. If this fails, your PHP installation may be corrupted. Contact your host.',
    ];

    // Detailed storage check with diagnostic info
    $storageExists   = is_dir($storageDir);
    $storageWritable = $storageExists && is_writable($storageDir);
    $storageOwner    = $storageExists && function_exists('posix_getpwuid') && function_exists('fileowner')
                        ? (posix_getpwuid(fileowner($storageDir))['name'] ?? 'unknown') : null;
    $processOwner    = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                        ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : null;
    $storagePerms    = $storageExists ? substr(sprintf('%o', fileperms($storageDir)), -4) : null;

    $storageDetail = $storageWritable ? 'Writable' : (
        $storageExists
            ? 'Exists but not writable (perms: '.($storagePerms??'?')
              .($storageOwner ? ', owner: '.$storageOwner : '')
              .($processOwner ? ', process: '.$processOwner : '').')'
            : 'Directory missing'
    );

    $checks['storage_writable'] = [
        'label' => 'storage/ Writable',
        'value' => $storageWritable ? 'Writable' : ($storageExists ? 'Not writable' : 'Missing'),
        'ok'    => $storageWritable,
        'note'  => 'Required for sessions, heartbeat history, rate limiting, and cron lock.',
        'fix'   => 'In cPanel File Manager: right-click the storage/ folder → Change Permissions → set to 755. '
                 . 'If that fails try 775. If storage/ does not exist, create it manually. '
                 . ($storageDetail !== ($storageWritable ? 'Writable' : '') ? 'Diagnostic: '.$storageDetail.'.' : '')
                 . ($storageOwner && $processOwner && $storageOwner !== $processOwner
                    ? ' Owner mismatch detected (file: '.$storageOwner.', PHP process: '.$processOwner.'). Your host may use suEXEC — try setting permissions to 755 or ask your host to chown storage/ to the PHP process user.'
                    : ''),
    ];

    $configWritable = (file_exists(GARRA_ROOT . '/config.php') && is_writable(GARRA_ROOT . '/config.php'))
                   || (!file_exists(GARRA_ROOT . '/config.php') && is_writable(GARRA_ROOT));
    $checks['config_writable'] = [
        'label' => 'config.php Writable',
        'value' => $configWritable ? 'Writable' : 'Not writable',
        'ok'    => $configWritable,
        'note'  => 'Setup needs to write config.php to this directory.',
        'fix'   => 'In cPanel File Manager: if config.php already exists, right-click it → Change Permissions → 644. '
                 . 'If it does not exist yet, make sure the parent folder ('
                 . basename(GARRA_ROOT).') is writable (755).',
    ];
    $checks['ssl'] = [
        'label' => 'OpenSSL',
        'value' => extension_loaded('openssl') ? 'Available' : 'Missing',
        'ok'    => extension_loaded('openssl'),
        'warn'  => true,
        'note'  => 'Required for HTTPS API calls and SMTP TLS encryption.',
        'fix'   => 'Enable openssl in cPanel → Select PHP Version → Extensions. Without it, LLM API calls over HTTPS may fail.',
    ];

    return $checks;
}

// ── Test LLM connectivity ─────────────────────────────────────────────────
function setup_test_llm(array $d): array
{
    $provider = $d['provider'] ?? 'openai';
    $key      = $d['api_key'] ?? '';
    $model    = $d['model'] ?? 'gpt-4o-mini';
    $baseUrl  = rtrim($d['base_url'] ?? 'https://api.openai.com/v1', '/');

    $headers = ['Content-Type: application/json'];

    if ($provider === 'anthropic') {
        $headers[] = 'x-api-key: ' . $key;
        $headers[] = 'anthropic-version: 2023-06-01';
        $url = $baseUrl . '/messages';
        $payload = ['model' => $model, 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => 'Hi']]];
    } elseif ($provider === 'gemini') {
        // Gemini uses its own REST format with API key as query param
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $key;
        $payload = ['contents' => [['parts' => [['text' => 'Hi']]]]];
    } else {
        $headers[] = 'Authorization: Bearer ' . $key;
        $url = $baseUrl . '/chat/completions';
        $payload = ['model' => $model, 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => 'Hi']]];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['ok' => false, 'message' => 'cURL error: ' . $err];
    $resp = json_decode($raw, true);
    if ($http >= 400) {
        $msg = $resp['error']['message'] ?? $resp['error']['msg'] ?? "HTTP {$http}";
        return ['ok' => false, 'message' => 'Provider error: ' . $msg];
    }
    return ['ok' => true, 'message' => "Connected. HTTP {$http} — model responded."];
}

// ── Test DB connectivity ──────────────────────────────────────────────────
function setup_test_db(array $d): array
{
    // WordPress auto-read
    if (!empty($d['wp_config_path']) && file_exists($d['wp_config_path'])) {
        $c = file_get_contents($d['wp_config_path']);
        foreach (['name'=>'DB_NAME','user'=>'DB_USER','pass'=>'DB_PASSWORD','host'=>'DB_HOST'] as $k=>$const) {
            if (preg_match("/define\s*\(\s*['\"]" . $const . "['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $c, $m)) {
                $d[$k] = $m[1];
            }
        }
    }

    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'] ?? 'localhost', $d['name'] ?? '');
        $pdo = new PDO($dsn, $d['user'] ?? '', $d['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
        return ['ok' => true, 'message' => "Connected. MySQL/MariaDB {$ver}"];
    } catch (PDOException $e) {
        return ['ok' => false, 'message' => 'DB error: ' . $e->getMessage()];
    }
}

// ── Test SMTP connectivity ────────────────────────────────────────────────
function setup_test_email(array $d): array
{
    $driver = $d['email_driver'] ?? 'smtp';

    if ($driver === 'none') {
        return ['ok' => true, 'message' => 'Email skipped — no driver selected.'];
    }

    if ($driver === 'mailgun') {
        $apiKey = $d['mg_key'] ?? '';
        $domain = $d['mg_domain'] ?? '';
        if (!$apiKey || !$domain) return ['ok' => false, 'message' => 'Mailgun API key and domain are required.'];
        $region = strtolower($d['mg_region'] ?? 'us');
        $base   = $region === 'eu' ? 'https://api.eu.mailgun.net/v3' : 'https://api.mailgun.net/v3';
        $ch = curl_init("{$base}/domains/{$domain}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_USERPWD=>"api:{$apiKey}", CURLOPT_TIMEOUT=>10]);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 200) return ['ok'=>true, 'message'=>"Mailgun domain '{$domain}' verified."];
        $resp = json_decode($raw, true);
        return ['ok'=>false, 'message'=>'Mailgun error: '.($resp['message'] ?? "HTTP {$http}")];
    }

    if ($driver === 'sendgrid') {
        $apiKey = $d['sg_key'] ?? '';
        if (!$apiKey) return ['ok'=>false, 'message'=>'SendGrid API key is required.'];
        $ch = curl_init('https://api.sendgrid.com/v3/user/account');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>["Authorization: Bearer {$apiKey}"], CURLOPT_TIMEOUT=>10]);
        $http = (int)curl_getinfo(($ch2 = $ch), CURLINFO_HTTP_CODE);
        curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($http === 200) return ['ok'=>true, 'message'=>'SendGrid API key is valid.'];
        return ['ok'=>false, 'message'=>"SendGrid returned HTTP {$http}. Check your API key."];
    }

    // SMTP — test socket connection and optionally STARTTLS/SSL handshake
    $host = $d['smtp_host'] ?? '';
    $port = (int)($d['smtp_port'] ?? 587);
    $enc  = strtolower($d['smtp_enc'] ?? 'tls');
    $user = $d['smtp_user'] ?? '';
    $pass = $d['smtp_pass'] ?? '';

    if (!$host) return ['ok'=>false, 'message'=>'SMTP host is required.'];

    // Step 1: socket connection
    $socketHost = ($enc === 'ssl') ? "ssl://{$host}" : $host;
    $sock = @fsockopen($socketHost, $port, $errno, $errstr, 10);

    if (!$sock) {
        // Give a targeted hint for the most common SiteGround/cPanel mistake
        $hint = '';
        if ($port === 587 && $enc === 'tls') {
            $hint = ' Tip: try port 465 with encryption=ssl, which is required by many shared hosts including SiteGround.';
        } elseif ($port === 465 && $enc === 'tls') {
            $hint = ' Port 465 requires encryption=ssl, not tls/STARTTLS.';
        }
        return ['ok'=>false, 'message'=>"Cannot connect to {$host}:{$port} [{$errno}]: {$errstr}.{$hint}"];
    }

    stream_set_timeout($sock, 10);
    $banner = fgets($sock, 512);

    // Step 2: EHLO
    fputs($sock, "EHLO setuptest\r\n");
    $ehlo = '';
    while ($line = fgets($sock, 512)) {
        $ehlo .= $line;
        if (substr($line, 3, 1) === ' ') break;
    }

    // Step 3: STARTTLS if needed
    if ($enc === 'tls') {
        fputs($sock, "STARTTLS\r\n");
        $stls = fgets($sock, 512);
        if (!str_starts_with($stls, '220')) {
            fclose($sock);
            return ['ok'=>false, 'message'=>"STARTTLS rejected by server: ".trim($stls)." — Try port 465 with encryption=ssl instead."];
        }
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($sock);
            return ['ok'=>false, 'message'=>"TLS handshake failed on {$host}:{$port}. Try port 465 with encryption=ssl."];
        }
        fputs($sock, "EHLO setuptest\r\n");
        while ($line = fgets($sock, 512)) { if (substr($line, 3, 1) === ' ') break; }
    }

    // Step 4: AUTH LOGIN
    if ($user && $pass) {
        fputs($sock, "AUTH LOGIN\r\n");
        fgets($sock, 512);
        fputs($sock, base64_encode($user) . "\r\n");
        fgets($sock, 512);
        fputs($sock, base64_encode($pass) . "\r\n");
        $authResp = fgets($sock, 512);
        fputs($sock, "QUIT\r\n");
        fclose($sock);

        if (str_starts_with($authResp, '235')) {
            return ['ok'=>true, 'message'=>"✓ Connected and authenticated to {$host}:{$port} ({$enc}). SMTP is ready."];
        }
        return ['ok'=>false, 'message'=>"Authentication failed: ".trim($authResp)." — Check username/password."];
    }

    fputs($sock, "QUIT\r\n");
    fclose($sock);

    return ['ok'=>true, 'message'=>"✓ Connected to {$host}:{$port} ({$enc}). No credentials to test."];
}

// ── Create DB tables ──────────────────────────────────────────────────────
function setup_create_tables(array $d): array
{
    try {
        $result = setup_test_db($d);
        if (!$result['ok']) return $result;

        if (!empty($d['wp_config_path']) && file_exists($d['wp_config_path'])) {
            $c = file_get_contents($d['wp_config_path']);
            foreach (['name'=>'DB_NAME','user'=>'DB_USER','pass'=>'DB_PASSWORD','host'=>'DB_HOST'] as $k=>$const) {
                if (preg_match("/define\s*\(\s*['\"]" . $const . "['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $c, $m)) $d[$k] = $m[1];
            }
        }

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'] ?? 'localhost', $d['name'] ?? '');
        $pdo = new PDO($dsn, $d['user'] ?? '', $d['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $tables = [
            'garra_jobs' => "CREATE TABLE IF NOT EXISTS `garra_jobs` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `label` VARCHAR(255) NULL,
                `goal` TEXT NOT NULL,
                `run_at` DATETIME NOT NULL,
                `recur` ENUM('none','hourly','daily','weekly','monthly') NOT NULL DEFAULT 'none',
                `status` ENUM('pending','running','done','failed','cancelled') NOT NULL DEFAULT 'pending',
                `notify_email` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_run_at` DATETIME NULL,
                INDEX `idx_status_run_at` (`status`, `run_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

            'garra_job_runs' => "CREATE TABLE IF NOT EXISTS `garra_job_runs` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `job_id` INT UNSIGNED NOT NULL,
                `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `finished_at` DATETIME NULL,
                `success` TINYINT(1) NULL,
                `response` TEXT NULL,
                `error` TEXT NULL,
                INDEX `idx_job_id` (`job_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

            'garra_tasks' => "CREATE TABLE IF NOT EXISTS `garra_tasks` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `project` VARCHAR(255) NOT NULL,
                `title` VARCHAR(500) NOT NULL,
                `notes` TEXT NULL,
                `priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
                `status` ENUM('pending','in_progress','done','blocked','cancelled') NOT NULL DEFAULT 'pending',
                `due_at` DATETIME NULL,
                `completed_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_project` (`project`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        ];

        $created = [];
        foreach ($tables as $name => $sql) {
            $pdo->exec($sql);
            $created[] = $name;
        }

        return ['ok' => true, 'message' => 'Tables ready: ' . implode(', ', $created)];
    } catch (PDOException $e) {
        return ['ok' => false, 'message' => 'Table creation failed: ' . $e->getMessage()];
    }
}

// ── Write config.php ──────────────────────────────────────────────────────
function setup_save(array $d, string $lockFile, array $lockData = []): array
{
    $cronSecret = bin2hex(random_bytes(16));
    $storageDir = GARRA_ROOT . '/storage';

    // Ensure storage subdirs
    foreach (['heartbeat', 'ratelimit'] as $sub) {
        $path = $storageDir . '/' . $sub;
        if (!is_dir($path)) @mkdir($path, 0755, true);
    }

    $phpBool   = fn($v) => $v ? 'true' : 'false';
    $phpStr    = fn($v) => "'" . addslashes($v) . "'";
    $wpPath    = $d['wp_config_path'] ?? '';
    $dbSection = $wpPath
        ? "        'wp_config_path' => {$phpStr($wpPath)},"
        : "        'wp_config_path' => null,
        'host'           => {$phpStr($d['db_host'] ?? 'localhost')},
        'name'           => {$phpStr($d['db_name'] ?? '')},
        'user'           => {$phpStr($d['db_user'] ?? '')},
        'pass'           => {$phpStr($d['db_pass'] ?? '')},";

    $emailDriver = $d['email_driver'] ?? 'smtp';

    $configContent = <<<PHP
<?php
if (!defined('GARRA_EXEC')) exit;

return [

    'provider' => {$phpStr($d['provider'] ?? 'openai')},
    'model'    => {$phpStr($d['model'] ?? 'gpt-4o-mini')},
    'api_key'  => {$phpStr($d['api_key'] ?? '')},
    'base_url' => {$phpStr($d['base_url'] ?? 'https://api.openai.com/v1')},

    'settings' => [
        'max_iterations' => {$phpStr((string)((int)($d['max_iterations'] ?? 5)))},
        'timeout'        => {$phpStr((string)((int)($d['timeout'] ?? 25)))},
        'skills_dir'     => GARRA_ROOT . '/skills',
        'storage_dir'    => GARRA_ROOT . '/storage',
    ],

    'auth' => [
        'enabled'     => false,
        'ui_exempt'   => true,
        'ping_exempt' => true,
        'keys'        => [],
    ],

    'rate_limit' => [
        'enabled' => false,
        'window'  => 60,
        'limit'   => 20,
        'backend' => 'file',
    ],

    'database' => [
        {$dbSection}
        'charset'        => 'utf8mb4',
        'table_prefix'   => {$phpStr($d['table_prefix'] ?? 'wp_')},
        'readonly'       => false,
    ],

    'email' => [
        'driver'    => {$phpStr($emailDriver)},
        'from_name' => {$phpStr($d['from_name'] ?? 'GarraPHP Agent')},
        'from_addr' => {$phpStr($d['from_addr'] ?? '')},
        'smtp' => [
            'host'       => {$phpStr($d['smtp_host'] ?? '')},
            'port'       => (int){$phpStr((string)((int)($d['smtp_port'] ?? 587)))},
            'encryption' => {$phpStr($d['smtp_enc'] ?? 'tls')},
            'username'   => {$phpStr($d['smtp_user'] ?? '')},
            'password'   => {$phpStr($d['smtp_pass'] ?? '')},
        ],
        'mailgun' => [
            'api_key' => {$phpStr($d['mg_key'] ?? '')},
            'domain'  => {$phpStr($d['mg_domain'] ?? '')},
            'region'  => {$phpStr($d['mg_region'] ?? 'us')},
        ],
        'sendgrid' => [
            'api_key' => {$phpStr($d['sg_key'] ?? '')},
        ],
    ],

    'heartbeat' => [
        'targets'         => [],
        'timeout'         => 10,
        'alert_threshold' => 2000,
        'history_limit'   => 100,
    ],

    'scheduler' => [
        'cron_secret' => '{$cronSecret}',
        'max_runtime' => 50,
    ],

    'webhook' => [
        'allowed_hosts' => [],
        'timeout'       => 15,
        'max_retries'   => 2,
    ],

    'tasks' => [
        'max_subtasks'  => 20,
        'storage_table' => 'garra_tasks',
    ],

    'system_prompt' => {$phpStr($d['system_prompt'] ?? 'You are a helpful AI agent running on GarraPHP. You have access to tools for monitoring, scheduling, database access, email, and web requests. Be concise, precise, and always use tools when they are relevant to the goal.')},

];
PHP;

    if (file_put_contents(GARRA_ROOT . '/config.php', $configContent) === false) {
        return ['ok' => false, 'message' => 'Could not write config.php — check file permissions.'];
    }

    // Write lock file with password hash
    @mkdir(dirname($lockFile), 0755, true);
    $lockPayload = [
        'provider'      => $d['provider'] ?? '',
        'model'         => $d['model'] ?? '',
        'completed_at'  => date('c'),
    ];
    // Hash admin password if provided
    $adminPassword = trim($d['admin_password'] ?? '');
    if ($adminPassword) {
        $lockPayload['password_hash'] = password_hash($adminPassword, PASSWORD_BCRYPT);
    } elseif (!empty($lockData['password_hash'])) {
        // Preserve existing hash if no new password given during re-save
        $lockPayload['password_hash'] = $lockData['password_hash'];
    }
    file_put_contents($lockFile, date('c') . "\n" . json_encode($lockPayload));

    $cronUrl  = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com');
    $cronUrl .= '/cron.php?secret=' . $cronSecret;

    return [
        'ok'          => true,
        'message'     => 'Configuration saved.',
        'cron_secret' => $cronSecret,
        'cron_url'    => $cronUrl,
        'cron_cmd'    => "*/5 * * * * curl -s \"{$cronUrl}\" > /dev/null 2>&1",
    ];
}

// ── Existing config reader (pre-populate form) ────────────────────────────
$existing = [];
if (file_exists(GARRA_ROOT . '/config.php')) {
    try { $existing = require GARRA_ROOT . '/config.php'; } catch (Throwable $e) {}
}

$probe = setup_probe();
$allOk = array_reduce($probe, fn($c, $p) => $c && $p['ok'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GarraPHP — Setup</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,300;0,400;0,500;0,600;1,400&family=IBM+Plex+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #090909;
  --surface:   #111;
  --surface2:  #161616;
  --border:    #222;
  --border-hi: #333;
  --text:      #c8c8c8;
  --muted:     #4a4a4a;
  --muted2:    #666;
  --accent:    #f5a623;
  --accent-dim:#6b470f;
  --green:     #3dba6f;
  --green-dim: #0d3320;
  --red:       #e05252;
  --red-dim:   #3a1010;
  --blue:      #4d9de0;
  --mono:      'IBM Plex Mono', monospace;
  --sans:      'IBM Plex Sans', sans-serif;
}

html, body {
  min-height: 100vh;
  background: var(--bg);
  color: var(--text);
  font-family: var(--mono);
  font-size: 13px;
  line-height: 1.6;
}

/* ── Scanline overlay ── */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background: repeating-linear-gradient(
    0deg,
    transparent,
    transparent 2px,
    rgba(0,0,0,.03) 2px,
    rgba(0,0,0,.03) 4px
  );
  pointer-events: none;
  z-index: 9999;
}

/* ── Layout ── */
.shell {
  max-width: 860px;
  margin: 0 auto;
  padding: 40px 20px 80px;
}

/* ── Header ── */
.masthead {
  display: flex;
  align-items: flex-end;
  gap: 16px;
  margin-bottom: 40px;
  padding-bottom: 20px;
  border-bottom: 1px solid var(--border);
}

.logo-text {
  font-size: 26px;
  font-weight: 600;
  letter-spacing: .1em;
  color: var(--accent);
  text-transform: uppercase;
}

.logo-text span { color: var(--muted2); font-weight: 300; }

.logo-sub {
  color: var(--muted2);
  font-size: 11px;
  letter-spacing: .15em;
  text-transform: uppercase;
  padding-bottom: 3px;
}

/* ── Steps nav ── */
.steps-nav {
  display: flex;
  gap: 0;
  margin-bottom: 36px;
  border: 1px solid var(--border);
  border-radius: 2px;
  overflow: hidden;
}

.step-tab {
  flex: 1;
  padding: 10px 8px;
  text-align: center;
  font-size: 10px;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--muted);
  border-right: 1px solid var(--border);
  cursor: pointer;
  transition: background .15s, color .15s;
  user-select: none;
  background: var(--surface);
}

.step-tab:last-child { border-right: none; }
.step-tab:hover { background: var(--surface2); color: var(--text); }
.step-tab.active { background: var(--accent-dim); color: var(--accent); }
.step-tab.done { color: var(--green); }
.step-tab.done::before { content: '✓ '; }

/* ── Section cards ── */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 2px;
  margin-bottom: 20px;
  display: none;
}

.card.active { display: block; }

.card-head {
  padding: 14px 20px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 12px;
}

.card-head-num {
  width: 22px; height: 22px;
  border: 1px solid var(--accent-dim);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  color: var(--accent);
  flex-shrink: 0;
}

.card-head-title {
  font-size: 12px;
  font-weight: 500;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--text);
}

.card-head-desc {
  margin-left: auto;
  font-size: 10px;
  color: var(--muted2);
}

.card-body { padding: 20px; }

/* ── Probe grid ── */
.probe-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.probe-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 2px;
}

.probe-dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}

.probe-dot.ok  { background: var(--green); box-shadow: 0 0 6px var(--green); }
.probe-dot.err { background: var(--red);   box-shadow: 0 0 6px var(--red); }
.probe-dot.warn{ background: var(--accent);box-shadow: 0 0 6px var(--accent); }

.probe-label { font-size: 11px; color: var(--text); flex: 1; }
.probe-value { font-size: 10px; color: var(--muted2); }

/* ── Form elements ── */
.field { margin-bottom: 16px; }
.field:last-child { margin-bottom: 0; }

.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }

label {
  display: block;
  font-size: 10px;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--muted2);
  margin-bottom: 5px;
}

input[type=text],
input[type=password],
input[type=email],
input[type=number],
input[type=url],
select,
textarea {
  width: 100%;
  background: var(--bg);
  border: 1px solid var(--border);
  color: var(--text);
  font-family: var(--mono);
  font-size: 12px;
  padding: 8px 10px;
  border-radius: 2px;
  outline: none;
  transition: border-color .15s;
}

input:focus, select:focus, textarea:focus { border-color: var(--accent-dim); }
input::placeholder, textarea::placeholder { color: var(--muted); }

select option { background: var(--surface); }

textarea { resize: vertical; min-height: 80px; line-height: 1.5; }

/* ── Radio pills ── */
.radio-group {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.radio-pill input[type=radio] { display: none; }

.radio-pill label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border: 1px solid var(--border);
  border-radius: 2px;
  cursor: pointer;
  font-size: 11px;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--muted2);
  transition: all .15s;
  margin: 0;
}

.radio-pill input:checked + label {
  border-color: var(--accent-dim);
  background: rgba(245,166,35,.08);
  color: var(--accent);
}

.radio-pill label:hover { border-color: var(--border-hi); color: var(--text); }

/* ── Subsection toggle ── */
.subsection { display: none; }
.subsection.visible { display: block; }

.divider {
  border: none;
  border-top: 1px solid var(--border);
  margin: 18px 0;
}

/* ── Test result banner ── */
.test-result {
  display: none;
  padding: 10px 14px;
  border-radius: 2px;
  font-size: 11px;
  margin-top: 10px;
  border: 1px solid;
}

.test-result.ok  { background: var(--green-dim); border-color: var(--green); color: var(--green); }
.test-result.err { background: var(--red-dim);   border-color: var(--red);   color: var(--red); }
.test-result.visible { display: block; }

/* ── Buttons ── */
.btn {
  padding: 9px 18px;
  font-family: var(--mono);
  font-size: 11px;
  font-weight: 500;
  letter-spacing: .1em;
  text-transform: uppercase;
  border-radius: 2px;
  cursor: pointer;
  border: 1px solid;
  transition: opacity .15s, background .15s;
}

.btn-primary {
  background: var(--accent);
  border-color: var(--accent);
  color: #000;
}

.btn-primary:hover { opacity: .85; }

.btn-ghost {
  background: transparent;
  border-color: var(--border-hi);
  color: var(--muted2);
}

.btn-ghost:hover { border-color: var(--text); color: var(--text); }

.btn-success {
  background: var(--green);
  border-color: var(--green);
  color: #000;
}

.btn:disabled { opacity: .35; cursor: not-allowed; }

.btn-row {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-top: 18px;
}

/* ── Field hint ── */
.hint {
  font-size: 10px;
  color: var(--muted2);
  margin-top: 4px;
  line-height: 1.5;
}

/* ── Cron box ── */
.cron-box {
  background: var(--bg);
  border: 1px solid var(--border);
  padding: 14px 16px;
  border-radius: 2px;
  font-size: 12px;
  color: var(--accent);
  word-break: break-all;
  margin: 10px 0;
  position: relative;
}

.copy-btn {
  position: absolute;
  top: 8px; right: 8px;
  padding: 3px 8px;
  background: transparent;
  border: 1px solid var(--border-hi);
  color: var(--muted2);
  font-family: var(--mono);
  font-size: 9px;
  cursor: pointer;
  border-radius: 2px;
}

.copy-btn:hover { color: var(--text); border-color: var(--text); }

/* ── Complete screen ── */
.complete-screen {
  text-align: center;
  padding: 50px 20px;
}

.complete-icon {
  font-size: 48px;
  color: var(--green);
  margin-bottom: 20px;
  display: block;
}

.complete-title {
  font-size: 20px;
  font-weight: 500;
  color: var(--text);
  letter-spacing: .1em;
  text-transform: uppercase;
  margin-bottom: 10px;
}

.complete-sub {
  color: var(--muted2);
  font-size: 12px;
  margin-bottom: 30px;
}

/* ── Spinner ── */
.spinner {
  display: inline-block;
  width: 12px; height: 12px;
  border: 2px solid var(--border-hi);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin .6s linear infinite;
  vertical-align: middle;
  margin-right: 6px;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* ── Progress bar ── */
.progress-track {
  height: 2px;
  background: var(--border);
  border-radius: 1px;
  margin-bottom: 30px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: var(--accent);
  border-radius: 1px;
  transition: width .4s ease;
}

/* ── Info tooltip ── */
.info-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px; height: 16px;
  border-radius: 50%;
  border: 1px solid var(--red);
  color: var(--red);
  font-size: 9px;
  font-style: normal;
  cursor: pointer;
  flex-shrink: 0;
  position: relative;
  margin-left: 4px;
  font-weight: 600;
  line-height: 1;
}

.info-icon:hover .tooltip {
  display: block;
}

.tooltip {
  display: none;
  position: absolute;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%);
  background: var(--surface2);
  border: 1px solid var(--border-hi);
  border-radius: 2px;
  padding: 10px 12px;
  width: 280px;
  font-size: 11px;
  color: var(--text);
  line-height: 1.6;
  z-index: 100;
  white-space: normal;
  text-align: left;
  box-shadow: 0 4px 16px rgba(0,0,0,.5);
}

.tooltip::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 6px solid transparent;
  border-top-color: var(--border-hi);
}

.tooltip-label {
  font-weight: 600;
  color: var(--accent);
  margin-bottom: 5px;
  font-size: 10px;
  letter-spacing: .06em;
  text-transform: uppercase;
}

.tooltip-fix {
  color: var(--text);
  font-size: 11px;
}
.alert {
  padding: 12px 16px;
  border-radius: 2px;
  font-size: 11px;
  margin-bottom: 20px;
  border: 1px solid;
}

.alert-warn {
  background: rgba(245,166,35,.07);
  border-color: var(--accent-dim);
  color: var(--accent);
}
</style>
</head>
<body>

<div class="shell">

  <div class="masthead">
    <div>
      <div class="logo-text">Garra<span>PHP</span></div>
    </div>
    <div class="logo-sub">Setup &amp; Configurator</div>
  </div>

  <div class="progress-track">
    <div class="progress-fill" id="progress" style="width:20%"></div>
  </div>

  <!-- Step tabs -->
  <div class="steps-nav">
    <div class="step-tab active" data-step="1">1 · Environment</div>
    <div class="step-tab"        data-step="2">2 · LLM</div>
    <div class="step-tab"        data-step="3">3 · Database</div>
    <div class="step-tab"        data-step="4">4 · Email</div>
    <div class="step-tab"        data-step="5">5 · Agent</div>
    <div class="step-tab"        data-step="6">6 · Cron &amp; Deploy</div>
  </div>

  <!-- ── Step 1: Environment ── -->
  <div class="card active" data-step="1">
    <div class="card-head">
      <div class="card-head-num">1</div>
      <div class="card-head-title">Environment Check</div>
      <div class="card-head-desc">Verifying server capabilities</div>
    </div>
    <div class="card-body">

      <?php if (!$allOk): ?>
      <div class="alert alert-warn">
        Some checks failed. GarraPHP may still run with limited functionality — review below.
      </div>
      <?php endif; ?>

      <div class="probe-grid">
        <?php foreach ($probe as $key => $check):
          $isWarn = !$check['ok'] && !empty($check['warn']);
          $dotClass = $check['ok'] ? 'ok' : ($isWarn ? 'warn' : 'err');
        ?>
        <div class="probe-item">
          <div class="probe-dot <?= $dotClass ?>"></div>
          <div class="probe-label"><?= htmlspecialchars($check['label']) ?></div>
          <div class="probe-value"><?= htmlspecialchars($check['value']) ?></div>
          <?php if (!$check['ok'] && !empty($check['fix'])): ?>
          <span class="info-icon">
            i
            <div class="tooltip">
              <div class="tooltip-label"><?= $isWarn ? '⚠ Warning' : '✗ Failed' ?> — How to fix</div>
              <div class="tooltip-fix"><?= htmlspecialchars($check['fix']) ?></div>
            </div>
          </span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

      <div class="field">
        <label>
          Admin Password
          <?php if (!empty($lockData['password_hash'])): ?>
            <span style="color:var(--green);margin-left:8px">✓ Password set</span>
          <?php endif; ?>
        </label>
        <input type="password" id="admin_password"
               placeholder="<?= !empty($lockData['password_hash']) ? 'Leave blank to keep existing password' : 'Set a password to protect setup access' ?>">
        <div class="hint">
          Required to access setup via <code>setup.php?unlock=yes</code> after initial configuration.
          <?php if (empty($lockData['password_hash'])): ?>
          <span style="color:var(--accent)">Recommended — without this, anyone can re-run setup.</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="btn-row">
        <button class="btn btn-primary" onclick="goStep(2)">Continue →</button>
      </div>
    </div>
  </div>

  <!-- ── Step 2: LLM ── -->
  <div class="card" data-step="2">
    <div class="card-head">
      <div class="card-head-num">2</div>
      <div class="card-head-title">LLM Provider</div>
      <div class="card-head-desc">AI brain configuration</div>
    </div>
    <div class="card-body">

      <div class="field">
        <label>Provider</label>
        <div class="radio-group" id="provider-group">
          <?php foreach (['openai'=>'OpenAI','anthropic'=>'Anthropic','gemini'=>'Gemini','ollama'=>'Ollama (Local)'] as $val=>$label): ?>
          <div class="radio-pill">
            <input type="radio" name="provider" id="p_<?=$val?>" value="<?=$val?>"
              <?= ($existing['provider'] ?? 'openai') === $val ? 'checked' : '' ?>
              onchange="onProviderChange(this.value)">
            <label for="p_<?=$val?>"><?=$label?></label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="field-row">
        <div class="field">
          <label>Model</label>
          <input type="text" id="model" placeholder="gpt-4o-mini"
                 value="<?= htmlspecialchars($existing['model'] ?? 'gpt-4o-mini') ?>">
        </div>
        <div class="field" id="field-base-url">
          <label>Base URL</label>
          <input type="url" id="base_url" placeholder="https://api.openai.com/v1"
                 value="<?= htmlspecialchars($existing['base_url'] ?? 'https://api.openai.com/v1') ?>">
        </div>
      </div>

      <div class="field" id="field-api-key">
        <label>API Key</label>
        <input type="password" id="api_key" placeholder="sk-..."
               value="<?= htmlspecialchars($existing['api_key'] ?? '') ?>">
      </div>

      <div id="ollama-hint" class="hint" style="display:none">
        Ollama base URL default: <code>http://localhost:11434/v1</code>. Leave API key blank.
      </div>

      <div id="llm-result" class="test-result"></div>

      <div class="btn-row">
        <button class="btn btn-ghost" onclick="testLLM(this)">⟳ Test Connection</button>
        <button class="btn btn-primary" onclick="goStep(3)">Continue →</button>
        <button class="btn btn-ghost" onclick="goStep(1)">← Back</button>
      </div>
    </div>
  </div>

  <!-- ── Step 3: Database ── -->
  <div class="card" data-step="3">
    <div class="card-head">
      <div class="card-head-num">3</div>
      <div class="card-head-title">Database</div>
      <div class="card-head-desc">MySQL / WordPress</div>
    </div>
    <div class="card-body">

      <div class="field">
        <label>Connection Method</label>
        <div class="radio-group">
          <div class="radio-pill">
            <input type="radio" name="db_method" id="db_manual" value="manual" checked
                   onchange="onDbMethod('manual')">
            <label for="db_manual">Manual Credentials</label>
          </div>
          <div class="radio-pill">
            <input type="radio" name="db_method" id="db_wp" value="wp"
                   onchange="onDbMethod('wp')">
            <label for="db_wp">WordPress (auto-read wp-config.php)</label>
          </div>
          <div class="radio-pill">
            <input type="radio" name="db_method" id="db_none" value="none"
                   onchange="onDbMethod('none')">
            <label for="db_none">Skip (no database)</label>
          </div>
        </div>
      </div>

      <div id="db-manual-fields" class="subsection visible">
        <hr class="divider">
        <div class="field-row">
          <div class="field">
            <label>Host</label>
            <input type="text" id="db_host" value="<?= htmlspecialchars(($existing['database'] ?? [])['host'] ?? 'localhost') ?>" placeholder="localhost">
          </div>
          <div class="field">
            <label>Database Name</label>
            <input type="text" id="db_name" value="<?= htmlspecialchars(($existing['database'] ?? [])['name'] ?? '') ?>" placeholder="my_database">
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Username</label>
            <input type="text" id="db_user" value="<?= htmlspecialchars(($existing['database'] ?? [])['user'] ?? '') ?>" placeholder="db_user">
          </div>
          <div class="field">
            <label>Password</label>
            <input type="password" id="db_pass" value="<?= htmlspecialchars(($existing['database'] ?? [])['pass'] ?? '') ?>" placeholder="">
          </div>
        </div>
        <div class="field">
          <label>Table Prefix</label>
          <input type="text" id="table_prefix" value="<?= htmlspecialchars(($existing['database'] ?? [])['table_prefix'] ?? 'wp_') ?>" placeholder="wp_" style="max-width:120px;">
        </div>
      </div>

      <div id="db-wp-fields" class="subsection">
        <hr class="divider">
        <div class="field">
          <label>wp-config.php Path</label>
          <input type="text" id="wp_config_path" placeholder="/home/customer/www/yoursite.com/public_html/wp-config.php"
                 value="<?= htmlspecialchars(($existing['database'] ?? [])['wp_config_path'] ?? '') ?>">
          <div class="hint">Absolute server path to your WordPress wp-config.php file.</div>
        </div>
      </div>

      <div id="db-result" class="test-result"></div>
      <div id="tables-result" class="test-result"></div>

      <div class="btn-row">
        <button class="btn btn-ghost" id="test-db-btn" onclick="testDB(this)">⟳ Test Connection</button>
        <button class="btn btn-ghost" id="create-tables-btn" onclick="createTables(this)" disabled>+ Create Tables</button>
        <button class="btn btn-primary" onclick="goStep(4)">Continue →</button>
        <button class="btn btn-ghost" onclick="goStep(2)">← Back</button>
      </div>
    </div>
  </div>

  <!-- ── Step 4: Email ── -->
  <div class="card" data-step="4">
    <div class="card-head">
      <div class="card-head-num">4</div>
      <div class="card-head-title">Email</div>
      <div class="card-head-desc">Notifications &amp; alerts</div>
    </div>
    <div class="card-body">

      <div class="field">
        <label>Email Driver</label>
        <div class="radio-group">
          <div class="radio-pill">
            <input type="radio" name="email_driver" id="ed_smtp" value="smtp" checked
                   onchange="onEmailDriver('smtp')">
            <label for="ed_smtp">SMTP</label>
          </div>
          <div class="radio-pill">
            <input type="radio" name="email_driver" id="ed_mailgun" value="mailgun"
                   onchange="onEmailDriver('mailgun')">
            <label for="ed_mailgun">Mailgun</label>
          </div>
          <div class="radio-pill">
            <input type="radio" name="email_driver" id="ed_sendgrid" value="sendgrid"
                   onchange="onEmailDriver('sendgrid')">
            <label for="ed_sendgrid">SendGrid</label>
          </div>
          <div class="radio-pill">
            <input type="radio" name="email_driver" id="ed_none" value="none"
                   onchange="onEmailDriver('none')">
            <label for="ed_none">Skip</label>
          </div>
        </div>
      </div>

      <div id="email-common" class="subsection visible">
        <hr class="divider">
        <div class="field-row">
          <div class="field">
            <label>From Name</label>
            <input type="text" id="from_name" value="<?= htmlspecialchars(($existing['email'] ?? [])['from_name'] ?? 'GarraPHP Agent') ?>" placeholder="GarraPHP Agent">
          </div>
          <div class="field">
            <label>From Address</label>
            <input type="email" id="from_addr" value="<?= htmlspecialchars(($existing['email'] ?? [])['from_addr'] ?? '') ?>" placeholder="agent@yourdomain.com">
          </div>
        </div>
      </div>

      <div id="smtp-fields" class="subsection visible">
        <hr class="divider">
        <div class="field-row-3">
          <div class="field">
            <label>SMTP Host</label>
            <input type="text" id="smtp_host" placeholder="mail.yourdomain.com" value="<?= htmlspecialchars(($existing['email']['smtp'] ?? [])['host'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Port</label>
            <input type="number" id="smtp_port" value="<?= htmlspecialchars((string)(($existing['email']['smtp'] ?? [])['port'] ?? 587)) ?>" placeholder="587" style="max-width:90px;">
          </div>
          <div class="field">
            <label>Encryption</label>
            <select id="smtp_enc">
              <option value="tls" <?= (($existing['email']['smtp'] ?? [])['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
              <option value="ssl" <?= (($existing['email']['smtp'] ?? [])['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
              <option value=""   <?= (($existing['email']['smtp'] ?? [])['encryption'] ?? '') === ''    ? 'selected' : '' ?>>None</option>
            </select>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Username</label>
            <input type="text" id="smtp_user" placeholder="agent@yourdomain.com">
          </div>
          <div class="field">
            <label>Password</label>
            <input type="password" id="smtp_pass" placeholder="">
          </div>
        </div>
      </div>

      <div id="mailgun-fields" class="subsection">
        <hr class="divider">
        <div class="field-row">
          <div class="field">
            <label>Mailgun API Key</label>
            <input type="password" id="mg_key" placeholder="key-xxxxxxxx">
          </div>
          <div class="field">
            <label>Mailgun Domain</label>
            <input type="text" id="mg_domain" placeholder="mg.yourdomain.com">
          </div>
        </div>
        <div class="field">
          <label>Region</label>
          <div class="radio-group">
            <div class="radio-pill">
              <input type="radio" name="mg_region" id="mg_us" value="us" checked>
              <label for="mg_us">US</label>
            </div>
            <div class="radio-pill">
              <input type="radio" name="mg_region" id="mg_eu" value="eu">
              <label for="mg_eu">EU</label>
            </div>
          </div>
        </div>
      </div>

      <div id="sendgrid-fields" class="subsection">
        <hr class="divider">
        <div class="field">
          <label>SendGrid API Key</label>
          <input type="password" id="sg_key" placeholder="SG.xxxxxxxx">
        </div>
      </div>

      <div id="email-result" class="test-result"></div>

      <div class="btn-row">
        <button class="btn btn-ghost" onclick="testEmail(this)">⟳ Test Connection</button>
        <button class="btn btn-primary" onclick="goStep(5)">Continue →</button>
        <button class="btn btn-ghost" onclick="goStep(3)">← Back</button>
      </div>
    </div>
  </div>

  <!-- ── Step 5: Agent ── -->
  <div class="card" data-step="5">
    <div class="card-head">
      <div class="card-head-num">5</div>
      <div class="card-head-title">Agent Behaviour</div>
      <div class="card-head-desc">System prompt &amp; limits</div>
    </div>
    <div class="card-body">

      <div class="field">
        <label>System Prompt</label>
        <textarea id="system_prompt" rows="5" placeholder="You are a helpful AI agent..."><?= htmlspecialchars($existing['system_prompt'] ?? 'You are a helpful AI agent running on GarraPHP. You have access to tools for monitoring, scheduling, database access, email, and web requests. Be concise, precise, and always use tools when they are relevant to the goal.') ?></textarea>
        <div class="hint">This is sent to the LLM before every conversation. Define the agent\'s role, tone, and focus area here.</div>
      </div>

      <hr class="divider">

      <div class="field-row">
        <div class="field">
          <label>Max Iterations per Goal</label>
          <input type="number" id="max_iterations" value="<?= (int)(($existing['settings'] ?? [])['max_iterations'] ?? 5) ?>" min="1" max="20">
          <div class="hint">How many tool-call cycles the agent can run per request before giving up.</div>
        </div>
        <div class="field">
          <label>Request Timeout (seconds)</label>
          <input type="number" id="timeout" value="<?= (int)(($existing['settings'] ?? [])['timeout'] ?? 25) ?>" min="5" max="120">
          <div class="hint">cURL timeout for LLM API calls. Keep below PHP max_execution_time.</div>
        </div>
      </div>

      <div class="btn-row">
        <button class="btn btn-primary" onclick="saveConfig(this)">
          Save Configuration
        </button>
        <button class="btn btn-ghost" onclick="goStep(4)">← Back</button>
      </div>
    </div>
  </div>

  <!-- ── Step 6: Cron & Deploy ── -->
  <div class="card" data-step="6">
    <div class="card-head">
      <div class="card-head-num">6</div>
      <div class="card-head-title">Cron &amp; Deploy</div>
      <div class="card-head-desc">Scheduler setup &amp; final checks</div>
    </div>
    <div class="card-body" id="step6-body">
      <p style="color:var(--muted2);font-size:12px;">Complete step 5 to generate your cron configuration.</p>
    </div>
  </div>

</div><!-- /shell -->

<script>
// ── State ──────────────────────────────────────────────────────────────────
var currentStep = 1;
var stepDone = {};
var savedData = {};

// ── Navigation ─────────────────────────────────────────────────────────────
function goStep(n) {
  document.querySelectorAll('.card').forEach(c => c.classList.remove('active'));
  document.querySelectorAll('.step-tab').forEach(t => {
    t.classList.remove('active');
    if (parseInt(t.dataset.step) < n && stepDone[t.dataset.step]) t.classList.add('done');
  });
  document.querySelector('.card[data-step="' + n + '"]').classList.add('active');
  document.querySelector('.step-tab[data-step="' + n + '"]').classList.add('active');
  currentStep = n;
  document.getElementById('progress').style.width = Math.round((n / 6) * 100) + '%';
  window.scrollTo({top: 0, behavior: 'smooth'});
  stepDone[n - 1] = true;
}

document.querySelectorAll('.step-tab').forEach(tab => {
  tab.addEventListener('click', function () { goStep(parseInt(this.dataset.step)); });
});

// ── Provider change ────────────────────────────────────────────────────────
function onProviderChange(v) {
  var urlMap   = {
    openai:    'https://api.openai.com/v1',
    anthropic: 'https://api.anthropic.com/v1',
    gemini:    'https://generativelanguage.googleapis.com/v1beta',
    ollama:    'http://localhost:11434/v1',
  };
  var modelMap = {
    openai:    'gpt-4o-mini',
    anthropic: 'claude-sonnet-4-20250514',
    gemini:    'gemini-2.0-flash',
    ollama:    'llama3',
  };
  document.getElementById('base_url').value = urlMap[v] || '';
  document.getElementById('model').value    = modelMap[v] || '';
  document.getElementById('ollama-hint').style.display = v === 'ollama' ? 'block' : 'none';
  document.getElementById('api_key').placeholder = v === 'gemini' ? 'AIza...' : (v === 'anthropic' ? 'sk-ant-...' : 'sk-...');
  var urlField = document.getElementById('field-base-url');
  urlField.style.display = v === 'gemini' ? 'none' : ''; // Gemini URL is fixed
}

// ── DB method ─────────────────────────────────────────────────────────────
function onDbMethod(v) {
  document.getElementById('db-manual-fields').classList.toggle('visible', v === 'manual');
  document.getElementById('db-wp-fields').classList.toggle('visible', v === 'wp');
  document.getElementById('test-db-btn').disabled = v === 'none';
}

// ── Email driver ──────────────────────────────────────────────────────────
function onEmailDriver(v) {
  document.getElementById('email-common').classList.toggle('visible', v !== 'none');
  document.getElementById('smtp-fields').classList.toggle('visible', v === 'smtp');
  document.getElementById('mailgun-fields').classList.toggle('visible', v === 'mailgun');
  document.getElementById('sendgrid-fields').classList.toggle('visible', v === 'sendgrid');
}

// ── Test LLM ──────────────────────────────────────────────────────────────
async function testLLM(btn) {
  var res = document.getElementById('llm-result');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Testing…';
  res.className = 'test-result';

  try {
    var r = await fetch('?action=test_llm', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        provider: document.querySelector('[name=provider]:checked').value,
        api_key:  document.getElementById('api_key').value,
        model:    document.getElementById('model').value,
        base_url: document.getElementById('base_url').value,
      }),
    });
    var d = await r.json();
    res.className = 'test-result visible ' + (d.ok ? 'ok' : 'err');
    res.textContent = d.message;
  } catch(e) {
    res.className = 'test-result visible err';
    res.textContent = 'Request failed: ' + e.message;
  }

  btn.disabled = false;
  btn.innerHTML = '⟳ Test Connection';
}

// ── Test Email ────────────────────────────────────────────────────────────
async function testEmail(btn) {
  var res = document.getElementById('email-result');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Testing…';
  res.className = 'test-result';

  var driver = document.querySelector('[name=email_driver]:checked')?.value || 'smtp';
  var payload = {
    email_driver: driver,
    from_addr:    document.getElementById('from_addr').value,
    smtp_host:    document.getElementById('smtp_host').value,
    smtp_port:    document.getElementById('smtp_port').value,
    smtp_enc:     document.getElementById('smtp_enc').value,
    smtp_user:    document.getElementById('smtp_user').value,
    smtp_pass:    document.getElementById('smtp_pass').value,
    mg_key:       document.getElementById('mg_key').value,
    mg_domain:    document.getElementById('mg_domain').value,
    mg_region:    document.querySelector('[name=mg_region]:checked')?.value || 'us',
    sg_key:       document.getElementById('sg_key').value,
  };

  try {
    var r = await fetch('?action=test_email', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    var d = await r.json();
    res.className = 'test-result visible ' + (d.ok ? 'ok' : 'err');
    res.textContent = d.message;
  } catch(e) {
    res.className = 'test-result visible err';
    res.textContent = 'Request failed: ' + e.message;
  }

  btn.disabled = false;
  btn.innerHTML = '⟳ Test Connection';
}

// ── Test DB ───────────────────────────────────────────────────────────────
async function testDB(btn) {
  var res = document.getElementById('db-result');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Testing…';
  res.className = 'test-result';

  var method = document.querySelector('[name=db_method]:checked').value;
  var payload = method === 'wp'
    ? {wp_config_path: document.getElementById('wp_config_path').value}
    : {host: document.getElementById('db_host').value, name: document.getElementById('db_name').value,
       user: document.getElementById('db_user').value, pass: document.getElementById('db_pass').value};

  try {
    var r = await fetch('?action=test_db', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    var d = await r.json();
    res.className = 'test-result visible ' + (d.ok ? 'ok' : 'err');
    res.textContent = d.message;
    document.getElementById('create-tables-btn').disabled = !d.ok;
  } catch(e) {
    res.className = 'test-result visible err';
    res.textContent = e.message;
  }

  btn.disabled = false;
  btn.innerHTML = '⟳ Test Connection';
}

// ── Create tables ─────────────────────────────────────────────────────────
async function createTables(btn) {
  var res = document.getElementById('tables-result');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Creating…';

  var method = document.querySelector('[name=db_method]:checked').value;
  var payload = method === 'wp'
    ? {wp_config_path: document.getElementById('wp_config_path').value}
    : {host: document.getElementById('db_host').value, name: document.getElementById('db_name').value,
       user: document.getElementById('db_user').value, pass: document.getElementById('db_pass').value};

  try {
    var r = await fetch('?action=create_tables', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    var d = await r.json();
    res.className = 'test-result visible ' + (d.ok ? 'ok' : 'err');
    res.textContent = d.message;
  } catch(e) {
    res.className = 'test-result visible err';
    res.textContent = e.message;
  }

  btn.disabled = false;
  btn.innerHTML = '+ Create Tables';
}

// ── Save config ───────────────────────────────────────────────────────────
async function saveConfig(btn) {
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Saving…';

  var method = document.querySelector('[name=db_method]:checked')?.value || 'none';
  var emailDriver = document.querySelector('[name=email_driver]:checked')?.value || 'smtp';

  var payload = {
    provider:       document.querySelector('[name=provider]:checked').value,
    api_key:        document.getElementById('api_key').value,
    model:          document.getElementById('model').value,
    base_url:       document.getElementById('base_url').value,
    max_iterations: document.getElementById('max_iterations').value,
    timeout:        document.getElementById('timeout').value,
    system_prompt:  document.getElementById('system_prompt').value,
    admin_password: document.getElementById('admin_password').value,
    email_driver:   emailDriver,
    from_name:      document.getElementById('from_name').value,
    from_addr:      document.getElementById('from_addr').value,
    smtp_host:      document.getElementById('smtp_host').value,
    smtp_port:      document.getElementById('smtp_port').value,
    smtp_enc:       document.getElementById('smtp_enc').value,
    smtp_user:      document.getElementById('smtp_user').value,
    smtp_pass:      document.getElementById('smtp_pass').value,
    mg_key:         document.getElementById('mg_key').value,
    mg_domain:      document.getElementById('mg_domain').value,
    mg_region:      document.querySelector('[name=mg_region]:checked')?.value || 'us',
    sg_key:         document.getElementById('sg_key').value,
  };

  if (method === 'wp') {
    payload.wp_config_path = document.getElementById('wp_config_path').value;
  } else if (method === 'manual') {
    payload.db_host      = document.getElementById('db_host').value;
    payload.db_name      = document.getElementById('db_name').value;
    payload.db_user      = document.getElementById('db_user').value;
    payload.db_pass      = document.getElementById('db_pass').value;
    payload.table_prefix = document.getElementById('table_prefix').value;
  }

  try {
    var r = await fetch('?action=save', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    var d = await r.json();

    if (d.ok) {
      savedData = d;
      renderStep6(d);
      goStep(6);
    } else {
      alert('Save failed: ' + d.message);
      btn.disabled = false;
      btn.innerHTML = 'Save Configuration';
    }
  } catch(e) {
    alert('Error: ' + e.message);
    btn.disabled = false;
    btn.innerHTML = 'Save Configuration';
  }
}

// ── Render step 6 ─────────────────────────────────────────────────────────
function renderStep6(d) {
  var host = window.location.origin + window.location.pathname.replace('setup.php', '');
  var dashUrl = host + 'dashboard.php';

  document.getElementById('step6-body').innerHTML = `
    <div style="margin-bottom:20px;">
      <div style="color:var(--green);font-size:13px;margin-bottom:8px;">✓ config.php written successfully</div>
      <div style="color:var(--muted2);font-size:11px;">Configuration saved. GarraPHP is ready.</div>
    </div>

    <hr class="divider">

    <div style="margin-bottom:20px;">
      <div style="font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted2);margin-bottom:10px;">
        Cron Job Setup — Add this to cPanel &gt; Cron Jobs
      </div>
      <div style="font-size:10px;color:var(--muted2);margin-bottom:6px;">Run every 5 minutes:</div>
      <div class="cron-box" id="cron-cmd-box">
        ${escHtml(d.cron_cmd)}
        <button class="copy-btn" onclick="copyText('cron-cmd-box')">Copy</button>
      </div>
      <div style="font-size:10px;color:var(--muted2);margin-top:6px;">Or call this URL directly:</div>
      <div class="cron-box" id="cron-url-box">
        ${escHtml(d.cron_url)}
        <button class="copy-btn" onclick="copyText('cron-url-box')">Copy</button>
      </div>
    </div>

    <hr class="divider">

    <div style="margin-bottom:20px;">
      <div style="font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted2);margin-bottom:10px;">
        Files to upload to public_html/
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
        ${['index.php','ui.php','cron.php','dashboard.php','setup.php (this file — delete after setup)'].map(f =>
          '<div style="padding:7px 10px;background:var(--surface2);border:1px solid var(--border);border-radius:2px;font-size:11px;color:var(--muted2);">' + f + '</div>'
        ).join('')}
      </div>
      <div style="margin-top:8px;font-size:10px;color:var(--muted2);">All other files (config.php, garra.php, drivers/, skills/) go in the parent garra/ folder.</div>
    </div>

    <div class="btn-row" style="margin-top:24px;">
      <a href="${dashUrl}" class="btn btn-success" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;"
         onclick="fetch('setup.php?action=logout',{method:'POST'})">
        → Open Dashboard
      </a>
      <button class="btn btn-ghost" onclick="goStep(5)">← Back to Agent Settings</button>
    </div>
  `;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function copyText(id) {
  var el = document.getElementById(id);
  var text = el.childNodes[0].textContent.trim();
  navigator.clipboard.writeText(text).then(() => {
    var btn = el.querySelector('.copy-btn');
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy', 1500);
  });
}
</script>

</body>
</html>
