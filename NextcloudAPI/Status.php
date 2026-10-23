<?php
declare(strict_types=1);
namespace Shiji\Nextcloud;

final class Status
{
    public static function payload(bool $installed): array
    {
        // Protocol compatibility version, not a claim that Nextcloud is installed.
        return ['installed' => $installed, 'maintenance' => false, 'needsDbUpgrade' => false,
            'version' => '30.0.0.0', 'versionstring' => '30.0.0', 'edition' => '',
            'productname' => 'Shiji Notes compatibility', 'extendedSupport' => false];
    }
}
