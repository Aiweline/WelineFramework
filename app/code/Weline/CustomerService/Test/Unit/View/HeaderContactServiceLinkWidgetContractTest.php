<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderContactServiceLinkWidgetContractTest extends TestCase
{
    public function testHeaderContactServiceLinkRegistersHomepageDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('header-contact-service-link', $widgets);
        $widget = $widgets['header-contact-service-link'];
        self::assertSame(['*'], $widget['page_layouts'] ?? null);
        self::assertSame('header-nav-extensions', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_CustomerService::templates/frontend/widgets/header-contact-service-link.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('header-nav-extensions', $injection['slot'] ?? null);
        self::assertSame(20, (int)($injection['sort_order'] ?? -1));
        self::assertTrue((bool)($injection['required'] ?? false));
        self::assertSame('客户服务', $injection['config']['label'] ?? null);
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
