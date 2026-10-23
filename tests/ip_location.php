<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$response = ['success' => true, 'data' => ['ip' => '8.8.8.8', 'country' => 'United States', 'region' => 'California', 'city' => 'Mountain View', 'isp' => 'Google', 'token' => 'must-not-retain']];
$result = Shiji\IpLocation::normalize($response, '8.8.8.8');
if ($result['city'] !== 'Mountain View' || isset($result['token'])) throw new RuntimeException('Location normalization failed');
if (Shiji\IpLocation::normalize($response, '1.1.1.1') !== []) throw new RuntimeException('Wrong-IP response accepted');
if (Shiji\IpLocation::normalize(['success' => false], '8.8.8.8') !== []) throw new RuntimeException('Failed response accepted');
echo "PASS: location fields, IP match, credential omission\n";
