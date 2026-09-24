<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterCurrencyRatesLinkWidgetContractTest extends TestCase
{
    public function testFooterCurrencyRatesLinkRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Currency/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-currency-rates-link', $widgets);
        $widget = $widgets['footer-currency-rates-link'];
        self::assertSame('footer-payment-account-links', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Currency::templates/Frontend/widgets/footer-currency-rates-link.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('*', $injection['layout_type'] ?? null);
        self::assertSame('footer-payment-account-links', $injection['slot'] ?? null);
        self::assertSame(20, (int)($injection['sort_order'] ?? -1));
        self::assertSame('货币与汇率', $injection['config']['label'] ?? null);
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
