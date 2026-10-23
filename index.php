<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
use Shiji\{Auth, HttpError};
use function Shiji\{secureHeaders, reportError};

// Handle compatibility APIs when PHP PATH_INFO/front-controller fallback reaches us.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
try {
    $localPath = substr($requestPath, strlen(Shiji\basePath()));
    if (preg_match('~^/(?:index\.php/)?(?:login/v2(?:/poll|/flow/[a-f0-9]{64})?|apps/notes/api/v(?:1|0\.2)/(?:notes(?:/[1-9][0-9]*)?|settings))/?$~D', $localPath)) {
        require __DIR__ . '/NextcloudAPI/index.php';
        exit;
    }
} catch (Throwable $error) { reportError($error, false); }

secureHeaders();
try {
    Auth::user();
    $view = __DIR__ . '/app/views/main.php';
    if (!is_readable($view)) {
        throw new HttpError(503, '首页模板缺失或不可读，请上传 app/views/main.php 并检查文件权限。', 'view_missing');
    }
    require $view;
} catch (HttpError $error) {
    if ($error->status === 401) {
        header('Location: ' . Shiji\basePath() . '/login.php');
        exit;
    }
    reportError($error, false);
} catch (Throwable $error) {
    reportError($error, false);
}
