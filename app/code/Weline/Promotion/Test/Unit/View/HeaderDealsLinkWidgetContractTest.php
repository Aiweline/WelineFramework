<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderDealsLinkWidgetContractTest extends TestCase
{
    public function testHeaderDealsLinkRegistersHomepageDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Promotion/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('header-deals-link', $widgets);
        $widget = $widgets['header-deals-link'];
        self::assertSame('header-nav-extensions', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Promotion::templates/frontend/widgets/header-deals-link.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('header-nav-extensions', $injection['slot'] ?? null);
        self::assertSame('header', $injection['area'] ?? null);
        self::assertSame(10, (int)($injection['sort_order'] ?? -1));
        self::assertTrue((bool)($injection['required'] ?? false));
        self::assertSame('今日特价', $injection['config']['label'] ?? null);
    }

    public function testHeaderDealsLinkTemplateUsesPromotionDealsRoute(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/header-deals-link.phtml'
        );
        self::assertStringContainsString('data-testid="header-deals-link"', $template);
        self::assertStringContainsString("@url{'promotion/deals'}", $template);
        self::assertStringContainsString('@widget.slot {header-nav-extensions}', $template);
        self::assertStringNotContainsString("'/promotion/deals'", $template);
    }
}
