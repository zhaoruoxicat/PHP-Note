<?php
declare(strict_types=1);

namespace Shiji;

ini_set('display_errors', '0');

use PDO;
use RuntimeException;
use Throwable;

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly string $errorCode = 'request_failed')
    {
        parent::__construct($message);
    }
}

function config(?string $key = null): mixed
{
    static $config;
    if ($config === null) {
        $path = getenv('SHIJI_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
        if (!is_file($path)) {
            throw new HttpError(503, '请访问 install.php 填写数据库信息并初始化站点。', 'not_configured');
        }
        $config = require $path;
        if (!is_array($config) || strlen($config['app_key'] ?? '') < 32 || str_contains($config['app_key'] ?? '', 'CHANGE_ME')) {
            throw new HttpError(503, '请配置至少 32 字符的随机应用密钥。', 'invalid_config');
        }
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $c = config('database');
        $pdo = new PDO($c['dsn'], $c['username'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if (str_contains($version, 'MariaDB') || version_compare($version, '8.0.0', '<')) {
            throw new HttpError(503, '需要 MySQL 8.0 或更高版本。', 'database_version');
        }
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    return $pdo;
}

function query(string $sql, array $params = []): \PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function secureHeaders(bool $anonymous = false): void
{
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: ' . ($anonymous ? 'no-referrer' : 'same-origin'));
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; font-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
}

function requireTransport(): void
{
    if (PHP_VERSION_ID < 80400) {
        throw new HttpError(503, '需要 PHP 8.4 或更高版本。', 'php_version');
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (!$https && !config('allow_http')) {
        throw new HttpError(503, '站点必须通过 HTTPS 访问。', 'https_required');
    }
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    requireTransport();
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) persistentSeconds());
    session_name('shiji_session');
    session_set_cookie_params(['lifetime' => persistentSeconds(), 'path' => basePath() . '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function persistentSeconds(): int
{
    return max(3600, min(31536000, (int) (config('remember_seconds') ?? 2592000)));
}

function authCookie(string $name, string $value, int $seconds): void
{
    setcookie($name, $value, ['expires' => time() + $seconds, 'path' => basePath() . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
    if ($seconds < 0) unset($_COOKIE[$name]); else $_COOKIE[$name] = $value;
}

function basePath(): string
{
    return rtrim((string) parse_url((string) config('site_url'), PHP_URL_PATH), '/');
}

function csrf(): string
{
    startSession();
    return $_SESSION['csrf'];
}

function checkCsrf(): void
{
    startSession();
    $value = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '';
    if (!is_string($value) || !hash_equals($_SESSION['csrf'], $value)) {
        throw new HttpError(403, '请求校验失败，请刷新页面重试。', 'csrf_failed');
    }
}

function input(): array
{
    if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
            throw new HttpError(413, '请求内容过大。');
        }
        try {
            $data = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(400, 'JSON 格式无效。');
        }
        if (!is_array($data)) {
            throw new HttpError(400, '请求格式无效。');
        }
        return $data;
    }
    return $_POST;
}

function id(mixed $value): int
{
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value)) {
        throw new HttpError(400, '无效的记录编号。');
    }
    return (int) $value;
}

function stringInput(array $data, string $key, int $max, bool $trim = true): string
{
    if (!is_string($data[$key] ?? null)) {
        throw new HttpError(422, '缺少有效的字段：' . $key);
    }
    $value = $trim ? trim($data[$key]) : $data[$key];
    if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max) {
        throw new HttpError(422, '字段格式或长度无效：' . $key);
    }
    return $value;
}

function jsonResponse(mixed $data = null, int $status = 200, string $message = ''): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function reportError(Throwable $error, bool $json = true): never
{
    $status = $error instanceof HttpError ? $error->status : 500;
    $message = $error instanceof HttpError ? $error->getMessage() : '服务器暂时无法处理请求，请检查服务端日志。';
    if (!$error instanceof HttpError) {
        // Keep diagnostic codes without logging SQL text, parameters or credentials.
        $details = '';
        if ($error instanceof \PDOException) {
            $state = (string) ($error->errorInfo[0] ?? $error->getCode());
            $driver = (string) ($error->errorInfo[1] ?? 'unknown');
            $details = ' SQLSTATE=' . preg_replace('/[^A-Z0-9]/i', '', $state)
                . ' driver=' . preg_replace('/[^0-9a-z]/i', '', $driver);
        }
        error_log('Shiji error: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . $details);
    }
    http_response_code($status);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message, 'error' => $error instanceof HttpError ? $error->errorCode : 'server_error'], JSON_UNESCAPED_UNICODE);
    } else {
        echo '<!doctype html><html lang="zh-CN"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>拾记</title><link rel="stylesheet" href="style/tabler.min.css"><body><main class="container py-5"><div class="alert alert-danger">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div></main></body></html>';
    }
    exit;
}

function rateLimit(string $scope, string $identity, int $limit, int $seconds): void
{
    $key = hash_hmac('sha256', $scope . ':' . $identity, config('app_key'));
    query('INSERT INTO rate_limits (rate_key, hits, expires_at) VALUES (?, 1, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE hits = IF(expires_at <= UTC_TIMESTAMP(6), 1, hits + 1), expires_at = IF(expires_at <= UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), expires_at)', [$key, $seconds, $seconds]);
    $hits = (int) query('SELECT hits FROM rate_limits WHERE rate_key = ?', [$key])->fetchColumn();
    if ($hits > $limit) {
        header('Retry-After: ' . $seconds);
        throw new HttpError(429, '请求过于频繁，请稍后重试。', 'rate_limited');
    }
}

function ip(): string
{
    return ClientIp::resolve($_SERVER, config('cloudflare_real_ip') !== false);
}

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/ClientIp.php';
require_once __DIR__ . '/IpLocation.php';
require_once __DIR__ . '/Uploads.php';
require_once __DIR__ . '/Notes.php';
require_once __DIR__ . '/Tokens.php';
