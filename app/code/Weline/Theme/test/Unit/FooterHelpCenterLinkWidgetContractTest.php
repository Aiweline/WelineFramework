<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class FooterHelpCenterLinkWidgetContractTest extends TestCase
{
    public function testFooterFaqLinkRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 2) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        $found = null;
        foreach ($widgets as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['code'] ?? '') === 'footer-faq-link') {
                $found = $entry;
                break;
            }
        }
        self::assertNotNull($found);
        self::assertSame('footer-help-links', $found['slot'] ?? null);
        $injection = $found['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('footer-help-links', $injection['slot'] ?? null);
        self::assertSame(40, (int)($injection['sort_order'] ?? -1));
        self::assertSame('FAQ/常见问题', $injection['config']['label'] ?? null);
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
