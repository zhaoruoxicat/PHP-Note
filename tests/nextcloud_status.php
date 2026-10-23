<?php
declare(strict_types=1);
require dirname(__DIR__) . '/NextcloudAPI/Status.php';
$status = Shiji\Nextcloud\Status::payload(true);
foreach (['installed', 'maintenance', 'needsDbUpgrade', 'extendedSupport'] as $key) {
    if (!is_bool($status[$key])) throw new RuntimeException('Invalid status flag: ' . $key);
}
if (!$status['installed'] || $status['maintenance'] || $status['needsDbUpgrade']) throw new RuntimeException('Unexpected ready status');
if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/D', $status['version'])) throw new RuntimeException('Invalid compatibility version');
if (Shiji\Nextcloud\Status::payload(false)['installed']) throw new RuntimeException('Uninitialized site marked installed');
echo "PASS: status payload and initialization flags\n";
