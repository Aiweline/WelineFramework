<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterHelpLinksWidgetContractTest extends TestCase
{
    public function testFooterShippingInfoLinkRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
        $tpl = 'Weline_Shipping::templates/frontend/widgets/footer-shipping-info-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-shipping-info-link.phtml');
        self::assertStringContainsString('@widget.code {footer-shipping-info-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-help-links}', $src);
        self::assertStringContainsString('"slot":"footer-help-links"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testFooterReturnsPolicyLinkRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
        $tpl = 'Weline_Shipping::templates/frontend/widgets/footer-returns-policy-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-returns-policy-link.phtml');
        self::assertStringContainsString('@widget.code {footer-returns-policy-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-help-links}', $src);
        self::assertStringContainsString('"slot":"footer-help-links"', $src);
        self::assertStringContainsString('"required":true', $src);
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
