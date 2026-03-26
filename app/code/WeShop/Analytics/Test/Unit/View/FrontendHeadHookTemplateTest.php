<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class FrontendHeadHookTemplateTest extends TestCase
{
    public function testAnalyticsHeadBeforeHookTemplateUsesSlotAwareQueryProvider(): void
    {
        $template = file_get_contents(
            __DIR__ . '/../../../view/hooks/Weline_Theme/frontend/layouts/base/head-before.phtml'
        );

        self::assertIsString($template);
        self::assertStringContainsString("w_query('analytics', 'getFrontendPixelSnippetsBySlot'", $template);
        self::assertStringContainsString("'slot' => 'head'", $template);
        self::assertStringContainsString('data-weshop-analytics-provider', $template);
    }

    public function testAnalyticsBodyAndFooterHookTemplatesUseSlotAwareQueryProvider(): void
    {
        $bodyTemplate = file_get_contents(
            __DIR__ . '/../../../view/hooks/Weline_Theme/frontend/layouts/base/body-start.phtml'
        );
        $footerTemplate = file_get_contents(
            __DIR__ . '/../../../view/hooks/Weline_Theme/frontend/layouts/base/footer-after.phtml'
        );

        self::assertIsString($bodyTemplate);
        self::assertStringContainsString("w_query('analytics', 'getFrontendPixelSnippetsBySlot'", $bodyTemplate);
        self::assertStringContainsString("'slot' => 'body'", $bodyTemplate);

        self::assertIsString($footerTemplate);
        self::assertStringContainsString("w_query('analytics', 'getFrontendPixelSnippetsBySlot'", $footerTemplate);
        self::assertStringContainsString("'slot' => 'footer'", $footerTemplate);
    }
}
