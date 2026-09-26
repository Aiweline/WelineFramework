<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Pagination widget stores Chinese prev_text/next_text in layout config;
 * must resolve via WidgetI18n so /{locale}/ pages do not keep Chinese chrome.
 */
final class PaginationWidgetPrevNextI18nContractTest extends TestCase
{
    public function testPaginationWidgetResolvesPrevNextViaWidgetI18n(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/theme/frontend/widgets/pagination/pagination/default.phtml';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('use Weline\\Theme\\Helper\\WidgetI18n;', $source);
        self::assertStringContainsString("WidgetI18n::label(", $source);
        self::assertStringContainsString("'上一页'", $source);
        self::assertStringContainsString("'下一页'", $source);
        self::assertStringNotContainsString("\$this->getData('prev_text') ?? __('上一页')", $source);
        self::assertStringNotContainsString("\$this->getData('next_text') ?? __('下一页')", $source);
    }
}
