<?php
declare(strict_types=1);
namespace Shiji\Nextcloud;

use Shiji\{Auth, HttpError};
use function Shiji\{query, db, config, rateLimit, ip};

final class LoginFlow
{
    public static function create(): array
    {
        rateLimit('nc_login_start', ip(), 20, 600);
        $poll = bin2hex(random_bytes(32));
        $grant = bin2hex(random_bytes(32));
        $agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Nextcloud Notes'), 0, 120);
        query('DELETE FROM nextcloud_login_flows WHERE expires_at <= UTC_TIMESTAMP(6)');
        query('INSERT INTO nextcloud_login_flows (poll_hash, grant_hash, device_name, expires_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 20 MINUTE))', [hash('sha256', $poll), hash('sha256', $grant), $agent]);
        $url = rtrim((string) config('site_url'), '/');
        return ['poll' => ['token' => $poll, 'endpoint' => $url . '/index.php/login/v2/poll'], 'login' => $url . '/index.php/login/v2/flow/' . $grant];
    }

    public static function pollToken(array $body, array $query): string
    {
        return \Shiji\stringInput($body + $query, 'token', 64);
    }

    public static function pending(string $grant): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $grant)) throw new HttpError(404, '授权请求不存在或已过期。');
        $flow = query('SELECT * FROM nextcloud_login_flows WHERE grant_hash = ? AND expires_at > UTC_TIMESTAMP(6)', [hash('sha256', $grant)])->fetch();
        if (!$flow || $flow['session_id'] !== null) throw new HttpError(404, '授权请求不存在、已完成或已过期。');
        return $flow;
    }

    public static function approve(string $grant, array $user): void
    {
        $address = ip();
        $location = \Shiji\IpLocation::lookup($address);
        db()->beginTransaction();
        try {
            // Match the password-change lock order before issuing the device credential.
            $locked = query('SELECT * FROM users WHERE id = ? FOR UPDATE', [$user['id']])->fetch();
            if (!$locked || (int) $locked['session_version'] !== (int) $user['session_version']) throw new HttpError(401, '登录状态已变更，请重新登录。');
            if (isset($user['device_id'])) {
                $active = query('SELECT id FROM auth_sessions WHERE id = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6)', [$user['device_id'], $user['id']])->fetchColumn();
                if (!$active) throw new HttpError(401, '登录设备已被撤销。');
            }
            self::pending($grant);
            $flow = query('SELECT * FROM nextcloud_login_flows WHERE grant_hash = ? FOR UPDATE', [hash('sha256', $grant)])->fetch();
            if (!$flow || $flow['session_id'] !== null || strtotime($flow['expires_at'] . ' UTC') <= time()) throw new HttpError(404, '授权请求已完成或已过期。');
            $password = 'nc_' . bin2hex(random_bytes(32));
            query('INSERT INTO auth_sessions (user_id,session_key_hash,session_version,device_name,last_ip,expires_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 DAY))', [$user['id'], hash('sha256', $password), $locked['session_version'], 'Nextcloud · ' . $flow['device_name'], ip()]);
            $session = db()->lastInsertId();
            query('INSERT INTO login_audit (user_id,session_id,login_ip,location_json) VALUES (?,?,?,?)', [$user['id'], $session, $address, json_encode($location, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $nonce = random_bytes(12);
            $cipher = openssl_encrypt($password, 'aes-256-gcm', hash('sha256', config('app_key'), true), OPENSSL_RAW_DATA, $nonce, $tag, $flow['poll_hash']);
            if ($cipher === false) throw new \RuntimeException('Credential encryption failed');
            query('UPDATE nextcloud_login_flows SET session_id = ?, sealed_password = ?, expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE) WHERE poll_hash = ?', [$session, base64_encode($nonce . $tag . $cipher), $flow['poll_hash']]);
            db()->commit();
        } catch (\Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function poll(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new HttpError(404, 'Not authorized');
        rateLimit('nc_login_poll', hash('sha256', $token), 180, 60);
        db()->beginTransaction();
        try {
            $flow = query('SELECT * FROM nextcloud_login_flows WHERE poll_hash = ? AND expires_at > UTC_TIMESTAMP(6) FOR UPDATE', [hash('sha256', $token)])->fetch();
            if (!$flow || $flow['session_id'] === null) throw new HttpError(404, 'Not authorized');
            $user = query('SELECT u.username FROM auth_sessions s JOIN users u ON u.id = s.user_id WHERE s.id = ? AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP(6) AND s.session_version = u.session_version', [$flow['session_id']])->fetch();
            if (!$user) throw new HttpError(404, 'Authorization revoked');
            $sealed = base64_decode($flow['sealed_password'], true);
            if ($sealed === false || strlen($sealed) < 29) throw new \RuntimeException('Invalid sealed credential');
            $password = openssl_decrypt(substr($sealed, 28), 'aes-256-gcm', hash('sha256', config('app_key'), true), OPENSSL_RAW_DATA, substr($sealed, 0, 12), substr($sealed, 12, 16), $flow['poll_hash']);
            if ($password === false) throw new \RuntimeException('Credential decryption failed');
            query('DELETE FROM nextcloud_login_flows WHERE poll_hash = ?', [$flow['poll_hash']]);
            db()->commit();
            return ['server' => rtrim((string) config('site_url'), '/'), 'loginName' => $user['username'], 'appPassword' => $password];
        } catch (\Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function browser(string $grant): never
    {
        $flow = self::pending($grant);
        $error = ''; $user = null; $complete = false;
        try { $user = Auth::user(); } catch (HttpError $e) { if ($e->status !== 401) throw $e; }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                \Shiji\checkCsrf();
                if (!$user) { Auth::login($_POST); $user = Auth::user(); }
                else { self::approve($grant, $user); $complete = true; }
            } catch (HttpError $e) { http_response_code($e->status); $error = $e->getMessage(); }
        }
        header('Content-Type: text/html; charset=utf-8');
        $escape = fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $css = $escape(\Shiji\basePath() . '/style/tabler.min.css');
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>客户端授权 · 拾记</title><link rel="stylesheet" href="' . $css . '"></head><body><main class="container-tight py-5"><h1 class="h2">拾记 · 客户端授权</h1>';
        if ($complete) { echo '<div class="alert alert-success">授权完成，请返回 Nextcloud Notes。</div></main></body></html>'; exit; }
        if ($error) echo '<div class="alert alert-danger">' . $escape($error) . '</div>';
        echo '<p class="text-secondary text-break">' . $escape($flow['device_name']) . '</p><form method="post"><input type="hidden" name="csrf" value="' . $escape(\Shiji\csrf()) . '">';
        if (!$user) {
            echo '<label class="form-label">用户名<input class="form-control mt-2" name="username" maxlength="100" autocomplete="username" required></label><label class="form-label mt-3">密码<input class="form-control mt-2" name="password" type="password" maxlength="1024" autocomplete="current-password" required></label><button class="btn btn-primary w-100 mt-3">登录</button>';
        } else {
            echo '<p>账号：' . $escape($user['username']) . '</p><p>授权后，此客户端可以读取、修改和删除笔记。</p><button class="btn btn-primary w-100 mt-3">授权此客户端</button>';
        }
        echo '</form></main></body></html>'; exit;
    }
}
