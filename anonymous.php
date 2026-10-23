<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
use Shiji\{HttpError, Tokens};
use function Shiji\{secureHeaders, requireTransport, rateLimit, ip, config, basePath, reportError};
secureHeaders(true);
$error = '';
try {
    requireTransport();
    rateLimit('anonymous_page', ip(), 60, 60);
    $code = $_GET['code'] ?? '';
    if (!is_string($code)) throw new HttpError(403, '投递链接无效。');
    Tokens::validate($code);
} catch (HttpError $e) {
    if (!in_array($e->status, [403, 429], true)) reportError($e, false);
    http_response_code($e->status);
    $error = $e->getMessage();
} catch (Throwable $e) { reportError($e, false); }
$base = htmlspecialchars(basePath(), ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>匿名投递 · 拾记</title><link rel="stylesheet" href="<?= $base ?>/style/tabler.min.css"></head>
<body><main class="container-tight py-5"><div class="bg-white border rounded p-4"><span class="avatar bg-primary text-white">拾</span><h1 class="h2 mt-4">匿名投递</h1>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php else: ?><div id="anonymous-root" data-code="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" data-base="<?= $base ?>" data-max-file="<?= (int) config('max_file_bytes') ?>" data-max-request="<?= (int) config('max_request_bytes') ?>" data-max-attachments="<?= (int) config('max_attachments') ?>"><div id="expiry" class="alert alert-success">正在校验有效期…</div><div id="pending" class="d-flex flex-wrap gap-2"></div><textarea id="message-input" class="form-control" rows="5" aria-label="投递内容" placeholder="写下要投递的内容…"></textarea><div class="d-flex gap-2 mt-3"><button id="attach" class="btn btn-outline-secondary">添加附件</button><button id="send" class="btn btn-primary ms-auto" disabled>发送</button></div><input id="file-input" type="file" multiple hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.md,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.7z,.tar,.gz,.json,.csv,.xml,.log"><div id="feedback" class="alert mt-3 d-none" role="status"></div></div><script src="<?= $base ?>/style/editor.js"></script><script src="<?= $base ?>/style/anonymous.js"></script><?php endif ?>
</div></main></body></html>
