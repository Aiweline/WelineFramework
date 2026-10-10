<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterContactServiceLinkWidgetContractTest extends TestCase
{
    public function testFooterContactServiceLinkRegistersHomepageDefaultAndRemainsGloballyAvailable(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
        $tpl = 'Weline_CustomerService::templates/Frontend/widgets/footer-contact-service-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/footer-contact-service-link.phtml');
        self::assertStringContainsString('@widget.code {footer-contact-service-link}', $src);
        self::assertStringContainsString('@widget.page_layouts {["*"]}', $src);
        self::assertStringContainsString('@widget.slot {footer-help-links}', $src);
        self::assertStringContainsString('"slot":"footer-help-links"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testFooterContactServiceLinkOpensFloatingChat(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-contact-service-link.phtml'
        );
        self::assertStringContainsString('data-testid="footer-contact-service-link"', $template);
        self::assertStringContainsString('data-cs-footer-open-chat', $template);
        self::assertStringContainsString('__WelineLoadCustomerServiceWidget', $template);
        self::assertStringContainsString('footer-help-links', $template);
        self::assertStringContainsString('联系客服', $template);
    }
}
