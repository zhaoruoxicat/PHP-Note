<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Installer.php';
use Shiji\{HttpError, Installer};
use function Shiji\{secureHeaders, reportError};

secureHeaders();
$error = ''; $done = false; $locked = false; $ready = false; $existing = null;
try {
    Installer::environment();
    $existing = Installer::existing();
    $locked = is_file(dirname(Installer::path()) . '/installed.lock') || ($existing && Installer::initialized($existing));
    if ($locked) throw new HttpError(403, '站点已初始化，安装入口已锁定。');
    $ready = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { Installer::install($_POST); $done = true; }
} catch (HttpError $exception) { http_response_code($exception->status); $error = $exception->getMessage(); }
catch (Throwable $exception) { reportError($exception, false); }

function escaped(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function value(string $name, string $default = ''): string { return escaped(is_string($_POST[$name] ?? null) ? $_POST[$name] : $default); }
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (!preg_match('/^[A-Za-z0-9.\-\[\]:]+$/D', $host)) $host = 'localhost';
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/install.php')), '/.');
$defaultUrl = ($https ? 'https://' : 'http://') . $host . $base;
?><!doctype html>
<html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>安装 · 拾记</title><link rel="stylesheet" href="style/tabler.min.css"></head>
<body><main class="container-sm py-4 py-md-5"><div class="row justify-content-center"><div class="col-12 col-lg-8"><form class="card" method="post"><div class="card-body p-4"><span class="avatar bg-primary text-white">拾</span><h1 class="h2 mt-3">初始化拾记</h1>
<h2 class="h3 mt-4">环境检查</h2><div class="list-group list-group-flush mb-4">
<?php $checks = ['PHP 8.4+' => PHP_VERSION_ID >= 80400, '配置目录可写' => is_writable(dirname(Installer::path())), 'IP 配置目录可写' => is_writable(__DIR__ . '/config'), 'HTTPS / 本地开发' => $https || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)];
foreach (['pdo_mysql', 'fileinfo', 'mbstring', 'json', 'openssl', 'session'] as $extension) $checks['扩展 ' . $extension] = extension_loaded($extension);
foreach ($checks as $label => $passed): ?><div class="list-group-item px-0 d-flex align-items-center justify-content-between gap-2"><span><?= escaped($label) ?></span><span class="badge <?= $passed ? 'bg-success-lt' : 'bg-danger-lt' ?>"><?= $passed ? '通过' : '未通过' ?></span></div><?php endforeach ?></div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= escaped($error) ?></div><?php endif ?>
<?php if ($done): ?><div class="alert alert-success">数据库、管理员和持久登录功能已初始化，主配置与 IP 配置已就绪，安装入口已锁定。</div><a href="login.php" class="btn btn-primary">前往登录</a>
<?php elseif ($ready && !$locked): ?>
<input type="hidden" name="csrf" value="<?= escaped($_SESSION['install_csrf']) ?>">
<?php if (!$existing): ?>
<h2 class="h3 mt-4">数据库连接</h2><p class="text-secondary">填写主机面板中已创建的空数据库及其账号。</p>
<div class="row g-3"><div class="col-12 col-md-8"><label class="form-label" for="db-host">数据库地址</label><input class="form-control" id="db-host" name="db_host" value="<?= value('db_host', '127.0.0.1') ?>" required maxlength="255"></div><div class="col-12 col-md-4"><label class="form-label" for="db-port">端口</label><input class="form-control" type="number" id="db-port" name="db_port" min="1" max="65535" value="<?= value('db_port', '3306') ?>" required></div>
<div class="col-12"><label class="form-label" for="db-name">数据库名</label><input class="form-control" id="db-name" name="db_name" value="<?= value('db_name') ?>" required maxlength="64"></div><div class="col-12 col-md-6"><label class="form-label" for="db-user">数据库用户名</label><input class="form-control" id="db-user" name="db_user" value="<?= value('db_user') ?>" required maxlength="100" autocomplete="off"></div><div class="col-12 col-md-6"><label class="form-label" for="db-password">数据库密码</label><input class="form-control" id="db-password" name="db_password" type="password" maxlength="1024" autocomplete="off"></div></div>
<h2 class="h3 mt-4">站点设置</h2><label class="form-label" for="site-url">站点地址</label><input class="form-control" id="site-url" name="site_url" type="url" value="<?= value('site_url', $defaultUrl) ?>" required maxlength="2048"><div class="row g-3 mt-1"><div class="col-12 col-md-5"><label class="form-label" for="timezone">时区</label><select class="form-select" id="timezone" name="timezone"><?php foreach (DateTimeZone::listIdentifiers() as $zone): ?><option value="<?= escaped($zone) ?>" <?= $zone === ($_POST['timezone'] ?? 'Asia/Shanghai') ? 'selected' : '' ?>><?= escaped($zone) ?></option><?php endforeach ?></select></div><div class="col-12 col-md-7"><label class="form-label" for="storage">附件存储目录</label><input class="form-control" id="storage" name="storage_path" value="<?= value('storage_path', __DIR__ . '/storage') ?>" required maxlength="4096"><small class="form-hint">填写服务器绝对路径，建议放在网站根目录外。</small></div></div>
<div class="row g-3 mt-1"><div class="col-12 col-md-6"><label class="form-label" for="remember-days">保持登录天数</label><input class="form-control" id="remember-days" name="remember_days" type="number" min="1" max="365" value="<?= value('remember_days', '30') ?>" required></div><div class="col-12 col-md-6 d-flex align-items-end"><input type="hidden" name="cloudflare_real_ip" value="0"><label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="cloudflare_real_ip" value="1" <?= ($_POST['cloudflare_real_ip'] ?? '1') === '1' ? 'checked' : '' ?>><span class="form-check-label">Cloudflare 真实 IP 识别</span></label></div></div>
<?php else: ?><div class="alert alert-info">检测到现有配置，将沿用连接与站点设置，不覆盖配置文件。</div><label class="form-label" for="install-key">安装密钥</label><input class="form-control" id="install-key" name="install_key" type="password" required autocomplete="off"><?php endif ?>
<h2 class="h3 mt-4">IP 归属地服务</h2>
<?php if (is_file(__DIR__ . '/config/ip.php')): ?><div class="alert alert-info">检测到 config/ip.php，将保留已有服务配置，不覆盖令牌。</div>
<?php else: ?><label class="form-label" for="ip-endpoint">服务地址</label><input class="form-control" id="ip-endpoint" name="ip_endpoint" type="url" value="<?= value('ip_endpoint', 'https://ip.example.com/ip.php') ?>" maxlength="2048" required><label class="form-label mt-3" for="ip-token">Token（可选）</label><input class="form-control" id="ip-token" name="ip_token" type="password" maxlength="1024" autocomplete="off"><small class="form-hint">留空不启用归属地查询。令牌仅存入服务器私有配置，不影响真实 IP 识别。</small>
<?php if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)): ?><div class="alert alert-warning mt-3">当前未启用 allow_url_fopen，归属地查询需在 PHP 配置中开启此选项。</div><?php endif ?>
<?php endif ?>
<h2 class="h3 mt-4">管理员账号</h2><label class="form-label" for="username">用户名</label><input class="form-control" id="username" name="username" value="<?= value('username', 'admin') ?>" required maxlength="100" autocomplete="username"><div class="row g-3 mt-1"><div class="col-12 col-md-6"><label class="form-label" for="password">密码</label><input class="form-control" id="password" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></div><div class="col-12 col-md-6"><label class="form-label" for="password-confirm">确认密码</label><input class="form-control" id="password-confirm" name="password_confirm" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></div></div><small class="form-hint mt-2">至少 12 字符，最多 72 字节。</small><button class="btn btn-primary w-100 mt-4" type="submit">安装并创建站点</button>
<?php elseif ($locked): ?><a href="login.php" class="btn btn-primary">前往登录</a><?php endif ?>
</div></form></div></div></main></body></html>
