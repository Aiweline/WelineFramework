<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\LocaleDisplayRankService;

final class LocaleDisplayRankServiceTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleDisplayRankService::clearProcessCaches();
    }

    protected function tearDown(): void
    {
        LocaleDisplayRankService::clearProcessCaches();
    }

    public function testVersionIsNonEmpty(): void
    {
        self::assertNotSame('', LocaleDisplayRankService::version());
    }

    public function testMajorLocalesOutrankSmallMarkets(): void
    {
        self::assertGreaterThan(
            LocaleDisplayRankService::score('bg_BG'),
            LocaleDisplayRankService::score('zh_Hans_CN'),
        );
        self::assertGreaterThan(
            LocaleDisplayRankService::score('bg_BG'),
            LocaleDisplayRankService::score('en_US'),
        );
        self::assertGreaterThan(
            LocaleDisplayRankService::score('bg_BG'),
            LocaleDisplayRankService::score('ar_SA'),
        );
        self::assertGreaterThan(
            LocaleDisplayRankService::score('ar_SA'),
            LocaleDisplayRankService::score('en_US'),
            'en_US language tier must beat ar_SA even when SA has mid country weight',
        );
    }

    public function testSortLanguageMapPutsMajorMarketsBeforeBulgariaAndPinsStableTies(): void
    {
        $map = [
            'bg_BG' => ['code' => 'bg_BG', 'country_code' => 'BG', 'country_name' => 'Bulgaria'],
            'ar_SA' => ['code' => 'ar_SA', 'country_code' => 'SA', 'country_name' => 'Saudi Arabia'],
            'zh_Hans_CN' => ['code' => 'zh_Hans_CN', 'country_code' => 'CN', 'country_name' => 'China'],
            'en_US' => ['code' => 'en_US', 'country_code' => 'US', 'country_name' => 'United States'],
        ];

        $ordered = \array_keys(LocaleDisplayRankService::sortLanguageMap($map));
        self::assertSame(
            ['zh_Hans_CN', 'en_US', 'ar_SA', 'bg_BG'],
            $ordered,
            'got: ' . \implode(',', $ordered),
        );
    }

    public function testSortLocaleCodesMatchesMapOrder(): void
    {
        $codes = LocaleDisplayRankService::sortLocaleCodes([
            'bg_BG',
            'ar_SA',
            'zh_Hans_CN',
            'en_US',
        ]);
        self::assertSame(['zh_Hans_CN', 'en_US', 'ar_SA', 'bg_BG'], $codes);
    }

    public function testHotCacheContractMentionsRankVersion(): void
    {
        $taglib = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Taglib/LanguageSwitcher.php'
        );
        self::assertStringContainsString('LocaleDisplayRankService', $taglib);
        self::assertStringContainsString('sortLanguageMap', $taglib);
        self::assertStringContainsString('preserveExplicitOrder', $taglib);
        self::assertStringContainsString('rank:', $taglib);
        self::assertStringContainsString('LocaleDisplayRankService::version()', $taglib);
    }
}
