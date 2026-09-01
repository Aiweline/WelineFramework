<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterPromoteLinkWidgetContractTest extends TestCase
{
    public function testFooterPromoteLinkRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Affiliate/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-promote-link', $widgets);
        $widget = $widgets['footer-promote-link'];
        self::assertSame('footer-partner-links', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Affiliate::templates/frontend/widgets/footer-promote-link.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('*', $injection['layout_type'] ?? null);
        self::assertSame('footer-partner-links', $injection['slot'] ?? null);
        self::assertSame('footer', $injection['area'] ?? null);
        self::assertSame(10, (int)($injection['sort_order'] ?? -1));
        self::assertSame('我要推广', $injection['config']['label'] ?? null);
    }

    public function testFooterPromoteLinkTemplatePointsToAffiliate(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-promote-link.phtml'
        );
        self::assertStringContainsString('data-testid="footer-promote-link"', $template);
        self::assertStringContainsString("@url{'affiliate'}", $template);
        self::assertStringContainsString('footer-partner-links', $template);
        self::assertStringContainsString('我要推广', $template);
    }
}
