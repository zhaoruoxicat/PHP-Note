<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
use Shiji\{Auth, HttpError};
use function Shiji\{secureHeaders, csrf, reportError};
secureHeaders();
$error = '';
try {
    $token = csrf();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            Auth::login($_POST);
            header('Location: index.php');
            exit;
        } catch (HttpError $e) {
            http_response_code($e->status);
            $error = $e->getMessage();
        }
    }
} catch (Throwable $e) { reportError($e, false); }
?><!doctype html>
<html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>登录 · 拾记</title><link rel="stylesheet" href="style/tabler.min.css"></head>
<body><main class="container-tight py-5"><form class="card" method="post"><div class="card-body p-4"><span class="avatar bg-primary text-white">拾</span><h1 class="h2 mt-4">欢迎回到拾记</h1><p class="text-secondary">登录你的笔记空间</p>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif ?>
<input type="hidden" name="csrf" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>"><label class="form-label">用户名<input class="form-control mt-2" name="username" required maxlength="100" autocomplete="username"></label><label class="form-label mt-3">密码<input class="form-control mt-2" name="password" type="password" required maxlength="1024" autocomplete="current-password"></label><button class="btn btn-primary w-100 mt-4">登录</button></div></form></main></body></html>
