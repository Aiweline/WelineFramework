<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\RegionCascadeEnsureService;

final class RegionCascadeEnsureServiceTest extends TestCase
{
    private RegionCascadeEnsureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RegionCascadeEnsureService(ObjectManager::getInstance());
    }

    public function testHasPackForSeededCountries(): void
    {
        foreach (['CN', 'HK', 'MO', 'TW'] as $code) {
            self::assertTrue($this->service->hasPack($code), $code . ' pack should exist');
            $pack = $this->service->loadPack($code);
            self::assertIsArray($pack);
            self::assertNotEmpty($pack['regions'] ?? []);
            self::assertSame($code, strtoupper((string)($pack['country_code'] ?? $code)));
        }
    }

    public function testMissingPackReturnsNull(): void
    {
        self::assertFalse($this->service->hasPack('ZZ'));
        self::assertNull($this->service->loadPack('ZZ'));
        $result = $this->service->ensureCountry('ZZ');
        self::assertTrue($result['skipped']);
        self::assertSame('no_pack', $result['reason']);
        self::assertSame(0, $result['imported']);
    }

    public function testInvalidCountrySkipped(): void
    {
        $result = $this->service->ensureCountry('macau');
        self::assertTrue($result['skipped']);
        self::assertSame('invalid_country', $result['reason']);
    }

    public function testMacauPackHasProvinceNodes(): void
    {
        $pack = $this->service->loadPack('MO');
        self::assertNotNull($pack);
        $types = array_map(static fn(array $n): string => (string)($n['type'] ?? ''), $pack['regions']);
        self::assertContains('province', $types);
        self::assertGreaterThanOrEqual(3, count($pack['regions']));
    }
}
