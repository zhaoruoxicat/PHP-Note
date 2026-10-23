<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/ClientIp.php';
use Shiji\ClientIp;

$cases = [
    [['REMOTE_ADDR' => '172.68.175.109', 'HTTP_CF_CONNECTING_IP' => '203.0.113.7'], true, '203.0.113.7'],
    [['REMOTE_ADDR' => '2606:4700::1234', 'HTTP_CF_CONNECTING_IP' => '2001:db8::7'], true, '2001:db8::7'],
    [['REMOTE_ADDR' => '203.0.113.8', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1'], true, '203.0.113.8'],
    [['REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1'], true, '127.0.0.1'],
    [['REMOTE_ADDR' => '172.68.175.109', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1, 8.8.8.8'], true, '172.68.175.109'],
    [['REMOTE_ADDR' => '172.68.175.109', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1'], false, '172.68.175.109'],
    [['REMOTE_ADDR' => '::ffff:172.68.175.109', 'HTTP_CF_CONNECTING_IP' => '203.0.113.7'], true, '203.0.113.7'],
    [['REMOTE_ADDR' => '104.32.0.1', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1'], true, '104.32.0.1'],
];
foreach ($cases as $i => [$server, $enabled, $expected]) {
    if (ClientIp::resolve($server, $enabled) !== $expected) { fwrite(STDERR, 'FAIL: case ' . $i . "\n"); exit(1); }
}
foreach ([['173.245.48.0', '173.245.48.0/20', true], ['173.245.63.255', '173.245.48.0/20', true], ['173.245.64.0', '173.245.48.0/20', false], ['1.1.1.1', '::/0', false], ['1.1.1.1', '0.0.0.0/33', false]] as [$ip, $range, $expected]) {
    if (ClientIp::inRange($ip, $range) !== $expected) { fwrite(STDERR, "FAIL: CIDR boundary\n"); exit(1); }
}
echo "PASS: Cloudflare trust, spoofed headers, IPv4/IPv6 and CIDR boundaries\n";
