<?php
declare(strict_types=1);
namespace Shiji;

final class IpLocation
{
    public static function normalize(array $response, string $ip): array
    {
        if (($response['success'] ?? false) !== true || !is_array($response['data'] ?? null)) return [];
        $data = $response['data'];
        if (($data['ip'] ?? '') !== $ip) return [];
        $result = [];
        foreach (['country', 'region', 'city', 'isp', 'organization', 'country_code'] as $field) {
            if (is_string($data[$field] ?? null) && mb_check_encoding($data[$field], 'UTF-8')) $result[$field] = mb_substr($data[$field], 0, 150);
        }
        return $result;
    }

    public static function lookup(string $ip): array
    {
        static $memo = [];
        if (isset($memo[$ip])) return $memo[$ip];
        $memo[$ip] = [];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return [];
        $path = dirname(__DIR__) . '/config/ip.php';
        if (!is_file($path)) return [];
        try {
            $settings = require $path;
            if (!is_array($settings) || !is_string($settings['token'] ?? null) || $settings['token'] === '') return [];
            $cached = query('SELECT location_json FROM ip_geo_cache WHERE ip = ? AND expires_at > UTC_TIMESTAMP(6)', [$ip])->fetchColumn();
            if ($cached) return $memo[$ip] = json_decode($cached, true, 16, JSON_THROW_ON_ERROR);
            $endpoint = $settings['endpoint'] ?? 'https://ip.example.com/ip.php';
            $parts = is_string($endpoint) ? parse_url($endpoint) : false;
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['fragment'])) return [];
            $url = $endpoint . (isset($parts['query']) ? '&' : '?') . http_build_query(['ip' => $ip, 'token' => $settings['token']], '', '&', PHP_QUERY_RFC3986);
            $context = stream_context_create(['http' => ['timeout' => max(1, min(3, (int) ($settings['timeout_seconds'] ?? 2))), 'follow_location' => 0, 'header' => "Accept: application/json\r\n"], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
            // Suppress URL-bearing warnings: they would disclose the service token.
            $stream = @fopen($url, 'rb', false, $context);
            if ($stream === false) return [];
            try { $raw = @stream_get_contents($stream, 65537); } finally { fclose($stream); }
            if (!is_string($raw) || strlen($raw) > 65536) return [];
            $data = self::normalize(json_decode($raw, true, 16, JSON_THROW_ON_ERROR), $ip);
            $ttl = $data ? max(60, min(604800, (int) ($settings['cache_seconds'] ?? 604800))) : 300;
            query('INSERT INTO ip_geo_cache (ip,location_json,expires_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE location_json = ?, expires_at = DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND)', [$ip, json_encode($data, JSON_THROW_ON_ERROR), $ttl, json_encode($data, JSON_THROW_ON_ERROR), $ttl]);
            return $memo[$ip] = $data;
        } catch (\Throwable $e) {
            error_log('Shiji IP lookup failed: ' . get_class($e));
            return [];
        }
    }
}
