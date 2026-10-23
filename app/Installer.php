<?php
declare(strict_types=1);
namespace Shiji;

use PDO;
use PDOException;

final class Installer
{
    public static function path(): string
    {
        return getenv('SHIJI_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
    }

    public static function environment(): void
    {
        if (PHP_VERSION_ID < 80400) throw new HttpError(503, '需要 PHP 8.4 或更高版本。');
        foreach (['pdo_mysql', 'fileinfo', 'mbstring', 'json', 'openssl', 'session'] as $extension) {
            if (!extension_loaded($extension)) throw new HttpError(503, '缺少 PHP 扩展：' . $extension);
        }
        if (!function_exists('link')) throw new HttpError(503, '服务器需允许 PHP link 函数，以安全发布配置文件。');
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        if (!$https && !$local) throw new HttpError(503, '请通过 HTTPS 打开安装页面。');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name('shiji_install');
            $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/install.php')), '/.');
            session_set_cookie_params(['lifetime' => 0, 'path' => $path . '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
            session_start();
        }
        $_SESSION['install_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function existing(): ?array
    {
        if (!is_file(self::path())) return null;
        $config = require self::path();
        if (!is_array($config) || !is_array($config['database'] ?? null)) throw new HttpError(503, '现有配置格式无效，请检查服务器上的配置文件。');
        return $config;
    }

    public static function connection(array $config): PDO
    {
        try {
            $settings = $config['database'];
            $pdo = new PDO($settings['dsn'], $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
        } catch (PDOException) {
            throw new HttpError(422, '数据库连接失败，请检查地址、端口、数据库名、账号密码及数据库用户权限。');
        }
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if (str_contains($version, 'MariaDB') || version_compare($version, '8.0.0', '<')) throw new HttpError(422, '需要 MySQL 8.0 或更高版本，不支持 MariaDB。');
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    public static function initialized(array $config, ?PDO $pdo = null): bool
    {
        if (is_file(dirname(self::path()) . '/installed.lock') || is_file(rtrim($config['storage_path'], '/') . '/installed.lock')) return true;
        $pdo ??= self::connection($config);
        try { return (bool) $pdo->query('SELECT id FROM users LIMIT 1')->fetchColumn(); }
        catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1146) return false;
            throw $error;
        }
    }

    public static function settings(array $data): array
    {
        $host = stringInput($data, 'db_host', 255);
        $port = stringInput($data, 'db_port', 5);
        $name = stringInput($data, 'db_name', 64);
        if (!preg_match('/^[A-Za-z0-9_.:\-]+$/D', $host) || !preg_match('/^[0-9]{1,5}$/D', $port) || (int) $port < 1 || (int) $port > 65535 || !preg_match('/^[A-Za-z0-9_-]+$/D', $name)) throw new HttpError(422, '数据库地址、端口或数据库名格式无效。');
        $username = stringInput($data, 'db_user', 100);
        if ($username === '') throw new HttpError(422, '数据库用户名不能为空。');
        $password = stringInput($data, 'db_password', 1024, false);
        $url = rtrim(stringInput($data, 'site_url', 2048), '/');
        $parts = parse_url($url);
        if (!$parts || !filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new HttpError(422, '站点地址必须是完整的 HTTP/HTTPS 地址，不能含账号、查询参数或片段。');
        $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        if ($parts['scheme'] !== 'https' && !($local && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true))) throw new HttpError(422, '生产站点地址必须使用 HTTPS。');
        $timezone = stringInput($data, 'timezone', 100);
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) throw new HttpError(422, '请选择有效的站点时区。');
        $storage = stringInput($data, 'storage_path', 4096);
        if ($storage === '' || $storage === '/' || !str_starts_with($storage, '/') || str_contains($storage, "\0") || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $storage)) throw new HttpError(422, '附件存储目录必须是有效的服务器绝对路径，不能包含相对路径片段。');
        $config = require dirname(__DIR__) . '/config/config.example.php';
        $config['database'] = ['dsn' => 'mysql:host=' . $host . ';port=' . (int) $port . ';dbname=' . $name . ';charset=utf8mb4', 'username' => $username, 'password' => $password];
        $config['site_url'] = $url; $config['timezone'] = $timezone;
        $config['storage_path'] = rtrim($storage, '/');
        $config['app_key'] = bin2hex(random_bytes(32));
        $config['install_key'] = bin2hex(random_bytes(32));
        $config['allow_http'] = $parts['scheme'] === 'http';
        $days = $data['remember_days'] ?? '30';
        if (!is_string($days) || !preg_match('/^[0-9]{1,3}$/D', $days) || (int) $days < 1 || (int) $days > 365) throw new HttpError(422, '保持登录天数必须为 1 至 365 天。');
        $config['remember_seconds'] = (int) $days * 86400;
        $config['cloudflare_real_ip'] = ($data['cloudflare_real_ip'] ?? '1') === '1';
        return $config;
    }

    public static function ipSettings(array $data): array
    {
        $endpoint = stringInput($data + ['ip_endpoint' => 'https://ip.example.com/ip.php'], 'ip_endpoint', 2048);
        $token = stringInput($data + ['ip_token' => ''], 'ip_token', 1024);
        $parts = parse_url($endpoint);
        if (!$parts || !filter_var($endpoint, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new HttpError(422, 'IP 服务地址必须是 HTTPS 地址，不能包含令牌、查询参数或账号。');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $token)) throw new HttpError(422, 'IP 服务令牌不能包含控制字符。');
        return ['endpoint' => $endpoint, 'token' => $token, 'timeout_seconds' => 2, 'cache_seconds' => 604800];
    }

    public static function source(array $config): string
    {
        // Submitted values are quoted as PHP data, never inserted as executable code.
        return "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    }

    public static function install(array $data): void
    {
        $nonce = $data['csrf'] ?? '';
        if (!is_string($nonce) || !hash_equals($_SESSION['install_csrf'] ?? '', $nonce) || $nonce === '') throw new HttpError(403, '请求校验失败，请刷新安装页面后重试。');
        $directory = dirname(self::path());
        if (!is_dir($directory) || !is_writable($directory)) throw new HttpError(422, '配置目录不可写，请通过主机面板允许 PHP 写入：' . $directory);
        $mutex = fopen($directory . '/.install-mutex', 'c');
        if (!$mutex || !flock($mutex, LOCK_EX | LOCK_NB)) {
            if ($mutex) fclose($mutex);
            throw new HttpError(409, '另一个安装请求正在处理，请稍后重试。');
        }
        $pdo = null; $temporary = null; $published = false; $committed = false; $dbLocked = false;
        $ipPath = dirname(__DIR__) . '/config/ip.php';
        $ipTemporary = null; $ipPublished = false;
        $pendingPath = $directory . '/.install-pending';
        try {
            $existing = self::existing();
            $config = $existing ?? self::settings($data);
            $ipConfig = self::ipSettings($data);
            if (!is_file($ipPath)) {
                $ipDirectory = dirname($ipPath);
                if (!is_writable($ipDirectory)) throw new HttpError(422, 'IP 配置目录不可写。');
                $ipTemporary = tempnam($ipDirectory, '.install-ip-');
                if (!$ipTemporary || !chmod($ipTemporary, 0600)) throw new HttpError(422, '无法准备安全的 IP 配置文件。');
                $ipSource = self::source($ipConfig);
                if (file_put_contents($ipTemporary, $ipSource, LOCK_EX) !== strlen($ipSource)) throw new HttpError(422, '无法写入 IP 配置文件。');
            }
            $pdo = self::connection($config);
            if (self::initialized($config, $pdo)) throw new HttpError(403, '站点已初始化，安装入口已锁定。');
            if ($existing) {
                $key = stringInput($data, 'install_key', 200, false);
                if (strlen($existing['install_key'] ?? '') < 32 || !hash_equals($existing['install_key'], $key)) throw new HttpError(403, '现有配置的安装密钥不正确。');
            }
            $username = stringInput($data, 'username', 100);
            $password = stringInput($data, 'password', 1024, false);
            $confirm = stringInput($data, 'password_confirm', 1024, false);
            if ($username === '' || mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) throw new HttpError(422, '用户名不能为空，管理员密码至少 12 字符、最多 72 字节，不能含空字节。');
            if (!hash_equals($password, $confirm)) throw new HttpError(422, '两次输入的管理员密码不一致。');
            $storage = rtrim($config['storage_path'], '/');
            if ((!is_dir($storage) && !@mkdir($storage, 0700, true)) || !is_writable($storage)) throw new HttpError(422, '附件目录无法创建或不可写，请检查目录权限。');
            $resolved = realpath($storage);
            $webRoot = realpath(dirname(__DIR__));
            $protected = $webRoot . '/storage';
            if (($resolved === $webRoot || str_starts_with($resolved, $webRoot . '/')) && !($resolved === $protected || str_starts_with($resolved, $protected . '/'))) throw new HttpError(422, '网站目录内只允许使用受保护的 storage 目录；其他附件目录请放在网站根目录外。');
            foreach ([$storage . '/uploads', $storage . '/temp'] as $path) {
                if ((!is_dir($path) && !@mkdir($path, 0700, true)) || !is_writable($path)) throw new HttpError(422, '附件目录无法创建或不可写，请检查目录权限。');
            }
            if (!$existing) {
                $temporary = tempnam($directory, '.install-');
                if (!$temporary || !chmod($temporary, 0600)) throw new HttpError(422, '无法准备安全的配置文件，请检查配置目录权限。');
                $source = self::source($config);
                if (file_put_contents($temporary, $source, LOCK_EX) !== strlen($source)) throw new HttpError(422, '写入配置文件失败。');
            }
            if ((int) $pdo->query("SELECT GET_LOCK('shiji_install', 10)")->fetchColumn() !== 1) throw new HttpError(409, '数据库正在初始化，请稍后重试。');
            $dbLocked = true;
            if (self::initialized($config, $pdo)) throw new HttpError(403, '站点已初始化，安装入口已锁定。');
            if (!$existing) {
                $fingerprint = hash('sha256', $config['database']['dsn'] . "\0" . $config['database']['username']);
                $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                $owned = ['users', 'note_groups', 'note_messages', 'note_attachments', 'auth_sessions', 'anonymous_tokens', 'sync_state', 'sync_events', 'rate_limits', 'file_garbage', 'nextcloud_note_meta', 'nextcloud_login_flows', 'browser_devices', 'login_audit', 'ip_geo_cache'];
                $resume = is_file($pendingPath) && hash_equals($fingerprint, trim((string) file_get_contents($pendingPath)));
                if ($tables && (!$resume || array_diff($tables, $owned))) throw new HttpError(422, '自动安装需要空数据库，请创建独立的空数据库，避免覆盖已有数据。');
                if (!$resume && file_put_contents($pendingPath, $fingerprint, LOCK_EX) !== strlen($fingerprint)) throw new HttpError(422, '无法记录安装进度，请检查配置目录权限。');
            }
            foreach (explode(';', file_get_contents(dirname(__DIR__) . '/database/install.sql')) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
            $pdo->beginTransaction();
            if ($pdo->query('SELECT id FROM users LIMIT 1')->fetchColumn()) throw new HttpError(403, '站点已初始化。');
            $insert = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
            $insert->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $insertGroup = $pdo->prepare('INSERT INTO note_groups (name) VALUES (?)');
            foreach (['临时文件', '长期记录', '代码片段', '工作记录'] as $name) $insertGroup->execute([$name]);
            $pdo->exec("INSERT INTO note_groups (name, group_type) VALUES ('匿名收件箱', 'anonymous')");
            if ($temporary) {
                // Publishing a completed temporary inode never overwrites existing config.
                if (!@link($temporary, self::path())) throw new HttpError(409, '无法发布配置文件：文件已存在，或服务器不支持此目录的安全写入。');
                $published = true;
            }
            if ($ipTemporary) {
                if (!@link($ipTemporary, $ipPath)) throw new HttpError(409, '无法发布 IP 配置文件，请检查目录权限或已有配置。');
                $ipPublished = true;
            }
            $pdo->commit(); $committed = true;
            if (!$existing && is_file($pendingPath)) @unlink($pendingPath);
            foreach ([$directory . '/installed.lock', $storage . '/installed.lock'] as $lockPath) {
                $handle = @fopen($lockPath, 'x');
                if ($handle) { fwrite($handle, gmdate('c')); fclose($handle); }
            }
            $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
        } catch (PDOException $error) {
            error_log('Shiji installation database failure: ' . ($error->errorInfo[1] ?? 'unknown'));
            throw new HttpError(422, '数据库初始化失败，请确认数据库账号具有建表、索引及数据读写权限。');
        } finally {
            if ($pdo?->inTransaction()) $pdo->rollBack();
            if ($published && !$committed) @unlink(self::path());
            if ($ipPublished && !$committed) @unlink($ipPath);
            if ($ipTemporary && is_file($ipTemporary)) @unlink($ipTemporary);
            if ($temporary && is_file($temporary)) @unlink($temporary);
            if ($dbLocked) $pdo->query("SELECT RELEASE_LOCK('shiji_install')");
            flock($mutex, LOCK_UN); fclose($mutex);
        }
    }
}
