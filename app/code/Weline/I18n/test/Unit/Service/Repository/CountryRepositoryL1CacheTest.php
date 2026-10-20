<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\Repository;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\I18n\Service\Repository\CountryRepository;

/**
 * Ch4：CountryRepository installed/installedActive 预热后二次获取 0 SQL。
 */
final class CountryRepositoryL1CacheTest extends TestCase
{
    protected function setUp(): void
    {
        CountryRepository::clearProcessCache();
    }

    protected function tearDown(): void
    {
        CountryRepository::clearProcessCache();
    }

    public function testSecondCallForSameSliceDoesNotQueryDb(): void
    {
        $countries = new CountryRepositoryL1CacheCountries([
            [
                Countries::schema_fields_CODE => 'CN',
                Countries::schema_fields_FLAG => 'cn',
                Countries::schema_fields_IS_ACTIVE => 1,
                Countries::schema_fields_IS_INSTALL => 1,
            ],
            [
                Countries::schema_fields_CODE => 'US',
                Countries::schema_fields_FLAG => 'us',
                Countries::schema_fields_IS_ACTIVE => 1,
                Countries::schema_fields_IS_INSTALL => 1,
            ],
        ]);
        $names = new CountryRepositoryL1CacheNames([
            [
                Name::schema_fields_COUNTRY_CODE => 'CN',
                Name::schema_fields_DISPLAY_NAME => '中国',
            ],
            [
                Name::schema_fields_COUNTRY_CODE => 'US',
                Name::schema_fields_DISPLAY_NAME => '美国',
            ],
        ]);
        $repository = new CountryRepository($countries, $names);

        $first = $repository->installedActive('zh_Hans_CN');
        $second = $repository->installedActive('zh_Hans_CN');

        self::assertSame(1, $countries->fetchCount);
        self::assertSame(1, $names->fetchCount);
        self::assertCount(2, $first);
        self::assertSame($first, $second);
        self::assertSame('中国', $first[0]->displayName);

        $repository->installed('zh_Hans_CN');
        self::assertSame(2, $countries->fetchCount, 'installed() is a different slice and must load once');
        self::assertSame(2, $names->fetchCount);

        CountryRepository::clearProcessCache();
        $repository->installedActive('zh_Hans_CN');
        self::assertSame(3, $countries->fetchCount, 'clearProcessCache must force a reload');
    }
}

final class CountryRepositoryL1CacheCountries extends Countries
{
    public int $fetchCount = 0;

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function __call($method, $args)
    {
        if ($method === 'fetchArray') {
            $this->fetchCount++;

            return $this->rows;
        }

        return $this;
    }
}

final class CountryRepositoryL1CacheNames extends Name
{
    public int $fetchCount = 0;

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function __call($method, $args)
    {
        if ($method === 'fetchArray') {
            $this->fetchCount++;

            return $this->rows;
        }

        return $this;
    }
}
