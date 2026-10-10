<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterHelpLinksWidgetContractTest extends TestCase
{
    public function testFooterMyAccountLinkRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $tpl = 'Weline_Customer::templates/frontend/widgets/footer-my-account-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-my-account-link.phtml');
        self::assertStringContainsString('@widget.code {footer-my-account-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-help-links}', $src);
        self::assertStringContainsString('"layout_type":"homepage"', $src);
        self::assertStringContainsString('"slot":"footer-help-links"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testFooterMyOrdersLinkRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $tpl = 'Weline_Customer::templates/frontend/widgets/footer-my-orders-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-my-orders-link.phtml');
        self::assertStringContainsString('@widget.code {footer-my-orders-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-help-links}', $src);
        self::assertStringContainsString('"slot":"footer-help-links"', $src);
        self::assertStringContainsString('"required":true', $src);
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
