<?php
/**
 * GarraPHP Configuration
 * ----------------------
 * Keep this file outside public_html or rely on the GARRA_EXEC guard + .htaccess.
 */
if (!defined('GARRA_EXEC')) exit;

return [

    'provider' => 'openai',
    'model'    => 'gpt-4o-mini',
    'api_key'  => 'your-openai-key-here',
    'base_url' => 'https://api.openai.com/v1',

    'settings' => [
        'max_iterations' => 5,
        'timeout'        => 25,
        'skills_dir'     => __DIR__ . '/skills',
        'storage_dir'    => __DIR__ . '/storage',
    ],

    'auth' => [
        'enabled'     => false,
        'ui_exempt'   => true,
        'ping_exempt' => true,
        'keys' => [
            // 'your-secret-key-here' => ['label' => 'WordPress', 'rate_limit' => 60],
        ],
    ],

    'rate_limit' => [
        'enabled' => false,
        'window'  => 60,
        'limit'   => 20,
        'backend' => 'file',
    ],

    'database' => [
        'wp_config_path' => null,
        'host'           => 'localhost',
        'name'           => 'your_db_name',
        'user'           => 'your_db_user',
        'pass'           => 'your_db_password',
        'charset'        => 'utf8mb4',
        'table_prefix'   => 'wp_',
        'readonly'       => false,
    ],

    'email' => [
        'driver'    => 'smtp',
        'from_name' => 'GarraPHP Agent',
        'from_addr' => 'agent@yourdomain.com',
        'smtp' => [
            'host'       => 'mail.yourdomain.com',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => 'agent@yourdomain.com',
            'password'   => 'your-smtp-password',
        ],
        'mailgun' => [
            'api_key' => 'key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'domain'  => 'mg.yourdomain.com',
            'region'  => 'us',
        ],
        'sendgrid' => [
            'api_key' => 'SG.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        ],
    ],

    'heartbeat' => [
        'targets'         => [],
        'timeout'         => 10,
        'alert_threshold' => 2000,
        'history_limit'   => 100,
    ],

    'scheduler' => [
        'cron_secret' => 'change-this-to-a-random-secret',
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

];
