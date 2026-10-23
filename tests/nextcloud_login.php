<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/NextcloudAPI/LoginFlow.php';
use Shiji\Nextcloud\LoginFlow;
use Shiji\Auth;
use function Shiji\{query, db};
if (!str_ends_with((string) query('SELECT DATABASE()')->fetchColumn(), '_test')) exit('Dedicated *_test database required.');
function verify(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException($name);
    echo "PASS: $name\n";
}
function missing(callable $call): void {
    try { $call(); throw new RuntimeException('Expected 404'); }
    catch (Shiji\HttpError $e) { verify($e->status === 404, 'pending/consumed poll returns 404'); }
}
$name = 'flow_test_' . bin2hex(random_bytes(5));
query('INSERT INTO users (username,password_hash) VALUES (?,?)', [$name, password_hash('test-password-long', PASSWORD_DEFAULT)]);
$user = Auth::credentials($name, 'test-password-long');
try {
    $flow = LoginFlow::create();
    verify(str_ends_with($flow['poll']['endpoint'], '/index.php/login/v2/poll'), 'poll URL uses working PHP entry point');
    verify(str_contains($flow['login'], '/index.php/login/v2/flow/'), 'grant URL uses working PHP entry point');
    verify(LoginFlow::pollToken([], ['token' => $flow['poll']['token']]) === $flow['poll']['token'], 'iOS query token is accepted');
    verify(LoginFlow::pollToken(['token' => $flow['poll']['token']], []) === $flow['poll']['token'], 'form or JSON token remains accepted');
    missing(fn() => LoginFlow::poll($flow['poll']['token']));
    $grant = basename(parse_url($flow['login'], PHP_URL_PATH));
    LoginFlow::approve($grant, $user);
    $result = LoginFlow::poll($flow['poll']['token']);
    verify($result['loginName'] === $name && $result['appPassword'] !== 'test-password-long', 'flow issues separate device credential');
    $device = Auth::apiCredentials($name, $result['appPassword']);
    verify((int) $device['id'] === (int) $user['id'], 'app password authenticates');
    missing(fn() => LoginFlow::poll($flow['poll']['token']));
    Auth::revoke($device, (int) $device['device_id']);
    try { Auth::apiCredentials($name, $result['appPassword']); throw new RuntimeException('Revoked app accepted'); }
    catch (Shiji\HttpError $e) { verify($e->status === 401, 'device revocation rejects app credential'); }
} finally {
    query('DELETE FROM nextcloud_login_flows WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)', [$user['id']]);
    query('DELETE FROM auth_sessions WHERE user_id = ?', [$user['id']]);
    query('DELETE FROM users WHERE id = ?', [$user['id']]);
}
