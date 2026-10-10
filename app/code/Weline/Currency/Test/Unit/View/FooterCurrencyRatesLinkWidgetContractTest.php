<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterCurrencyRatesLinkWidgetContractTest extends TestCase
{
    public function testFooterCurrencyRatesLinkRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Currency/widget.php';
        $tpl = 'Weline_Currency::templates/Frontend/widgets/footer-currency-rates-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/footer-currency-rates-link.phtml');
        self::assertStringContainsString('@widget.code {footer-currency-rates-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-payment-account-links}', $src);
        self::assertStringContainsString('"slot":"footer-payment-account-links"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testFooterCurrencyRatesLinkTemplatePointsToCurrency(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-currency-rates-link.phtml'
        );
        self::assertStringContainsString('data-testid="footer-currency-rates-link"', $template);
        self::assertStringContainsString("@url{'currency'}", $template);
        self::assertStringContainsString('货币与汇率', $template);
    }

    public function testCurrencyGuideTemplateExists(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Frontend/currency/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('data-testid="currency-guide-hub"', $src);
        self::assertStringContainsString('data-testid="currency-guide-policy"', $src);
        self::assertStringContainsString('支持的货币', $src);
    }
}
