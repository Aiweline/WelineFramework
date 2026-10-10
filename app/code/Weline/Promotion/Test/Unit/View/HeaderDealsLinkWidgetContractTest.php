<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderDealsLinkWidgetContractTest extends TestCase
{
    public function testHeaderDealsLinkRegistersHomepageDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Promotion/widget.php';
        $tpl = 'Weline_Promotion::templates/frontend/widgets/header-deals-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/header-deals-link.phtml');
        self::assertStringContainsString('@widget.code {header-deals-link}', $src);
        self::assertStringContainsString('@widget.slot {header-nav-extensions}', $src);
        self::assertStringContainsString('"slot":"header-nav-extensions"', $src);
        self::assertStringContainsString('"required":true', $src);
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
