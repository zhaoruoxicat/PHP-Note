<?php
declare(strict_types=1);
namespace Shiji;

final class Uploads
{
    private const MIME = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
        'gif' => ['image/gif'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf'],
        'txt' => ['text/plain'], 'md' => ['text/plain'], 'log' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'json' => ['application/json', 'text/plain'],
        'xml' => ['application/xml', 'text/xml', 'text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2'],
        'rar' => ['application/x-rar', 'application/vnd.rar'],
        '7z' => ['application/x-7z-compressed'], 'tar' => ['application/x-tar'],
        'gz' => ['application/gzip', 'application/x-gzip'],
    ];

    public static function directory(string $name): string
    {
        $path = rtrim(config('storage_path'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new HttpError(500, '无法创建文件存储目录。');
        return $path;
    }

    public static function stage(): array
    {
        $requestSize = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($requestSize > config('max_request_bytes') + 1024 * 1024) throw new HttpError(413, '上传总大小超过限制。');
        if ($requestSize > 0 && empty($_POST) && empty($_FILES) && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) throw new HttpError(413, '上传超过 PHP 请求大小限制。');
        $input = $_FILES['files'] ?? null;
        if (!$input) return [];
        if (!is_array($input['name'] ?? null) || count($input['name']) > config('max_attachments')) throw new HttpError(422, '附件数量超过限制。');
        $staged = [];
        $total = 0;
        try {
            foreach ($input['name'] as $i => $name) {
                if (!is_string($name) || !is_string($input['tmp_name'][$i] ?? null)) throw new HttpError(422, '附件格式无效。');
                if (($input['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new HttpError(422, '文件上传失败，请检查文件大小后重试。');
                $tmp = $input['tmp_name'][$i];
                if (!is_uploaded_file($tmp)) throw new HttpError(422, '附件来源无效。');
                $size = filesize($tmp);
                $total += $size;
                if ($size > config('max_file_bytes') || $total > config('max_request_bytes')) throw new HttpError(413, '附件大小超过限制。');
                $name = basename(str_replace('\\', '/', $name));
                if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new HttpError(422, '文件名无效。');
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
                if (!isset(self::MIME[$ext]) || !in_array($mime, self::MIME[$ext], true)) throw new HttpError(422, '不支持的文件类型或文件内容不匹配：' . $name);
                if (str_starts_with($mime, 'image/')) {
                    $info = @getimagesize($tmp);
                    if (!$info || $info['mime'] !== $mime || $info[0] * $info[1] > 40000000) throw new HttpError(422, '图片无效或像素过大。');
                }
                if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
                    if (!class_exists(\ZipArchive::class)) throw new HttpError(503, 'Office 文件校验需要 PHP zip 扩展。');
                    $zip = new \ZipArchive();
                    if ($zip->open($tmp) !== true) throw new HttpError(422, 'Office 文档无效。');
                    $entry = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$ext];
                    $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($entry) !== false;
                    $zip->close();
                    if (!$valid) throw new HttpError(422, 'Office 文件内容与扩展名不匹配。');
                }
                $storage = bin2hex(random_bytes(24));
                $path = self::directory('temp') . '/' . $storage;
                if (!move_uploaded_file($tmp, $path)) throw new HttpError(500, '保存上传文件失败。');
                $staged[] = ['name' => $name, 'size' => $size, 'mime' => $mime, 'extension' => $ext, 'storage' => $storage, 'path' => $path];
            }
            return $staged;
        } catch (\Throwable $e) {
            self::discard($staged);
            throw $e;
        }
    }

    public static function attach(int $message, array &$staged): void
    {
        foreach ($staged as &$file) {
            $path = self::directory('uploads') . '/' . $file['storage'];
            if (!rename($file['path'], $path)) throw new HttpError(500, '移动附件失败。');
            $file['path'] = $path;
            query('INSERT INTO note_attachments (message_id, original_name, storage_name, mime_type, file_size, file_extension) VALUES (?, ?, ?, ?, ?, ?)', [$message, $file['name'], $file['storage'], $file['mime'], $file['size'], $file['extension']]);
        }
    }

    public static function discard(array $files): void
    {
        foreach ($files as $file) if (is_file($file['path'])) @unlink($file['path']);
    }

    public static function collect(): void
    {
        try {
        $path = self::directory('uploads');
        foreach (query('SELECT storage_name FROM file_garbage LIMIT 100')->fetchAll() as $row) {
            $name = $row['storage_name'];
            if (!preg_match('/^[a-f0-9]{48}$/D', $name)) continue;
            if (!is_file($path . '/' . $name) || @unlink($path . '/' . $name)) query('DELETE FROM file_garbage WHERE storage_name = ?', [$name]);
        }
        } catch (\Throwable $error) {
            // The deletion already committed. Keep its durable queue for cron retry.
            error_log('Shiji file collection deferred: ' . get_class($error));
        }
    }
}
