<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Shipping country display names read I18n DB catalogs, not Symfony Intl.
 */
final class CountryNameDbOnlyContractTest extends TestCase
{
    public function testAddressRegionEmbargoForbidSymfonyCountries(): void
    {
        $paths = [
            \BP . 'app/code/Weline/Shipping/Service/AddressFormatter.php',
            \BP . 'app/code/Weline/Shipping/Service/RegionService.php',
            \BP . 'app/code/Weline/Shipping/Service/SystemEmbargoAdminService.php',
        ];
        foreach ($paths as $path) {
            $src = (string)\file_get_contents($path);
            self::assertStringNotContainsString(
                'Symfony\\Component\\Intl',
                $src,
                $path . ' must not reference Symfony Intl'
            );
            self::assertStringContainsString(
                'Weline\\I18n\\Model\\I18n',
                $src,
                $path . ' must resolve country names via I18n'
            );
            self::assertStringContainsString('getCountries', $src);
        }
    }
}
