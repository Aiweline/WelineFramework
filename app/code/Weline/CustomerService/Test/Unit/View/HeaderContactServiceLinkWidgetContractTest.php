<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderContactServiceLinkWidgetContractTest extends TestCase
{
    public function testHeaderContactServiceLinkRegistersHomepageDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
        $tpl = 'Weline_CustomerService::templates/Frontend/widgets/header-contact-service-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/header-contact-service-link.phtml');
        self::assertStringContainsString('@widget.code {header-contact-service-link}', $src);
        self::assertStringContainsString('"required":true', $src);
        self::assertStringContainsString('@widget.page_layouts {["*"]}', $src);
    }

    public function testHeaderContactServiceLinkOpensFloatingChat(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/header-contact-service-link.phtml'
        );
        self::assertStringContainsString('data-testid="header-contact-service-link"', $template);
        self::assertStringContainsString('data-cs-header-open-chat', $template);
        self::assertStringContainsString('__WelineLoadCustomerServiceWidget', $template);
        self::assertStringContainsString('@widget.slot {header-nav-extensions}', $template);
        self::assertStringContainsString('客户服务', $template);
    }
}
