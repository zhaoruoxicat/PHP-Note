<?php
declare(strict_types=1);
namespace Shiji;

final class ClientIp
{
    // Verified against Cloudflare's official IPv4/IPv6 lists on 2026-10-13.
    public const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function inRange(string $address, string $range): bool
    {
        $parts = explode('/', $range);
        if (count($parts) !== 2 || !ctype_digit($parts[1])) return false;
        $ip = @inet_pton($address); $network = @inet_pton($parts[0]);
        if ($ip === false || $network === false || strlen($ip) !== strlen($network)) return false;
        $bits = (int) $parts[1];
        if ($bits > strlen($ip) * 8) return false;
        $bytes = intdiv($bits, 8); $remaining = $bits % 8;
        if (substr($ip, 0, $bytes) !== substr($network, 0, $bytes)) return false;
        return $remaining === 0 || (ord($ip[$bytes]) & (255 << (8 - $remaining))) === (ord($network[$bytes]) & (255 << (8 - $remaining)));
    }

    public static function resolve(array $server, bool $enabled = true): string
    {
        $remote = $server['REMOTE_ADDR'] ?? '';
        if (!is_string($remote) || !filter_var($remote, FILTER_VALIDATE_IP)) return 'unknown';
        // Normalize IPv4-mapped peers without changing real IPv6 addresses.
        if (str_starts_with(strtolower($remote), '::ffff:') && filter_var(substr($remote, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $remote = substr($remote, 7);
        if (!$enabled) return $remote;
        foreach (self::CLOUDFLARE_RANGES as $range) {
            if (!self::inRange($remote, $range)) continue;
            $candidate = $server['HTTP_CF_CONNECTING_IP'] ?? '';
            return is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : $remote;
        }
        // Do not trust arbitrary X-Forwarded-For, X-Real-IP or forged CF headers.
        return $remote;
    }
}
