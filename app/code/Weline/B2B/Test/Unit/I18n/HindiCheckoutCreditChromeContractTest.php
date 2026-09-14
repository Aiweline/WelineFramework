<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * hi_IN storefront credit chrome must not bake Chinese via compile-time <lang>.
 */
final class HindiCheckoutCreditChromeContractTest extends TestCase
{
    public function testOrderNoteUsesRuntimeTranslateNotCompileLang(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/templates/frontend/widgets/checkout-tob-order-note.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("\$t('批发结账先付含税商品小计 30% 定金，尾款约 70%（含运费）待审批后再付；营销券与满减不可用。')", $src);
        self::assertStringContainsString('WidgetI18n::label', $src);
        self::assertStringNotContainsString('<lang>批发结账', $src);
        self::assertStringNotContainsString('<lang>批发订单</lang>', $src);
    }

    public function testDepositNoteUsesWidgetI18nForPathLocale(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('WidgetI18n::label', $src);
        self::assertStringContainsString("\$t('批发信用说明')", $src);
    }

    public function testHindiCsvTranslatesWholesaleCreditHelpLabel(): void
    {
        $path = dirname(__DIR__, 3) . '/i18n/hi_IN.csv';
        self::assertFileExists($path);
        $hi = '';
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $row = str_getcsv($line, ',', '"', '\\');
            if (($row[0] ?? '') === '批发信用说明') {
                $hi = (string)($row[1] ?? '');
                break;
            }
        }
        self::assertNotSame('', $hi);
        self::assertNotSame('批发信用说明', $hi);
        self::assertMatchesRegularExpression('/\p{Devanagari}/u', $hi);
    }
}
