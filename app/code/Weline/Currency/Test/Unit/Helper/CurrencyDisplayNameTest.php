<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Helper\CurrencyDisplayName;

final class CurrencyDisplayNameTest extends TestCase
{
    protected function setUp(): void
    {
        CurrencyDisplayName::clearProcessCache();
    }

    public function testGreekLocaleUsesIcuNamesNotChinese(): void
    {
        if (!class_exists(\ResourceBundle::class)) {
            self::markTestSkipped('ext-intl ResourceBundle required');
        }

        foreach (['el_GR', 'el'] as $locale) {
            $usd = CurrencyDisplayName::forCode('USD', $locale);
            $cny = CurrencyDisplayName::forCode('CNY', $locale);
            $sek = CurrencyDisplayName::forCode('SEK', $locale);

            self::assertNotSame('', $usd, $locale . ' USD');
            self::assertNotSame('', $cny, $locale . ' CNY');
            self::assertNotSame('', $sek, $locale . ' SEK');
            self::assertStringNotContainsString('美元', $usd);
            self::assertStringNotContainsString('人民币', $cny);
            self::assertStringNotContainsString('瑞典克朗', $sek);
            self::assertMatchesRegularExpression('/\p{Greek}/u', $usd);
            self::assertMatchesRegularExpression('/\p{Greek}/u', $cny);
        }
    }

    public function testLocalOverrideWinsOverIcu(): void
    {
        self::assertSame(
            '自定义美元',
            CurrencyDisplayName::forCode('USD', 'el_GR', '自定义美元')
        );
    }

    public function testNonChineseFallsBackToEnglishWhenLocaleMissing(): void
    {
        if (!class_exists(\ResourceBundle::class)) {
            self::markTestSkipped('ext-intl ResourceBundle required');
        }

        // Artificial locale with no ICU currency pack → en fallback.
        $name = CurrencyDisplayName::forCode('USD', 'xx_ZZ');
        self::assertSame('US Dollar', $name);
    }

    public function testChineseFamilyDetected(): void
    {
        self::assertTrue(CurrencyDisplayName::isChineseFamily('zh_Hans_CN'));
        self::assertTrue(CurrencyDisplayName::isChineseFamily('zh-CN'));
        self::assertFalse(CurrencyDisplayName::isChineseFamily('el_GR'));
        self::assertFalse(CurrencyDisplayName::isChineseFamily('en_US'));
    }

    public function testLocaleCandidatesPreferFullThenLanguage(): void
    {
        self::assertSame(['el_GR', 'el'], CurrencyDisplayName::localeCandidates('el_GR'));
        self::assertSame(['el'], CurrencyDisplayName::localeCandidates('el'));
    }
}
