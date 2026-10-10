<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class FooterHelpCenterLinkWidgetContractTest extends TestCase
{
    public function testFooterFaqLinkRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 2) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        $tpl = 'Weline_Theme::theme/frontend/widgets/footer/footer-faq-link/default.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/widgets/footer/footer-faq-link/default.phtml'
        );
        self::assertStringContainsString('@widget.code {footer-faq-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-help-links}', $src);
        self::assertStringContainsString('@widget.placement {layout}', $src);
        self::assertStringContainsString('@widget.default_injections {[]}', $src);
        self::assertStringContainsString('@param label {default="FAQ/常见问题"', $src);
    }

    public function testFooterFaqLinkTemplatePointsToThemeFaq(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/widgets/footer/footer-faq-link/default.phtml'
        );
        self::assertStringContainsString('data-testid="footer-faq-link"', $template);
        self::assertStringContainsString("@url{'faq'}", $template);
        self::assertStringContainsString('footer-help-links', $template);
        self::assertStringNotContainsString('blog/category', $template);
        self::assertStringNotContainsString('category_slug', $template);
    }

    public function testFaqLayoutDeepLinksToBusinessGuides(): void
    {
        $layout = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/layouts/faq/default.phtml'
        );
        $hub = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Faq/Service/FaqHubContent.php'
        );
        self::assertStringContainsString('FaqHubContent', $layout);
        self::assertStringContainsString("'url' => 'guide/shipping'", $hub);
        self::assertStringContainsString("'url' => 'guide/returns'", $hub);
        self::assertStringContainsString("'url' => 'guide/payment'", $hub);
        self::assertStringContainsString('data-cs-footer-open-chat', $layout);
        self::assertStringContainsString("@url{'guide/shipping'}", $layout);
        self::assertStringContainsString("@url{'guide/returns'}", $layout);
    }
}
