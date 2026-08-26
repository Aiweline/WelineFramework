<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class SearchLayoutContractTest extends TestCase
{
    public function testSearchLayoutUsesStableGridTokensAndTypeFilterPartial(): void
    {
        $layout = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/search/default.phtml';
        $filter = dirname(__DIR__, 3) . '/Search/view/templates/frontend/partials/type-filter.phtml';
        self::assertFileExists($layout);
        self::assertFileExists($filter);

        $source = (string)file_get_contents($layout);
        self::assertStringContainsString('search-layout__body', $source);
        self::assertStringContainsString('search-layout__body--with-sidebar', $source);
        self::assertStringContainsString('type-filter.phtml', $source);
        self::assertStringContainsString('storefront-search__filter-panel', $source);
        self::assertStringContainsString('.weline-page-wrapper.search-layout', $source);
        self::assertStringContainsString('data-surface="body"', $source);
        self::assertStringContainsString('w-surface-body', $source);
        self::assertStringContainsString('--weline-theme-body-text', $source);
        self::assertStringContainsString('search-layout__recommendations > .widget-wrapper', $source);
        self::assertStringContainsString('--weline-space-5', $source);
        self::assertStringNotContainsString('minmax(var(--size-panel-220), var(--layout-sidebar-width))', $source);
    }
}
