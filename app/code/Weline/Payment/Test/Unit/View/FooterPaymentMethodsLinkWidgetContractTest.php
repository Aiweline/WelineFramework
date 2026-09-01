<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterPaymentMethodsLinkWidgetContractTest extends TestCase
{
    public function testFooterPaymentMethodsLinkRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Payment/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-payment-methods-link', $widgets);
        $widget = $widgets['footer-payment-methods-link'];
        self::assertSame('footer-payment-account-links', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Payment::templates/frontend/widgets/footer-payment-methods-link.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('*', $injection['layout_type'] ?? null);
        self::assertSame('footer-payment-account-links', $injection['slot'] ?? null);
        self::assertSame('footer', $injection['area'] ?? null);
        self::assertSame(0, (int)($injection['sort_order'] ?? -1));
        self::assertSame('支付方式', $injection['config']['label'] ?? null);
    }

    public function testFooterPaymentMethodsLinkTemplatePointsToGuide(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-payment-methods-link.phtml'
        );
        self::assertStringContainsString('data-testid="footer-payment-methods-link"', $template);
        self::assertStringContainsString("@url{'guide/payment'}", $template);
        self::assertStringContainsString('footer-payment-account-links', $template);
        self::assertStringContainsString('支付方式', $template);
    }
}
