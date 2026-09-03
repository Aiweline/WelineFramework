<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterHelpLinksWidgetContractTest extends TestCase
{
    public function testFooterShippingInfoLinkRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-shipping-info-link', $widgets);
        $widget = $widgets['footer-shipping-info-link'];
        self::assertSame('footer-help-links', $widget['slot'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('footer-help-links', $injection['slot'] ?? null);
        self::assertSame(20, (int)($injection['sort_order'] ?? -1));
        self::assertSame('配送说明', $injection['config']['label'] ?? null);
    }

    public function testFooterReturnsPolicyLinkRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-returns-policy-link', $widgets);
        $widget = $widgets['footer-returns-policy-link'];
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame(30, (int)($injection['sort_order'] ?? -1));
        self::assertSame('退换政策', $injection['config']['label'] ?? null);
    }

    public function testFooterHelpLinkTemplatesPointToGuidePages(): void
    {
        $shipping = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-shipping-info-link.phtml'
        );
        $returns = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-returns-policy-link.phtml'
        );
        self::assertStringContainsString("@url{'guide/shipping'}", $shipping);
        self::assertStringContainsString("@url{'guide/returns'}", $returns);
        self::assertStringContainsString('footer-help-links', $shipping);
        self::assertStringContainsString('footer-help-links', $returns);
    }

    public function testGuideControllersAndTemplatesExist(): void
    {
        self::assertFileExists(
            dirname(__DIR__, 3) . '/Controller/Frontend/Guide/Shipping.php'
        );
        self::assertFileExists(
            dirname(__DIR__, 3) . '/Controller/Frontend/Guide/Returns.php'
        );
        self::assertFileExists(
            dirname(__DIR__, 3) . '/view/templates/frontend/guide/shipping.phtml'
        );
        self::assertFileExists(
            dirname(__DIR__, 3) . '/view/templates/frontend/guide/returns.phtml'
        );
        $shippingTpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/guide/shipping.phtml'
        );
        $returnsTpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/guide/returns.phtml'
        );
        self::assertStringContainsString('data-testid="shipping-guide"', $shippingTpl);
        self::assertStringContainsString('data-testid="shipping-returns"', $returnsTpl);
        self::assertStringContainsString('amazon-doc__panel', $shippingTpl);
        self::assertStringContainsString('amazon-doc__panel', $returnsTpl);
        self::assertStringContainsString('一、发货时效', $shippingTpl);
        self::assertStringContainsString('一、适用条件', $returnsTpl);
        self::assertStringNotContainsString('width: min(52rem', $shippingTpl);
        self::assertStringNotContainsString('width: min(52rem', $returnsTpl);
    }
}
