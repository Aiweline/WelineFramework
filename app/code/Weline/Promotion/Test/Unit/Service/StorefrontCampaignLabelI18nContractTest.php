<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class StorefrontCampaignLabelI18nContractTest extends TestCase
{
    public function testResolveCampaignDisplayLabelTranslatesHanViaUnderscore(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/PromotionActivityThemeService.php',
        );
        self::assertStringContainsString('translateStorefrontCampaignLabel', $src);
        self::assertStringContainsString('WidgetI18n::label', $src);
    }

    public function testHindiCsvCoversDealsChrome(): void
    {
        $path = dirname(__DIR__, 3) . '/i18n/hi_IN.csv';
        self::assertFileExists($path);
        $hi = (string)file_get_contents($path);
        self::assertStringContainsString('今日特价,"आज के विशेष"', $hi);
        self::assertMatchesRegularExpression('/\\p{Devanagari}/u', $hi);
    }
}
