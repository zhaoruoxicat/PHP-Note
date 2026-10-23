<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/NextcloudAPI/Request.php';
use Shiji\Nextcloud\Request;
function verify(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException($name);
    echo "PASS: $name\n";
}
$data = Request::decode('application/x-www-form-urlencoded', 'content=hello%0Aworld&favorite=false&modified=1791889515');
verify($data['content'] === "hello\nworld" && $data['favorite'] === false && $data['modified'] === 1791889515, 'iOS encoded form fields');
$data = Request::decode('application/json', '{"content":"hello","favorite":true,"modified":123}');
verify($data['favorite'] === true && $data['modified'] === 123, 'JSON types preserved');
$data = Request::decode('application/x-www-form-urlencoded', 'modified=1791890235.825');
verify($data['modified'] === 1791890235, 'fractional form timestamp becomes integer seconds');
$data = Request::decode('application/x-www-form-urlencoded', 'modified=1.791890235825e9');
verify($data['modified'] === 1791890235, 'scientific form timestamp becomes integer seconds');
$data = Request::decode('application/json', '{"modified":1791890235.825}');
verify($data['modified'] === 1791890235, 'fractional JSON timestamp becomes integer seconds');
foreach (['not-a-date', '-1', '1e1000', '253402300800'] as $value) {
    try { Request::decode('application/x-www-form-urlencoded', 'modified=' . urlencode($value)); throw new RuntimeException('Invalid timestamp accepted'); }
    catch (Shiji\HttpError $e) { verify($e->status === 400, 'invalid/out-of-range timestamp rejected'); }
}
Request::checkOrigin(['HTTP_ORIGIN' => 'https://notes.example.com', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], 'https://notes.example.com/subdir');
Request::checkOrigin([], 'https://notes.example.com');
try { Request::checkOrigin(['HTTP_ORIGIN' => 'https://attacker.example'], 'https://notes.example.com'); throw new RuntimeException('Cross-site form accepted'); }
catch (Shiji\HttpError $e) { verify($e->status === 403, 'cross-origin form rejected'); }
try { Request::checkOrigin(['HTTP_SEC_FETCH_SITE' => 'cross-site'], 'https://notes.example.com'); throw new RuntimeException('Cross-site form accepted'); }
catch (Shiji\HttpError $e) { verify($e->status === 403, 'cross-site browser request rejected'); }
