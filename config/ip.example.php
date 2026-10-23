<?php
declare(strict_types=1);
// Copy to config/ip.php and set the token locally. Never commit real credentials.
return [
    'endpoint' => 'https://ip.example.com/ip.php',
    'token' => '', // YOUR_IP_API_TOKEN; leave empty to disable lookups.
    'timeout_seconds' => 2,
    'cache_seconds' => 604800,
];
