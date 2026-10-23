<?php
declare(strict_types=1);
namespace Shiji;

final class Tokens
{
    public static function validate(string $code, bool $lock = false): array
    {
        if (!preg_match('/^[0-9]{6}$/D', $code)) throw new HttpError(403, '投递链接无效或已失效。', 'token_invalid');
        $row = query('SELECT * FROM anonymous_tokens WHERE code_hmac = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6)' . ($lock ? ' FOR UPDATE' : ''), [hash_hmac('sha256', $code, config('app_key'))])->fetch();
        if (!$row) throw new HttpError(403, '投递链接无效、已过期或已撤销。', 'token_invalid');
        return $row;
    }

    public static function create(array $data): array
    {
        $duration = id($data['duration'] ?? 5);
        if (!in_array($duration, [5, 10, 20, 30, 60], true)) throw new HttpError(422, '请选择有效的链接时长。');
        return Notes::transaction(function () use ($duration) {
            for ($i = 0; $i < 30; $i++) {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $hash = hash_hmac('sha256', $code, config('app_key'));
                // Historical codes are retained until cleanup and cannot collide either.
                if (query('SELECT id FROM anonymous_tokens WHERE code_hmac = ?', [$hash])->fetchColumn()) continue;
                query('INSERT INTO anonymous_tokens (code_hmac, code_hint, duration_minutes, expires_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? MINUTE))', [$hash, substr($code, -2), $duration, $duration]);
                $id = (int) db()->lastInsertId();
                $expires = query('SELECT expires_at FROM anonymous_tokens WHERE id = ?', [$id])->fetchColumn();
                return ['code' => $code, 'url' => rtrim(config('site_url'), '/') . '/' . $code, 'fallback_url' => rtrim(config('site_url'), '/') . '/anonymous.php?code=' . $code, 'expires_at' => $expires];
            }
            throw new HttpError(503, '暂时无法分配访问码，请稍后重试。');
        });
    }

    public static function listing(): array
    {
        return query("SELECT id, CONCAT('••••', code_hint) AS masked_code, created_at, expires_at, submit_count, CASE WHEN revoked_at IS NOT NULL THEN 'revoked' WHEN expires_at <= UTC_TIMESTAMP(6) THEN 'expired' ELSE 'active' END AS status FROM anonymous_tokens ORDER BY id DESC LIMIT 100")->fetchAll();
    }

    public static function revoke(int $id): void
    {
        Notes::transaction(function () use ($id) {
            $statement = query('UPDATE anonymous_tokens SET revoked_at = UTC_TIMESTAMP(6) WHERE id = ? AND revoked_at IS NULL', [$id]);
            if (!$statement->rowCount()) throw new HttpError(404, '链接不存在或已撤销。');
        });
    }
}
