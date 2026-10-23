<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Test\Unit\Lead;

use PHPUnit\Framework\TestCase;
use Weline\AiAcquisition\Service\Catalog\ContinentCatalog;
use Weline\AiAcquisition\Service\Catalog\CountryCatalog;

/**
 * CountryCatalog 纯函数测试：覆盖常见贸易国与未知回退。
 */
final class CountryCatalogTest extends TestCase
{
    public function testCoversCommonTradeCountries(): void
    {
        $groups = [
            ContinentCatalog::NA => ['US', 'CA', 'MX', 'GT'],
            ContinentCatalog::SA => ['BR', 'AR', 'CL', 'CO', 'PE', 'VE'],
            ContinentCatalog::EU => [
                'GB', 'DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'SE', 'NO', 'DK', 'FI',
                'PL', 'PT', 'IE', 'AT', 'CH', 'CZ', 'GR', 'TR', 'RU', 'UA', 'RO', 'HU',
            ],
            ContinentCatalog::AS => [
                'JP', 'KR', 'CN', 'HK', 'TW', 'SG', 'MY', 'TH', 'VN', 'ID', 'PH',
                'IN', 'PK', 'BD', 'AE', 'SA', 'QA', 'KW', 'IL', 'JO',
            ],
            ContinentCatalog::AF => ['EG', 'ZA', 'NG', 'KE', 'MA', 'ET', 'GH', 'TZ'],
            ContinentCatalog::OC => ['AU', 'NZ'],
        ];

        $known = [];
        foreach ($groups as $continent => $codes) {
            foreach ($codes as $code) {
                $country = CountryCatalog::resolve($code);
                self::assertSame($code, $country['country_code'], $code . ' 应可解析');
                self::assertSame($continent, $country['continent_code'], $code . ' 洲归属错误');
                self::assertNotSame(CountryCatalog::UNKNOWN_NAME, $country['country_name']);
                self::assertNotSame('', $country['timezone'], $code . ' 缺少时区');
                $known[$code] = true;
            }
        }

        self::assertGreaterThanOrEqual(60, \count($known));
    }

    public function testResolvesCodeNameAndAliases(): void
    {
        self::assertSame('US', CountryCatalog::resolve('us')['country_code']);
        self::assertSame('US', CountryCatalog::resolve('United States')['country_code']);
        self::assertSame('US', CountryCatalog::resolve('united states')['country_code']);
        self::assertSame('US', CountryCatalog::resolve('美国')['country_code']);
        self::assertSame('US', CountryCatalog::resolve('USA')['country_code']);
        self::assertSame('GB', CountryCatalog::resolve('UK')['country_code']);
        self::assertSame('NL', CountryCatalog::resolve('Holland')['country_code']);
        self::assertSame('AE', CountryCatalog::resolve('UAE')['country_code']);
        self::assertSame('KR', CountryCatalog::resolve('South Korea')['country_code']);
        self::assertSame('VN', CountryCatalog::resolve('Viet Nam')['country_code']);
        self::assertSame('CZ', CountryCatalog::resolve('Czech Republic')['country_code']);
        self::assertSame('HK', CountryCatalog::resolve('香港')['country_code']);
        self::assertSame('TW', CountryCatalog::resolve('台湾')['country_code']);
    }

    public function testUnknownCountryFallsBackToXx(): void
    {
        $expected = [
            'country_code' => 'XX',
            'country_name' => 'UNKNOWN',
            'continent_code' => ContinentCatalog::UNKNOWN,
            'timezone' => '',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ];

        self::assertSame($expected, CountryCatalog::resolve('ZZ'));
        self::assertSame($expected, CountryCatalog::resolve('Narnia'));
        self::assertSame($expected, CountryCatalog::resolve(''));
    }

    public function testContinentFor(): void
    {
        self::assertSame(ContinentCatalog::NA, CountryCatalog::continentFor('US'));
        self::assertSame(ContinentCatalog::EU, CountryCatalog::continentFor('DE'));
        self::assertSame(ContinentCatalog::AS, CountryCatalog::continentFor('CN'));
        self::assertSame(ContinentCatalog::SA, CountryCatalog::continentFor('BR'));
        self::assertSame(ContinentCatalog::AF, CountryCatalog::continentFor('ZA'));
        self::assertSame(ContinentCatalog::OC, CountryCatalog::continentFor('AU'));
        self::assertSame(ContinentCatalog::UNKNOWN, CountryCatalog::continentFor('ZZ'));
        self::assertSame(ContinentCatalog::EU, CountryCatalog::continentFor('Europe'));
    }

    public function testResolvedRowContainsExpectedShape(): void
    {
        $germany = CountryCatalog::resolve('DE');

        self::assertSame('Germany', $germany['country_name']);
        self::assertSame('Europe/Berlin', $germany['timezone']);
        self::assertSame(51.1657, $germany['latitude']);
        self::assertSame(10.4515, $germany['longitude']);
    }
}
