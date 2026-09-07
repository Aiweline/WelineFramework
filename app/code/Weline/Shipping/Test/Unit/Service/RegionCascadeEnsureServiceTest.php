<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\RegionCascadeEnsureService;

final class RegionCascadeEnsureServiceTest extends TestCase
{
    private RegionCascadeEnsureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RegionCascadeEnsureService();
    }

    public function testCatalogCoverageForSeededCountries(): void
    {
        foreach (['CN', 'HK', 'MO', 'TW', 'AU'] as $code) {
            self::assertTrue($this->service->hasCatalogCoverage($code), $code . ' catalog should exist');
            self::assertTrue($this->service->hasPack($code), $code . ' hasPack aliases catalog');
        }
    }

    public function testMissingCatalogAndJsonPackGone(): void
    {
        self::assertFalse($this->service->hasPack('ZZ'));
        self::assertTrue($this->service->hasCatalogCoverage('PY'));
        self::assertTrue($this->service->hasCatalogCoverage('EG'));
        self::assertNull($this->service->loadPack('CN'));
        $result = $this->service->ensureCountry('ZZ');
        self::assertTrue($result['skipped']);
        self::assertContains($result['reason'], ['catalog_mode', 'catalog_not_covered', 'use_addresscatalog_import']);
        self::assertSame(0, $result['imported']);
    }

    public function testInvalidCountrySkipped(): void
    {
        $result = $this->service->ensureCountry('macau');
        self::assertTrue($result['skipped']);
        self::assertSame('invalid_country', $result['reason']);
    }
}
