<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterPromoteLinkWidgetContractTest extends TestCase
{
    public function testFooterPromoteLinkRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Affiliate/widget.php';
        $tpl = 'Weline_Affiliate::templates/frontend/widgets/footer-promote-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-promote-link.phtml');
        self::assertStringContainsString('@widget.code {footer-promote-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-partner-links}', $src);
        self::assertStringContainsString('"slot":"footer-partner-links"', $src);
        self::assertStringContainsString('"required":true', $src);
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
