<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class RegionListLocalizationBatchContractTest extends TestCase
{
    public function testRegionListUsesBatchLocalizedNameLookup(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/RegionService.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('namesByRegionIds($regionIds', $source);
    }
}
