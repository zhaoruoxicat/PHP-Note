<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Installer.php';
use Shiji\{HttpError, Installer};

function verify(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException($description);
    echo 'PASS: ' . $description . "\n";
}
function reject(array $data, string $description): void
{
    try { Installer::settings($data); }
    catch (HttpError $error) { verify($error->status === 422, $description); return; }
    throw new RuntimeException('Accepted invalid settings: ' . $description);
}

try {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $input = ['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'shiji', 'db_user' => 'shiji', 'db_password' => "secret'; \$GLOBALS['config_injection'] = true; //\\end", 'site_url' => 'https://note.example.com/notes', 'timezone' => 'Asia/Shanghai', 'storage_path' => '/tmp/shiji-installer-test-storage'];
    $config = Installer::settings($input);
    verify($config['remember_seconds'] === 2592000 && $config['cloudflare_real_ip'], 'persistent login and trusted Cloudflare defaults');
    verify(Installer::settings($input + ['remember_days' => '7', 'cloudflare_real_ip' => '0'])['remember_seconds'] === 604800, 'configured login duration');
    reject($input + ['remember_days' => '0'], 'invalid login duration rejected');
    $ip = Installer::ipSettings(['ip_token' => "token'\\value"]);
    verify($ip['token'] === "token'\\value" && $ip['endpoint'] === 'https://ip.example.com/ip.php', 'IP token and endpoint settings');
    verify(Installer::ipSettings([])['token'] === '', 'optional IP token supported');
    try { Installer::ipSettings(['ip_endpoint' => 'https://example.com/ip?token=secret']); throw new RuntimeException('Token-bearing endpoint accepted'); }
    catch (HttpError $error) { verify($error->status === 422, 'token-bearing endpoint rejected'); }
    verify($config['database']['password'] === $input['db_password'], 'database password preserved verbatim');
    verify(strlen($config['app_key']) === 64 && $config['app_key'] !== $config['install_key'], 'distinct cryptographic secrets generated');
    verify($config['app_key'] !== Installer::settings($input)['app_key'], 'secrets are generated anew, not derived from submitted credentials');
    $temporary = tempnam(sys_get_temp_dir(), 'shiji-export-test-');
    try {
        file_put_contents($temporary, Installer::source($config));
        $roundTrip = require $temporary;
        verify($roundTrip === $config && !isset($GLOBALS['config_injection']), 'generated PHP quotes credentials as inert data');
    } finally { unlink($temporary); }
    reject(array_replace($input, ['db_host' => 'localhost;dbname=other']), 'DSN injection rejected');
    reject(array_replace($input, ['db_port' => '0']), 'invalid port rejected');
    reject(array_replace($input, ['db_name' => '../other']), 'invalid database name rejected');
    reject(array_replace($input, ['site_url' => 'https://user:password@example.com']), 'credentials in site URL rejected');
    reject(array_replace($input, ['site_url' => 'https://example.com/?q=value']), 'query in site URL rejected');
    reject(array_replace($input, ['timezone' => 'Not/AZone']), 'invalid timezone rejected');
    reject(array_replace($input, ['storage_path' => '/tmp/../public']), 'storage traversal rejected');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    reject(array_replace($input, ['site_url' => 'http://example.com']), 'production HTTP configuration rejected');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    verify(Installer::settings(array_replace($input, ['site_url' => 'http://127.0.0.1:8080']))['allow_http'], 'loopback development HTTP supported');
    $_SESSION = ['install_csrf' => 'expected'];
    try { Installer::install(['csrf' => 'wrong']); throw new RuntimeException('Invalid CSRF accepted'); }
    catch (HttpError $error) { verify($error->status === 403, 'invalid CSRF rejected before any file or database changes'); }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n"); exit(1);
}
