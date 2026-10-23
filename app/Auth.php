<?php
declare(strict_types=1);
namespace Shiji;

final class Auth
{
    public static function apiCredentials(string $username, string $password): array
    {
        if (preg_match('/^nc_[a-f0-9]{64}$/D', $password)) {
            $user = query('SELECT u.*, s.id AS device_id FROM auth_sessions s JOIN users u ON u.id = s.user_id WHERE u.username = ? AND s.session_key_hash = ? AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP(6) AND s.session_version = u.session_version', [$username, hash('sha256', $password)])->fetch();
            if ($user) {
                query('UPDATE auth_sessions SET last_seen_at = UTC_TIMESTAMP(6), last_ip = ? WHERE id = ?', [ip(), $user['device_id']]);
                return $user;
            }
        }
        return self::credentials($username, $password);
    }

    public static function credentials(string $username, string $password): array
    {
        $user = query('SELECT * FROM users WHERE username = ?', [$username])->fetch();
        $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if (!password_verify($password, $user['password_hash'] ?? $dummy) || !$user) {
            throw new HttpError(401, '用户名或密码不正确。', 'login_failed');
        }
        return $user;
    }

    public static function user(): array
    {
        startSession();
        $key = $_SESSION['auth_key'] ?? $_COOKIE['shiji_remember'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new HttpError(401, '请登录后继续。', 'unauthorized');
        }
        $user = query('SELECT u.id, u.username, u.password_hash, u.session_version, s.id AS device_id FROM auth_sessions s JOIN users u ON u.id = s.user_id WHERE s.session_key_hash = ? AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP(6) AND s.session_version = u.session_version', [hash('sha256', $key)])->fetch();
        if (!$user) {
            self::destroy();
            throw new HttpError(401, '登录已过期或此设备已被移除，请重新登录。', 'unauthorized');
        }
        if (!isset($_SESSION['auth_key'])) {
            session_regenerate_id(true);
            $_SESSION['auth_key'] = $key;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        query('UPDATE auth_sessions SET last_seen_at = UTC_TIMESTAMP(6), last_ip = ? WHERE id = ?', [ip(), $user['device_id']]);
        return $user;
    }

    public static function login(array $data): void
    {
        checkCsrf();
        rateLimit('login', ip(), 10, 600);
        $username = stringInput($data, 'username', 100);
        $password = stringInput($data, 'password', 1024, false);
        $user = self::credentials($username, $password);
        $address = ip();
        $location = IpLocation::lookup($address);
        $device = $_COOKIE['shiji_browser'] ?? '';
        if (!is_string($device) || !preg_match('/^[a-f0-9]{64}$/D', $device)) $device = bin2hex(random_bytes(32));
        $deviceHash = hash('sha256', $device);
        // Serialize with password changes so an old-password login cannot survive them.
        db()->beginTransaction();
        try {
            $locked = query('SELECT * FROM users WHERE id = ? FOR UPDATE', [$user['id']])->fetch();
            if (!hash_equals($user['password_hash'], $locked['password_hash'])) {
                throw new HttpError(401, '密码已变更，请重新登录。');
            }
            session_regenerate_id(true);
            $key = bin2hex(random_bytes(32));
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $browser = str_contains($agent, 'Edg/') ? 'Edge' : (str_contains($agent, 'Firefox/') ? 'Firefox' : (str_contains($agent, 'Chrome/') ? 'Chrome' : (str_contains($agent, 'Safari/') ? 'Safari' : '浏览器')));
            $os = str_contains($agent, 'iPhone') ? 'iPhone' : (str_contains($agent, 'Android') ? 'Android' : (str_contains($agent, 'Windows') ? 'Windows' : (str_contains($agent, 'Mac') ? 'macOS' : 'Linux / 其他')));
            $existing = query('SELECT session_id FROM browser_devices WHERE user_id = ? AND device_hash = ?', [$user['id'], $deviceHash])->fetchColumn();
            if (!$existing && isset($_SESSION['auth_key']) && is_string($_SESSION['auth_key'])) {
                $existing = query('SELECT id FROM auth_sessions WHERE user_id = ? AND session_key_hash = ?', [$user['id'], hash('sha256', $_SESSION['auth_key'])])->fetchColumn();
            }
            if ($existing) {
                query('UPDATE auth_sessions SET session_key_hash = ?, session_version = ?, device_name = ?, last_ip = ?, last_seen_at = UTC_TIMESTAMP(6), expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), revoked_at = NULL WHERE id = ? AND user_id = ?', [hash('sha256', $key), $locked['session_version'], $browser . ' · ' . $os, $address, persistentSeconds(), $existing, $user['id']]);
                $sessionId = (int) $existing;
            } else {
                query('INSERT INTO auth_sessions (user_id, session_key_hash, session_version, device_name, last_ip, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))', [$user['id'], hash('sha256', $key), $locked['session_version'], $browser . ' · ' . $os, $address, persistentSeconds()]);
                $sessionId = (int) db()->lastInsertId();
            }
            query('INSERT INTO browser_devices (user_id,device_hash,session_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE session_id = ?', [$user['id'], $deviceHash, $sessionId, $sessionId]);
            query('INSERT INTO login_audit (user_id,session_id,login_ip,location_json) VALUES (?,?,?,?)', [$user['id'], $sessionId, $address, json_encode($location, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            db()->commit();
            $_SESSION['auth_key'] = $key;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            authCookie('shiji_browser', $device, 31536000);
            authCookie('shiji_remember', $key, persistentSeconds());
        } catch (\Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
    }

    public static function destroy(): void
    {
        authCookie('shiji_remember', '', -3600);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
    }

    public static function logout(array $user): void
    {
        query('UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE id = ? AND user_id = ?', [$user['device_id'], $user['id']]);
        self::destroy();
    }

    public static function devices(array $user): array
    {
        $rows = query('SELECT id, device_name, last_ip, created_at, last_seen_at, expires_at FROM auth_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6) AND session_version = ? ORDER BY last_seen_at DESC', [$user['id'], $user['session_version']])->fetchAll();
        foreach ($rows as &$row) {
            $row['current'] = (int) $row['id'] === (int) $user['device_id'];
            $audit = query('SELECT login_ip, location_json, created_at FROM login_audit WHERE session_id = ? ORDER BY id DESC LIMIT 1', [$row['id']])->fetch();
            $row['login_ip'] = $audit['login_ip'] ?? $row['last_ip'];
            $row['last_login_at'] = $audit['created_at'] ?? $row['created_at'];
            $row['login_location'] = $audit ? json_decode($audit['location_json'] ?? '{}', true) : [];
            $row['ip_location'] = IpLocation::lookup($row['last_ip']);
        }
        return $rows;
    }

    public static function revoke(array $user, int $device): bool
    {
        $statement = query('UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$device, $user['id']]);
        if (!$statement->rowCount()) throw new HttpError(404, '设备不存在或已移除。');
        $current = $device === (int) $user['device_id'];
        if ($current) self::destroy();
        return $current;
    }

    public static function changePassword(array $user, array $data): void
    {
        rateLimit('password', (string) $user['id'], 5, 600);
        $old = stringInput($data, 'old_password', 1024, false);
        $new = stringInput($data, 'new_password', 1024, false);
        if (mb_strlen($new) < 12 || strlen($new) > 72 || str_contains($new, "\0") || $old === $new) throw new HttpError(422, '新密码至少 12 字符、最多 72 字节，不能含空字节，并且必须与旧密码不同。');
        $hash = password_hash($new, PASSWORD_DEFAULT);
        db()->beginTransaction();
        try {
            $row = query('SELECT password_hash FROM users WHERE id = ? FOR UPDATE', [$user['id']])->fetch();
            if (!password_verify($old, $row['password_hash'])) throw new HttpError(422, '当前密码不正确。');
            query('UPDATE users SET password_hash = ?, session_version = session_version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$hash, $user['id']]);
            query('UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND revoked_at IS NULL', [$user['id']]);
            db()->commit();
            // Password change requires all devices, including this one, to sign in again.
            self::destroy();
        } catch (\Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
    }
}
