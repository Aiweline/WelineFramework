<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class FrontendHeadHookTemplateTest extends TestCase
{
    public function testAnalyticsHeadHookTemplateUsesAnalyticsQueryProvider(): void
    {
        $template = file_get_contents(
            __DIR__ . '/../../../view/hooks/Weline_Theme/frontend/layouts/base/head-after.phtml'
        );

        self::assertIsString($template);
        self::assertStringContainsString("w_query('analytics', 'getFrontendPixelSnippets'", $template);
        self::assertStringContainsString('data-weshop-analytics-provider', $template);
    }
}
