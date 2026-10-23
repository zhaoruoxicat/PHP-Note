<?php
declare(strict_types=1);
// Local PHP built-in server only. Production uses Apache/Nginx access rules.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('~^/(?:index\.php/)?login/v2(?:/poll|/flow/[a-f0-9]{64})?/?$~D', $path)) {
    require __DIR__ . '/NextcloudAPI/index.php'; return true;
}
if (preg_match('~^/(?:(?:NextcloudAPI/)?status\.php|(?:index\.php/)?apps/notes/api/v(?:1|0\.2)/(?:notes(?:/[1-9][0-9]*)?|settings)/?|ocs/v[12]\.php/cloud/capabilities/?|NextcloudAPI/(?:index\.php)?)$~D', $path)) {
    require __DIR__ . '/NextcloudAPI/index.php'; return true;
}
if (str_contains($path, '..') || !(preg_match('~^/(?:index|login|install|api|attachment|anonymous)\.php$~D', $path) || preg_match('~^/style/[a-zA-Z0-9_.-]+\.(?:css|js)$~D', $path) || $path === '/' || $path === '/demo.html' || preg_match('~^/[0-9]{6}/?$~D', $path))) {
    http_response_code(403); exit('Forbidden');
}
if (preg_match('~^/([0-9]{6})/?$~D', $path, $matches)) {
    $_GET['code'] = $matches[1]; require __DIR__ . '/anonymous.php'; return true;
}
return false;
