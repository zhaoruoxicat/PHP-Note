<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ob_start();
require dirname(__DIR__) . '/app/bootstrap.php';
use Shiji\{Auth, HttpError, Notes, Tokens};
use function Shiji\{db, query, csrf, config, checkCsrf, id};

// Only an explicitly configured dedicated *_test database may be modified.
if (!str_ends_with((string) query('SELECT DATABASE()')->fetchColumn(), '_test')) {
    fwrite(STDERR, "Refusing: set SHIJI_CONFIG to a config using a dedicated *_test database.\n"); exit(1);
}
function verify(bool $condition, string $description): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    echo 'PASS: ' . $description . "\n";
}
function rejected(callable $fn, int $status, string $description): void {
    try { $fn(); } catch (HttpError $e) { verify($e->status === $status, $description); return; }
    throw new RuntimeException('FAIL: accepted ' . $description);
}
try {
    foreach (explode(';', file_get_contents(dirname(__DIR__) . '/database/install.sql')) as $statement) if (trim($statement)) db()->exec($statement);
    $username = 'test_' . bin2hex(random_bytes(6));
    $password = bin2hex(random_bytes(20));
    query('INSERT INTO users (username, password_hash) VALUES (?, ?)', [$username, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) db()->lastInsertId();
    csrf();
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_USER_AGENT'] = 'integration-test';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf'];
    Auth::login(['username' => $username, 'password' => $password]);
    $user = Auth::user();
    verify((int) $user['id'] === $userId, 'login creates authenticated server session');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong';
    rejected(checkCsrf(...), 403, 'CSRF rejects wrong token');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf']; checkCsrf();
    rejected(fn() => id('1 OR 1=1'), 400, 'IDs reject SQL input');
    $group = Notes::createGroup(['name' => '<script>alert(1)</script>']); $groupId = (int) $group['id'];
    $snapshot = Notes::snapshot($groupId);
    $message = Notes::send($groupId, ['content' => "first\nsecond\n<script>alert(1)</script>"]);
    rejected(fn() => Notes::deleteGroup($groupId), 409, 'nonempty groups cannot be deleted');
    verify($message['content'] === "first\nsecond\n<script>alert(1)</script>", 'multiline text is stored without HTML execution or alteration');
    $sync = Notes::sync($snapshot['cursor'], $groupId);
    verify(count($sync['events']) === 1 && $sync['events'][0]['event_type'] === 'message_added', 'incremental addition returns one committed event');
    Notes::rename($groupId, ['name' => 'renamed']);
    Notes::deleteMessages($groupId, [$message['id']]);
    $changes = Notes::sync($sync['next_cursor'], $groupId);
    verify(array_column($changes['events'], 'event_type') === ['group_updated', 'message_deleted'], 'rename and deletion remain visible to other devices');
    verify(Notes::page($groupId)['messages'] === [], 'deleted messages stay deleted');
    $anchor = null;
    for ($i = 0; $i < 52; $i++) {
        $row = Notes::send($groupId, ['content' => 'history ' . $i]);
        $anchor ??= $row['id'];
    }
    $page = Notes::page($groupId);
    verify(count($page['messages']) === 50 && $page['has_more'], 'history uses bounded 50-message pages');
    $older = Notes::page($groupId, (int) $page['before']);
    verify(count($older['messages']) === 2 && !$older['has_more'], 'history cursor returns nonoverlapping previous messages');
    $emptyGroup = Notes::createGroup(['name' => 'empty deletion test']);
    Notes::deleteGroup((int) $emptyGroup['id']);
    rejected(fn() => Notes::group((int) $emptyGroup['id']), 404, 'empty ordinary group can be deleted');
    $token = Tokens::create(['duration' => 5]);
    verify((bool) preg_match('/^[0-9]{6}$/D', $token['code']), 'anonymous codes are exactly six digits');
    $row = Tokens::validate($token['code']); $tokenId = (int) $row['id'];
    verify(!array_key_exists('code', $row) && $row['code_hmac'] === hash_hmac('sha256', $token['code'], config('app_key')), 'database stores HMAC instead of plaintext code');
    $leadingId = null;
    if (!query('SELECT id FROM anonymous_tokens WHERE code_hmac = ?', [hash_hmac('sha256', '003517', config('app_key'))])->fetchColumn()) {
        query('INSERT INTO anonymous_tokens (code_hmac, code_hint, duration_minutes, expires_at) VALUES (?, ?, 5, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE))', [hash_hmac('sha256', '003517', config('app_key')), '17']);
        $leadingId = (int) db()->lastInsertId();
        verify((int) Tokens::validate('003517')['id'] === $leadingId, 'leading-zero anonymous code remains a string');
    }
    Tokens::revoke($tokenId);
    rejected(fn() => Tokens::validate($token['code']), 403, 'revoked anonymous codes immediately fail validation');
    query('UPDATE sync_state SET min_cursor = current_seq WHERE id = 1');
    verify(Notes::sync('0', $groupId)['reload'], 'expired event cursors force rebaseline');
    Auth::revoke($user, (int) $user['device_id']);
    rejected(Auth::user(...), 401, 'revoked session cannot authenticate again');
    echo "Integration checks completed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n"); $failed = true;
} finally {
    if (isset($groupId)) {
        while ($rows = Notes::page($groupId)['messages']) Notes::deleteMessages($groupId, array_column($rows, 'id'));
        Notes::deleteGroup($groupId);
    }
    if (isset($tokenId)) query('DELETE FROM anonymous_tokens WHERE id = ?', [$tokenId]);
    if (isset($leadingId)) query('DELETE FROM anonymous_tokens WHERE id = ?', [$leadingId]);
    if (isset($userId)) { query('DELETE FROM auth_sessions WHERE user_id = ?', [$userId]); query('DELETE FROM users WHERE id = ?', [$userId]); }
}
exit(isset($failed) ? 1 : 0);
