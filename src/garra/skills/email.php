<?php
/**
 * Skill: email
 * ============
 * Send email via SMTP (raw sockets), Mailgun, or SendGrid.
 * Driver is set in config.php email.driver.
 *
 * Actions:
 *   send — send an email
 *   test — send a test message to a given address to verify configuration
 */
if (!defined('GARRA_EXEC')) exit;

// ---------------------------------------------------------------------------
// Definition
// ---------------------------------------------------------------------------

function email_definition(): array
{
    return [
        'name'        => 'email',
        'description' => 'Send an email to one or more recipients via SMTP, Mailgun, or SendGrid. Supports plain text and HTML bodies. Use for notifications, alerts, summaries, or any automated messaging.',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'enum'        => ['send', 'test'],
                    'description' => '"send" sends the email. "test" sends a test message to verify config.',
                ],
                'to' => [
                    'type'        => 'string',
                    'description' => 'Recipient email address. For multiple, separate with commas.',
                ],
                'subject' => [
                    'type'        => 'string',
                    'description' => 'Email subject line.',
                ],
                'body' => [
                    'type'        => 'string',
                    'description' => 'Email body content. Plain text or HTML.',
                ],
                'html' => [
                    'type'        => 'boolean',
                    'description' => 'Set true if body contains HTML. Default false (plain text).',
                ],
                'reply_to' => [
                    'type'        => 'string',
                    'description' => 'Optional reply-to address.',
                ],
            ],
            'required' => ['action'],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Execution
// ---------------------------------------------------------------------------

function email_execute(array $args): array
{
    $action = $args['action'] ?? 'send';
    $config = email_load_config();

    if ($action === 'test') {
        $to = $args['to'] ?? $config['from_addr'];
        $args = [
            'action'  => 'send',
            'to'      => $to,
            'subject' => 'GarraPHP Email Test — ' . date('Y-m-d H:i:s'),
            'body'    => "This is a test email from GarraPHP.\n\nDriver: " . $config['driver'] . "\nSent: " . date('c'),
            'html'    => false,
        ];
    }

    if ($action !== 'send' && $action !== 'test') {
        return ['error' => "Unknown action '{$action}'."];
    }

    $to      = trim($args['to'] ?? '');
    $subject = trim($args['subject'] ?? '');
    $body    = trim($args['body'] ?? '');
    $isHtml  = (bool)($args['html'] ?? false);
    $replyTo = trim($args['reply_to'] ?? '');

    if (!$to)      return ['error' => 'to is required.'];
    if (!$subject) return ['error' => 'subject is required.'];
    if (!$body)    return ['error' => 'body is required.'];

    $recipients = array_map('trim', explode(',', $to));

    try {
        $driver = $config['driver'] ?? 'smtp';

        switch ($driver) {
            case 'mailgun':
                return email_send_mailgun($recipients, $subject, $body, $isHtml, $replyTo, $config);
            case 'sendgrid':
                return email_send_sendgrid($recipients, $subject, $body, $isHtml, $replyTo, $config);
            case 'smtp':
            default:
                return email_send_smtp($recipients, $subject, $body, $isHtml, $replyTo, $config);
        }
    } catch (RuntimeException $e) {
        return ['error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Drivers
// ---------------------------------------------------------------------------

/**
 * SMTP driver — uses PHP's raw socket functions.
 * No library required. Works with any standard SMTP server.
 */
function email_send_smtp(array $to, string $subject, string $body, bool $html, string $replyTo, array $config): array
{
    $smtp = $config['smtp'] ?? [];
    $host = $smtp['host'] ?? '';
    $port = (int)($smtp['port'] ?? 587);
    $enc  = strtolower($smtp['encryption'] ?? 'tls');
    $user = $smtp['username'] ?? '';
    $pass = $smtp['password'] ?? '';

    if (!$host) throw new RuntimeException('SMTP host not configured.');

    $fromAddr = $config['from_addr'] ?? '';
    $fromName = $config['from_name'] ?? 'GarraPHP';

    // Build raw message
    $boundary = 'GarraBoundary_' . md5(uniqid());
    $headers  = [
        'From'         => "{$fromName} <{$fromAddr}>",
        'To'           => implode(', ', $to),
        'Subject'      => $subject,
        'MIME-Version' => '1.0',
        'Date'         => date('r'),
        'Message-ID'   => '<' . uniqid('garra') . '@' . parse_url('http://' . $host, PHP_URL_HOST) . '>',
    ];

    if ($replyTo) $headers['Reply-To'] = $replyTo;

    if ($html) {
        $headers['Content-Type'] = "multipart/alternative; boundary=\"{$boundary}\"";
        $rawBody = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
                 . strip_tags($body)
                 . "\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
                 . $body
                 . "\r\n--{$boundary}--";
    } else {
        $headers['Content-Type']              = 'text/plain; charset=UTF-8';
        $headers['Content-Transfer-Encoding'] = '8bit';
        $rawBody = $body;
    }

    $headerStr = '';
    foreach ($headers as $k => $v) $headerStr .= "{$k}: {$v}\r\n";
    $message = $headerStr . "\r\n" . $rawBody;

    // Open socket
    $socketHost = ($enc === 'ssl') ? "ssl://{$host}" : $host;
    $sock = @fsockopen($socketHost, $port, $errno, $errstr, 15);
    if (!$sock) throw new RuntimeException("SMTP connect failed [{$errno}]: {$errstr}");

    stream_set_timeout($sock, 15);

    $read = fn() => fgets($sock, 512);
    $send = function(string $cmd) use ($sock, $read): string {
        fputs($sock, $cmd . "\r\n");
        return $read();
    };

    $read(); // banner

    // EHLO
    //$ehloResp = $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

    // STARTTLS upgrade
    if ($enc === 'tls') {
        $send('STARTTLS');
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS negotiation failed.');
        }
        $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    }
    else
    {
        // EHLO
        $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        // Read the remaining lines of the 250- response
        while ($line = fgets($sock)) {
            if (str_starts_with($line, '250 ')) break; // End of multi-line response
        }
    }

    // Auth
    if ($user && $pass) {
        $send('AUTH LOGIN');
        
        // Send Username
        $userResp = $send(base64_encode($user));
        if (!str_starts_with($userResp, '334')) {
            throw new RuntimeException('SMTP Auth Username rejected: ' . trim($userResp));
        }

        // Send Password
        $authResp = $send(base64_encode($pass));
        if (!str_starts_with($authResp, '235')) {
            throw new RuntimeException('SMTP Authentication failed: ' . trim($authResp));
        }
    }
    $send("MAIL FROM: <{$fromAddr}>");

    foreach ($to as $recipient) {
        $rcptResp = $send("RCPT TO: <{$recipient}>");
        if (!str_starts_with($rcptResp, '250')) {
            throw new RuntimeException("RCPT TO rejected for {$recipient}: " . trim($rcptResp));
        }
    }

    $send('DATA');
    fputs($sock, $message . "\r\n.\r\n");
    $dataResp = $read();
    $send('QUIT');
    fclose($sock);

    if (!str_starts_with($dataResp, '250')) {
        throw new RuntimeException('SMTP DATA rejected: ' . trim($dataResp));
    }

    return ['success' => true, 'driver' => 'smtp', 'to' => $to, 'subject' => $subject];
}

/**
 * Mailgun driver — HTTP API, no library needed.
 */
function email_send_mailgun(array $to, string $subject, string $body, bool $html, string $replyTo, array $config): array
{
    $mg     = $config['mailgun'] ?? [];
    $apiKey = $mg['api_key'] ?? '';
    $domain = $mg['domain'] ?? '';
    $region = strtolower($mg['region'] ?? 'us');

    if (!$apiKey || !$domain) throw new RuntimeException('Mailgun api_key and domain are required.');

    $base = $region === 'eu'
        ? 'https://api.eu.mailgun.net/v3'
        : 'https://api.mailgun.net/v3';

    $fromAddr = $config['from_addr'] ?? '';
    $fromName = $config['from_name'] ?? 'GarraPHP';

    $fields = [
        'from'    => "{$fromName} <{$fromAddr}>",
        'to'      => implode(',', $to),
        'subject' => $subject,
    ];

    if ($html) {
        $fields['html'] = $body;
        $fields['text'] = strip_tags($body);
    } else {
        $fields['text'] = $body;
    }

    if ($replyTo) $fields['h:Reply-To'] = $replyTo;

    $ch = curl_init("{$base}/{$domain}/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_USERPWD        => "api:{$apiKey}",
        CURLOPT_TIMEOUT        => 15,
    ]);

    $raw  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resp = json_decode($raw, true);

    if ($http !== 200) {
        throw new RuntimeException('Mailgun error: ' . ($resp['message'] ?? $raw));
    }

    return ['success' => true, 'driver' => 'mailgun', 'to' => $to, 'id' => $resp['id'] ?? null];
}

/**
 * SendGrid driver — HTTP API v3.
 */
function email_send_sendgrid(array $to, string $subject, string $body, bool $html, string $replyTo, array $config): array
{
    $sg     = $config['sendgrid'] ?? [];
    $apiKey = $sg['api_key'] ?? '';

    if (!$apiKey) throw new RuntimeException('SendGrid api_key is required.');

    $fromAddr = $config['from_addr'] ?? '';
    $fromName = $config['from_name'] ?? 'GarraPHP';

    $content = $html
        ? [['type' => 'text/html', 'value' => $body], ['type' => 'text/plain', 'value' => strip_tags($body)]]
        : [['type' => 'text/plain', 'value' => $body]];

    $payload = [
        'personalizations' => [[
            'to' => array_map(fn($e) => ['email' => $e], $to),
        ]],
        'from'    => ['email' => $fromAddr, 'name' => $fromName],
        'subject' => $subject,
        'content' => $content,
    ];

    if ($replyTo) $payload['reply_to'] = ['email' => $replyTo];

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $raw  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http < 200 || $http >= 300) {
        $resp = json_decode($raw, true);
        $msg  = $resp['errors'][0]['message'] ?? $raw;
        throw new RuntimeException("SendGrid error (HTTP {$http}): {$msg}");
    }

    return ['success' => true, 'driver' => 'sendgrid', 'to' => $to];
}

// ---------------------------------------------------------------------------
// Config loader
// ---------------------------------------------------------------------------

function email_load_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $file = __DIR__ . '/../config.php';
    if (!file_exists($file)) throw new RuntimeException('config.php not found.');

    $full = require $file;
    $cfg  = $full['email'] ?? [];

    return $cfg;
}
