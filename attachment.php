<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
use Shiji\{Auth, HttpError, Uploads};
use function Shiji\{secureHeaders, query, id, reportError};

secureHeaders();
try {
    Auth::user();
    $file = query('SELECT * FROM note_attachments WHERE id = ?', [id($_GET['id'] ?? null)])->fetch();
    if (!$file || !preg_match('/^[a-f0-9]{48}$/D', $file['storage_name'])) throw new HttpError(404, '附件不存在或已删除。');
    $path = Uploads::directory('uploads') . '/' . $file['storage_name'];
    $handle = @fopen($path, 'rb');
    if (!$handle) throw new HttpError(404, '附件文件不存在。');
    $preview = ($_GET['preview'] ?? '') === '1' && str_starts_with($file['mime_type'], 'image/');
    header('Content-Type: ' . ($preview ? $file['mime_type'] : 'application/octet-stream'));
    header('Content-Length: ' . (string) fstat($handle)['size']);
    header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="attachment.' . $file['file_extension'] . '"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
    session_write_close();
    fpassthru($handle);
    fclose($handle);
} catch (Throwable $error) {
    reportError($error, false);
}
