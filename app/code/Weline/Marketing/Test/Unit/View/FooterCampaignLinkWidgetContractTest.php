<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterCampaignLinkWidgetContractTest extends TestCase
{
    public function testFooterCampaignLinkRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Marketing/widget.php';
        $tpl = 'Weline_Marketing::templates/frontend/widgets/footer-campaign-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-campaign-link.phtml');
        self::assertStringContainsString('@widget.code {footer-campaign-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-payment-account-links}', $src);
        self::assertStringContainsString('"slot":"footer-payment-account-links"', $src);
        self::assertStringContainsString('"required":true', $src);
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
