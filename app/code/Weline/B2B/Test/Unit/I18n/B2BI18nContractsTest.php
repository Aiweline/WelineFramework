<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllB2BI18nContractsPass().
 */
final class B2BI18nContractsTest extends TestCase
{
    public function testAllB2BI18nContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'HindiCheckoutCreditChrome::testOrderNoteUsesRuntimeTranslateNotCompileLang' => function () {
            $this->assert_HindiCheckoutCreditChrome_testOrderNoteUsesRuntimeTranslateNotCompileLang();
        },
            'HindiCheckoutCreditChrome::testDepositNoteUsesWidgetI18nForPathLocale' => function () {
            $this->assert_HindiCheckoutCreditChrome_testDepositNoteUsesWidgetI18nForPathLocale();
        },
            'HindiCheckoutCreditChrome::testHindiCsvTranslatesWholesaleCreditHelpLabel' => function () {
            $this->assert_HindiCheckoutCreditChrome_testHindiCsvTranslatesWholesaleCreditHelpLabel();
        },
        ];
    }

private function assert_HindiCheckoutCreditChrome_testOrderNoteUsesRuntimeTranslateNotCompileLang(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/widgets/checkout-tob-order-note.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("\$t('批发结账先付含税商品小计 30% 定金，尾款约 70%（含运费）待审批后再付；营销券与满减不可用。')", $src);
    self::assertStringContainsString('WidgetI18n::label', $src);
    self::assertStringNotContainsString('<lang>批发结账', $src);
    self::assertStringNotContainsString('<lang>批发订单</lang>', $src);
}

private function assert_HindiCheckoutCreditChrome_testDepositNoteUsesWidgetI18nForPathLocale(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('WidgetI18n::label', $src);
    self::assertStringContainsString("\$t('批发信用说明')", $src);
}

private function assert_HindiCheckoutCreditChrome_testHindiCsvTranslatesWholesaleCreditHelpLabel(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/i18n/hi_IN.csv';
    self::assertFileExists($path);
    $hi = '';
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $row = str_getcsv($line, ',', '"', '\\');
        if (($row[0] ?? '') === '批发信用说明') {
            $hi = (string) ($row[1] ?? '');
            break;
        }
    }
    self::assertNotSame('', $hi);
    self::assertNotSame('批发信用说明', $hi);
    self::assertMatchesRegularExpression('/\p{Devanagari}/u', $hi);
}
}
