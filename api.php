<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

use Shiji\{Auth, HttpError, Notes, Tokens};
use function Shiji\{secureHeaders, requireTransport, input, id, csrf, checkCsrf, jsonResponse, reportError, rateLimit, ip, query, config};

secureHeaders();
try {
    requireTransport();
    $action = $_GET['action'] ?? '';
    if (!is_string($action)) throw new HttpError(400, '接口名称无效。');
    $method = $_SERVER['REQUEST_METHOD'];
    $publicActions = ['anonymous_validate', 'anonymous_submit'];
    if (in_array($action, $publicActions, true)) {
        secureHeaders(true);
        $expected = $action === 'anonymous_submit' ? 'POST' : 'GET';
        if ($method !== $expected) throw new HttpError(405, '请求方式不允许。');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $site = parse_url(config('site_url'));
        $expectedOrigin = $site['scheme'] . '://' . $site['host'] . (isset($site['port']) ? ':' . $site['port'] : '');
        if (($origin !== null && $origin !== $expectedOrigin) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') throw new HttpError(403, '请通过本站投递页面提交。');
        rateLimit('anonymous', ip(), $action === 'anonymous_submit' ? 20 : 60, 60);
        $data = $action === 'anonymous_submit' ? input() : $_GET;
        $code = $data['code'] ?? '';
        if (!is_string($code)) throw new HttpError(403, '投递链接无效。');
        $token = Tokens::validate($code);
        if ($action === 'anonymous_validate') jsonResponse(['expires_at' => $token['expires_at'], 'server_time' => gmdate('Y-m-d H:i:s')]);
        $group = (int) query("SELECT id FROM note_groups WHERE group_type = 'anonymous'")->fetchColumn();
        if (!$group) throw new HttpError(503, '匿名收件箱未初始化。');
        Notes::send($group, $data, $code);
        // Never expose message IDs, histories or attachment URLs to anonymous callers.
        jsonResponse(null, 201, '保存成功');
    }
    if ($action === 'login') {
        if ($method !== 'POST') throw new HttpError(405, '请求方式不允许。');
        Auth::login(input());
        jsonResponse(['csrf' => csrf()]);
    }
    $user = Auth::user();
    $reads = ['me', 'groups', 'messages', 'snapshot', 'sync', 'devices', 'tokens'];
    if (in_array($action, $reads, true)) {
        if ($method !== 'GET') throw new HttpError(405, '请求方式不允许。');
    } else {
        if ($method !== 'POST') throw new HttpError(405, '请求方式不允许。');
        checkCsrf();
    }
    switch ($action) {
        case 'me': jsonResponse(['username' => $user['username'], 'csrf' => csrf(), 'timezone' => config('timezone'), 'poll_seconds' => config('poll_seconds'), 'max_file_bytes' => config('max_file_bytes'), 'max_request_bytes' => config('max_request_bytes'), 'max_attachments' => config('max_attachments')]);
        case 'groups': jsonResponse(Notes::groups());
        case 'snapshot': jsonResponse(Notes::snapshot(id($_GET['group_id'] ?? null)));
        case 'messages': jsonResponse(Notes::page(id($_GET['group_id'] ?? null), isset($_GET['before']) ? id($_GET['before']) : null));
        case 'sync':
            $since = $_GET['since'] ?? '0';
            if (!is_string($since)) throw new HttpError(400, '同步游标无效。');
            jsonResponse(Notes::sync($since, id($_GET['group_id'] ?? null)));
        case 'create_group': jsonResponse(Notes::createGroup(input()), 201);
        case 'rename_group': $data = input(); Notes::rename(id($data['id'] ?? null), $data); jsonResponse();
        case 'delete_group': Notes::deleteGroup(id(input()['id'] ?? null)); jsonResponse();
        case 'send':
            rateLimit('send', (string) $user['id'], 120, 60);
            $data = input();
            jsonResponse(Notes::send(id($data['group_id'] ?? null), $data), 201);
        case 'delete_messages':
            $data = input();
            if (!is_array($data['ids'] ?? null)) throw new HttpError(422, '消息列表无效。');
            Notes::deleteMessages(id($data['group_id'] ?? null), $data['ids']); jsonResponse();
        case 'devices': jsonResponse(Auth::devices($user));
        case 'revoke_device': jsonResponse(['current' => Auth::revoke($user, id(input()['id'] ?? null))]);
        case 'revoke_others': query('UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND id <> ? AND revoked_at IS NULL', [$user['id'], $user['device_id']]); jsonResponse();
        case 'change_password': Auth::changePassword($user, input()); jsonResponse(null, 200, '密码已修改，请重新登录。');
        case 'logout': Auth::logout($user); jsonResponse();
        case 'tokens': jsonResponse(Tokens::listing());
        case 'create_token': rateLimit('create_token', (string) $user['id'], 20, 60); jsonResponse(Tokens::create(input()), 201);
        case 'revoke_token': Tokens::revoke(id(input()['id'] ?? null)); jsonResponse();
        default: throw new HttpError(404, '接口不存在。');
    }
} catch (Throwable $error) {
    reportError($error);
}
