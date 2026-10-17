<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllCurrencyViewContractsPass().
 */
final class CurrencyViewContractsTest extends TestCase
{
    public function testAllCurrencyViewContractsPass(): void
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
            'CurrencySwitcherLivePreviewPath::testCurrencyJsPeelsLivePreviewMountInFallbackBuilder' => function () {
            $this->assert_CurrencySwitcherLivePreviewPath_testCurrencyJsPeelsLivePreviewMountInFallbackBuilder();
        },
            'FooterCurrencyRatesLinkWidget::testFooterCurrencyRatesLinkRegistersDefaultInjection' => function () {
            $this->assert_FooterCurrencyRatesLinkWidget_testFooterCurrencyRatesLinkRegistersDefaultInjection();
        },
            'FooterCurrencyRatesLinkWidget::testFooterCurrencyRatesLinkTemplatePointsToCurrency' => function () {
            $this->assert_FooterCurrencyRatesLinkWidget_testFooterCurrencyRatesLinkTemplatePointsToCurrency();
        },
            'FooterCurrencyRatesLinkWidget::testCurrencyGuideTemplateExists' => function () {
            $this->assert_FooterCurrencyRatesLinkWidget_testCurrencyGuideTemplateExists();
        },
        ];
    }

private function assert_CurrencySwitcherLivePreviewPath_testCurrencyJsPeelsLivePreviewMountInFallbackBuilder(): void
{
    $runtime = $this->read_CurrencySwitcherLivePreviewPath('view/statics/js/currency.js');
    self::assertStringContainsString('peelLivePreviewPathMount', $runtime);
    self::assertStringContainsString('LIVE_PREVIEW_TOKEN_PATTERN', $runtime);
    self::assertStringContainsString('buildCurrencyUrlFromPath', $runtime);
    self::assertStringContainsString('never leave token segments', $runtime);
    self::assertStringContainsString('/~preview/${live.token}', $runtime);
    self::assertStringContainsString("__initialized === 3", $runtime);
    self::assertStringContainsString('__initialized: 3', $runtime);
    $builderAt = strpos($runtime, 'function buildCurrencyUrlFromPath');
    self::assertNotFalse($builderAt);
    $builderBody = substr($runtime, $builderAt, 1800);
    self::assertStringContainsString('peelLivePreviewPathMount(pathOnly)', $builderBody);
    self::assertStringContainsString("part === '~preview'", $builderBody);
    self::assertStringContainsString("part === '~site'", $builderBody);
    self::assertStringContainsString('!live.token && isBackendLocalizedPath', $builderBody);
}

private function read_CurrencySwitcherLivePreviewPath(string $relative): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/' . ltrim($relative, '/');
    $content = file_get_contents($path);
    self::assertIsString($content, $path);
    return $content;
}

private function assert_FooterCurrencyRatesLinkWidget_testFooterCurrencyRatesLinkRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/extends/module/Weline_Widget/Weline_Currency/widget.php';
    $tpl = 'Weline_Currency::templates/frontend/widgets/footer-currency-rates-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/view/templates/frontend/widgets/footer-currency-rates-link.phtml');
    self::assertStringContainsString('@widget.code {footer-currency-rates-link}', $src);
    self::assertStringContainsString('@widget.slot {footer-payment-account-links}', $src);
    self::assertStringContainsString('"slot":"footer-payment-account-links"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_FooterCurrencyRatesLinkWidget_testFooterCurrencyRatesLinkTemplatePointsToCurrency(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/view/templates/frontend/widgets/footer-currency-rates-link.phtml');
    self::assertStringContainsString('data-testid="footer-currency-rates-link"', $template);
    self::assertStringContainsString("@url{'currency'}", $template);
    self::assertStringContainsString('货币与汇率', $template);
}

private function assert_FooterCurrencyRatesLinkWidget_testCurrencyGuideTemplateExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/view/templates/frontend/currency/index.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="currency-guide-hub"', $src);
    self::assertStringContainsString('data-testid="currency-guide-policy"', $src);
    self::assertStringContainsString('支持的货币', $src);
}
}
