<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterHelpLinksWidgetContractTest extends TestCase
{
    public function testFooterMyAccountLinkRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-my-account-link', $widgets);
        $widget = $widgets['footer-my-account-link'];
        self::assertSame('footer-help-links', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Customer::templates/frontend/widgets/footer-my-account-link.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('*', $injection['layout_type'] ?? null);
        self::assertSame('footer-help-links', $injection['slot'] ?? null);
        self::assertSame('footer', $injection['area'] ?? null);
        self::assertSame(0, (int)($injection['sort_order'] ?? -1));
        self::assertSame('我的账户', $injection['config']['label'] ?? null);
    }

    public function testFooterMyOrdersLinkRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-my-orders-link', $widgets);
        $widget = $widgets['footer-my-orders-link'];
        self::assertSame('footer-help-links', $widget['slot'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame(10, (int)($injection['sort_order'] ?? -1));
        self::assertSame('我的订单', $injection['config']['label'] ?? null);
    }

    public function testFooterHelpLinkTemplatesPointToAccount(): void
    {
        $account = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-my-account-link.phtml'
        );
        $orders = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-my-orders-link.phtml'
        );
        self::assertStringContainsString('data-testid="footer-my-account-link"', $account);
        self::assertStringContainsString("@url{'customer/account/index'}", $account);
        self::assertStringContainsString('footer-help-links', $account);
        self::assertStringContainsString('data-testid="footer-my-orders-link"', $orders);
        self::assertStringContainsString("@url{'customer/account/index'}#orders", $orders);
        self::assertStringContainsString('footer-help-links', $orders);
    }
}
