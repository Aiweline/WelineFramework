<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterContactServiceLinkWidgetContractTest extends TestCase
{
    public function testFooterContactServiceLinkRegistersHomepageDefaultAndRemainsGloballyAvailable(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-contact-service-link', $widgets);
        $widget = $widgets['footer-contact-service-link'];
        self::assertSame(['*'], $widget['page_layouts'] ?? null);
        self::assertSame('footer-help-links', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_CustomerService::templates/frontend/widgets/footer-contact-service-link.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('footer-help-links', $injection['slot'] ?? null);
        self::assertSame(50, (int)($injection['sort_order'] ?? -1));
        self::assertSame('联系客服', $injection['config']['label'] ?? null);
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
