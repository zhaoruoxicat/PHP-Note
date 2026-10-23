<?php
declare(strict_types=1);

return [
    'database' => [
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=shiji;charset=utf8mb4',
        'username' => 'shiji',
        'password' => 'CHANGE_ME',
    ],
    'site_url' => 'https://note.example.com',
    'timezone' => 'Asia/Shanghai',
    // Generate distinct secrets with: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    'app_key' => 'CHANGE_ME_TO_RANDOM_SECRET',
    'install_key' => 'CHANGE_ME_TO_ANOTHER_RANDOM_SECRET',
    'storage_path' => dirname(__DIR__) . '/storage',
    'session_seconds' => 28800,
    'remember_seconds' => 30 * 86400,
    'poll_seconds' => 5,
    'cloudflare_real_ip' => true,
    'max_file_bytes' => 10 * 1024 * 1024,
    'max_request_bytes' => 20 * 1024 * 1024,
    'max_attachments' => 5,
    'max_text_length' => 100000,
    'anonymous_max_submissions' => 20,
    'anonymous_max_bytes' => 50 * 1024 * 1024,
    'sync_retention_days' => 14,
    // Production must be HTTPS. Only enable for localhost development.
    'allow_http' => false,
];
