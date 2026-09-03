<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterCampaignLinkWidgetContractTest extends TestCase
{
    public function testFooterCampaignLinkRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Marketing/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-campaign-link', $widgets);
        $widget = $widgets['footer-campaign-link'];
        self::assertSame('footer-payment-account-links', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Marketing::templates/frontend/widgets/footer-campaign-link.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('footer-payment-account-links', $injection['slot'] ?? null);
        self::assertSame(10, (int)($injection['sort_order'] ?? -1));
        self::assertSame('活动', $injection['config']['label'] ?? null);
    }

    public function testFooterCampaignLinkTemplatePointsToPromotionDeals(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-campaign-link.phtml'
        );
        self::assertStringContainsString('data-testid="footer-campaign-link"', $template);
        self::assertStringContainsString("@url{'promotion/deals'}", $template);
        self::assertStringNotContainsString("@url{'marketing/campaign'}", $template);
        self::assertStringContainsString('活动', $template);
        self::assertStringContainsString('WidgetI18n::label($labelSource', $template);
    }
}
