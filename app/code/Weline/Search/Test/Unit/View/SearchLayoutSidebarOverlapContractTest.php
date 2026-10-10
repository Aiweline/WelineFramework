<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Search type sidebar must sit in its own grid track without painting over results.
 * Fail mode: aside + filter-panel both carry card chrome → min-content overflow under sticky.
 */
final class SearchLayoutSidebarOverlapContractTest extends TestCase
{
    public function testSidebarIsLayoutShellNotSecondCard(): void
    {
        $moduleLayout = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/search/default.phtml';
        self::assertFileExists($moduleLayout);
        $this->assertSidebarShellCss((string)file_get_contents($moduleLayout), $moduleLayout);

        $hanfuLayout = dirname(__DIR__, 6) . '/design/Weline/hanfu/frontend/layouts/search/default.phtml';
        if (is_file($hanfuLayout)) {
            $this->assertSidebarShellCss((string)file_get_contents($hanfuLayout), $hanfuLayout);
        }
    }

    private function assertSidebarShellCss(string $source, string $path): void
    {
        self::assertStringContainsString('search-layout__body--with-sidebar', $source, $path);
        self::assertStringContainsString(
            'grid-template-columns: minmax(14rem, 18rem) minmax(0, 1fr)',
            $source,
            $path,
        );
        self::assertStringContainsString('overflow-x: clip', $source, $path);
        self::assertStringContainsString('type-filter.phtml', $source, $path);

        self::assertMatchesRegularExpression(
            '/\.search-layout__filters\s*\{[^}]*background:\s*transparent/s',
            $source,
            'aside must be a transparent column shell; card chrome belongs on filter panels: ' . $path,
        );
        self::assertMatchesRegularExpression(
            '/\.search-layout__filters\s*\{[^}]*box-shadow:\s*none/s',
            $source,
            $path,
        );
        self::assertMatchesRegularExpression(
            '/\.storefront-search__filter-panel[^\{]*\{[^}]*box-shadow:/s',
            $source,
            'filter panels keep raised card chrome: ' . $path,
        );
    }
}
