<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Shipping\Service\RegionLocalNameResolver;

/**
 * 统一 L1：Region/Street/PostalPlace 名称使用显式命名进程袋（扁平 locale|id 键）。
 */
final class RegionLocalNameResolverBagTest extends TestCase
{
    private ReflectionProperty $region;
    private ReflectionProperty $street;
    private ReflectionProperty $postal;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(RegionLocalNameResolver::class);
        $this->region = $reflection->getProperty('processRegionCache');
        $this->street = $reflection->getProperty('processStreetCache');
        $this->postal = $reflection->getProperty('processPostalPlaceCache');
        foreach ([$this->region, $this->street, $this->postal] as $property) {
            $property->setAccessible(true);
            $property->setValue(null, []);
        }
        RegionLocalNameResolver::clearProcessCache();
    }

    protected function tearDown(): void
    {
        RegionLocalNameResolver::clearProcessCache();
    }

    public function testNamedFlatBagServesNamesWithoutDb(): void
    {
        $this->region->setValue(null, ['en_US|5' => 'North', 'zh_Hans_CN|5' => '北']);
        $this->street->setValue(null, ['en_US|9' => 'Main St']);
        $this->postal->setValue(null, ['en_US|11' => '10001']);

        $resolver = (new ReflectionClass(RegionLocalNameResolver::class))->newInstanceWithoutConstructor();

        self::assertSame([5 => 'North'], $resolver->namesByRegionIds([5], 'en_US'));
        self::assertSame('北', $resolver->nameByRegionId(5, 'zh_Hans_CN'));
        self::assertSame([9 => 'Main St'], $resolver->namesByStreetIds([9], 'en_US'));
        self::assertSame('10001', $resolver->nameByPostalPlaceId(11, 'en_US'));

        $stats = DictionaryCacheNamespace::bagStats();
        self::assertSame(2, $stats['shipping.region_names']['entries'] ?? -1);
        self::assertSame(1, $stats['shipping.street_names']['entries'] ?? -1);
        self::assertSame(1, $stats['shipping.postal_place_names']['entries'] ?? -1);

        RegionLocalNameResolver::clearProcessCache();
        $afterClear = DictionaryCacheNamespace::bagStats();
        self::assertSame(0, $afterClear['shipping.region_names']['entries'] ?? -1);
    }
}
