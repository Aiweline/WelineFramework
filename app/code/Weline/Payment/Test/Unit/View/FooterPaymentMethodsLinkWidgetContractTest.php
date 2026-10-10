<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterPaymentMethodsLinkWidgetContractTest extends TestCase
{
    public function testFooterPaymentMethodsLinkRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Payment/widget.php';
        $tpl = 'Weline_Payment::templates/Frontend/widgets/footer-payment-methods-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/footer-payment-methods-link.phtml');
        self::assertStringContainsString('@widget.code {footer-payment-methods-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-payment-account-links}', $src);
        self::assertStringContainsString('"slot":"footer-payment-account-links"', $src);
        self::assertStringContainsString('"required":true', $src);
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
