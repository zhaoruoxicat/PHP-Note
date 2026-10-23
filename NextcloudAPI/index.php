<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/NotesApi.php';
require __DIR__ . '/Status.php';
require __DIR__ . '/LoginFlow.php';
require __DIR__ . '/Request.php';
use Shiji\{Auth, HttpError};
use Shiji\Nextcloud\{NotesApi, Conflict};
use function Shiji\{requireTransport, secureHeaders, config, basePath, rateLimit, ip, input, id};

function respond(mixed $value, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($status !== 304) echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
function conditional(mixed $value): never {
    $etag = '"' . hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)) . '"';
    if (is_array($value) && isset($value['etag'])) $etag = '"' . $value['etag'] . '"';
    header('ETag: ' . $etag);
    if (in_array($etag, array_map('trim', explode(',', $_SERVER['HTTP_IF_NONE_MATCH'] ?? '')), true) || ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '*') respond(null, 304);
    respond($value);
}
try {
    secureHeaders(true);
    requireTransport();
    header('X-Notes-API-Versions: 0.2, 1.1');
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $path = substr($path, strlen(basePath()));
    $path = preg_replace('~^/NextcloudAPI(?:/index\.php)?~', '', $path);
    $path = preg_replace('~^/index\.php~', '', $path);
    $path = rtrim($path, '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($path === '/login/v2') {
        if ($method !== 'POST') { header('Allow: POST'); throw new HttpError(405, 'Method not allowed'); }
        respond(Shiji\Nextcloud\LoginFlow::create());
    }
    if ($path === '/login/v2/poll') {
        if ($method !== 'POST') { header('Allow: POST'); throw new HttpError(405, 'Method not allowed'); }
        $data = input();
        respond(Shiji\Nextcloud\LoginFlow::poll(Shiji\Nextcloud\LoginFlow::pollToken($data, $_GET)));
    }
    if (preg_match('~^/login/v2/flow/([a-f0-9]{64})$~D', $path, $flow)) {
        if (!in_array($method, ['GET', 'POST'], true)) { header('Allow: GET, POST'); throw new HttpError(405, 'Method not allowed'); }
        Shiji\Nextcloud\LoginFlow::browser($flow[1]);
    }
    // Clients detect the server before collecting credentials.
    if ($path === '/status.php') {
        if ($method !== 'GET') { header('Allow: GET'); throw new HttpError(405, 'Method not allowed'); }
        $installed = (bool) Shiji\query('SELECT id FROM users LIMIT 1')->fetchColumn();
        respond(Shiji\Nextcloud\Status::payload($installed));
    }
    $username = $_SERVER['PHP_AUTH_USER'] ?? null;
    $password = $_SERVER['PHP_AUTH_PW'] ?? null;
    if ($username === null) {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/iD', $header, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) [$username, $password] = explode(':', $decoded, 2);
        }
    }
    if (!is_string($username) || !is_string($password) || strlen($username) > 400 || strlen($password) > 1024) throw new HttpError(401, 'Basic authentication required');
    // Count failed authentication only, so normal background sync does not exhaust login limits.
    try { $user = Auth::apiCredentials($username, $password); }
    catch (HttpError $e) { rateLimit('login', ip(), 10, 600); throw $e; }
    rateLimit('nextcloud_api', ip() . ':' . $user['id'], 300, 60);
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        Shiji\Nextcloud\Request::checkOrigin($_SERVER, (string) config('site_url'));
    }
    if ($path === '/ocs/v2.php/cloud/capabilities' || $path === '/ocs/v1.php/cloud/capabilities') {
        if ($method !== 'GET') throw new HttpError(405, 'Method not allowed');
        respond(['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 100, 'message' => 'OK'], 'data' => [
            'version' => ['major' => 30, 'minor' => 0, 'micro' => 0, 'string' => '30.0.0', 'edition' => '', 'extendedSupport' => false],
            'capabilities' => ['notes' => ['api_version' => ['0.2', '1.1'], 'version' => '3.4.0']]]]]);
    }
    $legacy = str_starts_with($path, '/apps/notes/api/v0.2/');
    if ($legacy) $path = '/apps/notes/api/v1/' . substr($path, strlen('/apps/notes/api/v0.2/'));
    if ($path === '/apps/notes/api/v1/settings') {
        if ($method === 'GET') respond(NotesApi::settings());
        if ($method === 'PUT') respond(NotesApi::settings(Shiji\Nextcloud\Request::body()));
        header('Allow: GET, PUT');
        throw new HttpError(405, 'Method not allowed');
    }
    if (!preg_match('~^/apps/notes/api/v1/notes(?:/([1-9][0-9]{0,17}))?/?$~D', $path, $m)) throw new HttpError(404, 'Unsupported compatibility endpoint');
    $noteId = isset($m[1]) ? id($m[1]) : null;
    if ($method === 'GET') {
        if ($noteId !== null) conditional(NotesApi::get($noteId));
        $category = isset($_GET['category']) ? Shiji\stringInput($_GET, 'category', 120) : null;
        if ($category === '') $category = '未分类';
        if (isset($_GET['pruneBefore']) && (!is_string($_GET['pruneBefore']) || !preg_match('/^[0-9]{1,12}$/D', $_GET['pruneBefore']))) throw new HttpError(400, 'Invalid pruneBefore');
        if (isset($_GET['exclude']) && !is_string($_GET['exclude'])) throw new HttpError(400, 'Invalid exclude');
        $notes = NotesApi::listing($category);
        // Do not prune: returning full objects is valid and avoids losing same-second edits.
        $exclude = explode(',', $_GET['exclude'] ?? '');
        foreach ($notes as &$note) foreach ($exclude as $field) if ($field !== 'id') unset($note[$field]);
        unset($note);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        conditional($notes);
    }
    if (($method === 'POST' && $noteId === null) || ($method === 'PUT' && $noteId !== null)) {
        $data = Shiji\Nextcloud\Request::body();
        if ($legacy && array_key_exists('content', $data)) {
            $content = Shiji\stringInput($data, 'content', config('max_text_length'), false);
            $data['title'] = mb_substr(explode("\n", $content)[0] ?: 'New note', 0, 255);
        }
        respond(NotesApi::save($noteId, $data, $method === 'PUT' ? ($_SERVER['HTTP_IF_MATCH'] ?? null) : null));
    }
    if ($method === 'DELETE' && $noteId !== null) { NotesApi::delete($noteId); respond([]); }
    header('Allow: ' . ($noteId === null ? 'GET, POST' : 'GET, PUT, DELETE'));
    throw new HttpError(405, 'Method not allowed');
} catch (Conflict $e) {
    respond($e->note, 412);
} catch (\Throwable $e) {
    $status = $e instanceof HttpError ? $e->status : 500;
    if ($e instanceof HttpError && in_array($status, [400, 422], true)) {
        $type = substr(preg_replace('/[^a-zA-Z0-9\/;= ._-]/', '', (string) ($_SERVER['CONTENT_TYPE'] ?? 'missing')), 0, 120);
        error_log('Shiji NextcloudAPI validation: status=' . $status
            . ' code=' . $e->errorCode . ' at ' . basename($e->getFile()) . ':' . $e->getLine()
            . ' content_type=' . $type . ' content_length=' . (int) ($_SERVER['CONTENT_LENGTH'] ?? 0));
    }
    if ($status === 401) header('WWW-Authenticate: Basic realm="Shiji Notes", charset="UTF-8"');
    if (!$e instanceof HttpError) {
        $details = '';
        if ($e instanceof \PDOException) {
            $details = ' SQLSTATE=' . preg_replace('/[^A-Z0-9]/i', '', (string) ($e->errorInfo[0] ?? $e->getCode()))
                . ' driver=' . preg_replace('/[^0-9a-z]/i', '', (string) ($e->errorInfo[1] ?? 'unknown'));
        }
        // Never log SQL, credentials, grant URLs, polling tokens or exception messages.
        $origin = $e->getTrace()[0] ?? [];
        $details .= ' caller=' . basename((string) ($origin['file'] ?? $e->getFile())) . ':' . ($origin['line'] ?? $e->getLine());
        error_log('Shiji NextcloudAPI error: ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . $details);
    }
    respond(['errorMessage' => $e instanceof HttpError ? $e->getMessage() : 'Server error; check logs and apply NextcloudAPI/migration.sql.'], $status);
}
