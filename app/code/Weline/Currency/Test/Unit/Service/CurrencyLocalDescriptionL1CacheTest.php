<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Currency\Model\Currency\LocalDescription;
use Weline\Currency\Service\CurrencyLocalDescriptionService;
use Weline\Framework\Phrase\DictionaryCacheNamespace;

/**
 * Ch4 延伸：CurrencyLocalDescriptionService 进程袋改用显式命名 processBag，
 * 预热后同一 currency 二次获取不再触发 fetchArray（0 SQL）。
 */
final class CurrencyLocalDescriptionL1CacheTest extends TestCase
{
    protected function setUp(): void
    {
        CurrencyLocalDescriptionService::clearProcessCache();
    }

    protected function tearDown(): void
    {
        CurrencyLocalDescriptionService::clearProcessCache();
    }

    public function testLocalNamesUseNamedProcessBagAndSkipSecondQuery(): void
    {
        $localDescription = new CurrencyLocalDescriptionL1CacheFixture();
        $service = self::serviceWith($localDescription);
        $currencyId = \random_int(1_000_000, 9_999_999);

        $first = $service->getLocalNames($currencyId);
        self::assertSame(['en_US' => 'US Dollar'], $first);
        self::assertSame(1, $localDescription->fetchCount);

        $stats = DictionaryCacheNamespace::bagStats();
        self::assertArrayHasKey('currency.local_names', $stats);
        self::assertSame(1, $stats['currency.local_names']['entries']);

        $second = $service->getLocalNames($currencyId);
        self::assertSame($first, $second);
        self::assertSame(1, $localDescription->fetchCount, 'second getLocalNames() must be served from the process bag');

        CurrencyLocalDescriptionService::clearProcessCache();
        $afterClear = DictionaryCacheNamespace::bagStats();
        self::assertSame(0, $afterClear['currency.local_names']['entries'] ?? 0, 'clearProcessCache must empty the L1 bag');

        // L2（ScopeSharedMemo）仍在 TTL 内，结果继续正确；此处不再断言重新读库。
        self::assertSame($first, $service->getLocalNames($currencyId));
    }

    private static function serviceWith(LocalDescription $localDescription): CurrencyLocalDescriptionService
    {
        $reflection = new ReflectionClass(CurrencyLocalDescriptionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('localDescription');
        $property->setAccessible(true);
        $property->setValue($service, $localDescription);

        return $service;
    }
}

final class CurrencyLocalDescriptionL1CacheFixture extends LocalDescription
{
    public int $fetchCount = 0;

    public function __construct()
    {
    }

    public function clear(bool $with_query = true): static
    {
        return $this;
    }

    public function __call($method, $args)
    {
        if ($method === 'fetchArray') {
            $this->fetchCount++;

            return [[
                LocalDescription::schema_fields_local_code => 'en_US',
                LocalDescription::schema_fields_name => 'US Dollar',
            ]];
        }

        return $this;
    }
}
