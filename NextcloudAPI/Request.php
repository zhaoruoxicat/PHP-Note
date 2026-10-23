<?php
declare(strict_types=1);
namespace Shiji\Nextcloud;

use Shiji\HttpError;

final class Request
{
    public static function checkOrigin(array $server, string $site): void
    {
        $fetch = $server['HTTP_SEC_FETCH_SITE'] ?? '';
        if ($fetch !== '' && !in_array($fetch, ['same-origin', 'none'], true)) throw new HttpError(403, 'Cross-site API writes are not allowed.');
        if (!isset($server['HTTP_ORIGIN'])) return; // Native apps do not send browser Origin.
        $origin = parse_url((string) $server['HTTP_ORIGIN']);
        $expected = parse_url($site);
        $normalized = static function (array|false $url): ?array {
            if (!$url || !isset($url['scheme'], $url['host'])) return null;
            $scheme = strtolower($url['scheme']);
            return [$scheme, strtolower($url['host']), $url['port'] ?? ($scheme === 'https' ? 443 : 80)];
        };
        if ($normalized($origin) === null || $normalized($origin) !== $normalized($expected)) throw new HttpError(403, 'Cross-origin API writes are not allowed.');
    }

    public static function decode(string $type, string $raw): array
    {
        if (strlen($raw) > 1048576) throw new HttpError(413, 'Request too large.');
        if ($type === 'application/json') {
            try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new HttpError(400, 'Invalid JSON.', 'nc_invalid_json'); }
            if (!is_array($data)) throw new HttpError(400, 'Invalid request body.', 'nc_invalid_body');
            return self::modified($data);
        }
        if ($type !== 'application/x-www-form-urlencoded') throw new HttpError(415, 'Use JSON or URL-encoded form data.');
        parse_str($raw, $data);
        return self::formTypes($data);
    }

    public static function formTypes(array $data): array
    {
        if (isset($data['favorite']) && is_string($data['favorite'])) {
            $value = strtolower($data['favorite']);
            if (!in_array($value, ['true', 'false', '1', '0'], true)) throw new HttpError(400, 'Invalid favorite flag.', 'nc_invalid_favorite_form');
            $data['favorite'] = in_array($value, ['true', '1'], true);
        }
        return self::modified($data);
    }

    private static function modified(array $data): array
    {
        if (!array_key_exists('modified', $data)) return $data;
        $value = $data['modified'];
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || (is_string($value) && strlen($value) > 64) || !is_numeric($value)) {
            throw new HttpError(400, 'Invalid modified timestamp.', 'nc_invalid_modified_form');
        }
        $seconds = (float) $value;
        if (!is_finite($seconds) || $seconds < 0 || $seconds > 253402300799) {
            throw new HttpError(400, 'Invalid modified timestamp.', 'nc_invalid_modified_form');
        }
        $data['modified'] = (int) floor($seconds);
        return $data;
    }

    public static function body(): array
    {
        $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) throw new HttpError(413, 'Request too large.');
        if ($type === 'multipart/form-data' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if ($_FILES) throw new HttpError(415, 'File uploads are not supported by this endpoint.');
            return self::formTypes($_POST);
        }
        $stream = fopen('php://input', 'rb');
        if ($stream === false) throw new HttpError(400, 'Cannot read request.');
        try { $raw = stream_get_contents($stream, 1048577); }
        finally { fclose($stream); }
        if ($raw === false) throw new HttpError(400, 'Cannot read request.');
        return self::decode($type, $raw);
    }
}
