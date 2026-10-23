<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use Shiji\{Notes, Uploads};
use function Shiji\{config, db, query};

try {
    Uploads::collect();
    $cutoff = time() - 86400;
    foreach (['temp', 'uploads'] as $directory) {
        foreach (new DirectoryIterator(Uploads::directory($directory)) as $file) {
            if (!$file->isFile() || $file->isLink() || $file->getMTime() > $cutoff || !preg_match('/^[a-f0-9]{48}$/D', $file->getFilename())) continue;
            if ($directory === 'uploads' && query('SELECT id FROM note_attachments WHERE storage_name = ?', [$file->getFilename()])->fetchColumn()) continue;
            @unlink($file->getPathname());
        }
    }
    // Sequence truncation advances an explicit low watermark. Old browsers must
    // rebaseline, including when the surviving event table is empty.
    Notes::transaction(function () {
        $cut = query('SELECT MAX(seq) FROM sync_events WHERE created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? DAY)', [config('sync_retention_days')])->fetchColumn();
        if ($cut !== null && $cut !== false) {
            query('DELETE FROM sync_events WHERE seq <= ?', [$cut]);
            query('UPDATE sync_state SET min_cursor = GREATEST(min_cursor, ?) WHERE id = 1', [$cut]);
        }
    });
    query('DELETE FROM rate_limits WHERE expires_at < UTC_TIMESTAMP(6)');
    query('DELETE FROM auth_sessions WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 30 DAY) OR revoked_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 30 DAY)');
    query('DELETE FROM anonymous_tokens WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 7 DAY)');
    echo "Cleanup completed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Cleanup failed: ' . get_class($error) . "\n");
    exit(1);
}
