<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ob_start();
require dirname(__DIR__) . '/app/bootstrap.php';
use Shiji\Auth;
use function Shiji\{query, csrf};
if (!str_ends_with((string) query('SELECT DATABASE()')->fetchColumn(), '_test')) exit('Dedicated *_test database required.');
function verify(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS: $name\n"; }
$name = 'remember_' . bin2hex(random_bytes(5));
$password = bin2hex(random_bytes(20));
query('INSERT INTO users (username,password_hash) VALUES (?,?)', [$name, password_hash($password, PASSWORD_DEFAULT)]);
$uid = Shiji\db()->lastInsertId();
try {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    csrf(); $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf'];
    Auth::login(['username' => $name, 'password' => $password]);
    $first = Auth::user();
    $old = $_COOKIE['shiji_remember'];
    unset($_SESSION['auth_key']);
    verify(Auth::user()['device_id'] === $first['device_id'], 'remember cookie restores session after session storage loss');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf'];
    Auth::login(['username' => $name, 'password' => $password]);
    verify(Auth::user()['device_id'] === $first['device_id'], 'same browser login reuses device');
    verify($_COOKIE['shiji_remember'] !== $old, 'login rotates credential');
    verify((int) query('SELECT COUNT(*) FROM login_audit WHERE user_id = ?', [$uid])->fetchColumn() === 2, 'every password login records IP');
    Auth::logout(Auth::user());
    verify(!isset($_COOKIE['shiji_remember']), 'logout removes persistent credential');
    $_COOKIE['shiji_remember'] = $old;
    try { Auth::user(); throw new RuntimeException('Old key accepted'); }
    catch (Shiji\HttpError $e) { verify($e->status === 401, 'rotated/revoked key rejected'); }
} finally {
    query('DELETE FROM login_audit WHERE user_id = ?', [$uid]);
    query('DELETE FROM browser_devices WHERE user_id = ?', [$uid]);
    query('DELETE FROM auth_sessions WHERE user_id = ?', [$uid]);
    query('DELETE FROM users WHERE id = ?', [$uid]);
}
