<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllSearchViewContractsPass().
 */
final class SearchViewContractsTest extends TestCase
{
    public function testAllSearchViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'SearchLayoutSidebarOverlap::testSidebarIsLayoutShellNotSecondCard' => function () {
            $this->assert_SearchLayoutSidebarOverlap_testSidebarIsLayoutShellNotSecondCard();
        },
            'TypeFilterPrefetch::testTypeFilterPrefetchesLabelsBeforeRender' => function () {
            $this->assert_TypeFilterPrefetch_testTypeFilterPrefetchesLabelsBeforeRender();
        },
        ];
    }

private function assert_SearchLayoutSidebarOverlap_testSidebarIsLayoutShellNotSecondCard(): void
{
    $moduleLayout = '/Users/weline/Project/Official/框架/app/code/Weline/Search' . '/view/theme/frontend/layouts/search/default.phtml';
    self::assertFileExists($moduleLayout);
    $this->assertSidebarShellCss_SearchLayoutSidebarOverlap((string) file_get_contents($moduleLayout), $moduleLayout);
    $hanfuLayout = '/Users/weline/Project/Official/框架/app' . '/design/Weline/hanfu/frontend/layouts/search/default.phtml';
    if (is_file($hanfuLayout)) {
        $this->assertSidebarShellCss_SearchLayoutSidebarOverlap((string) file_get_contents($hanfuLayout), $hanfuLayout);
    }
}

private function assertSidebarShellCss_SearchLayoutSidebarOverlap(string $source, string $path): void
{
    self::assertStringContainsString('search-layout__body--with-sidebar', $source, $path);
    self::assertStringContainsString('grid-template-columns: minmax(14rem, 18rem) minmax(0, 1fr)', $source, $path);
    self::assertStringContainsString('overflow-x: clip', $source, $path);
    self::assertStringContainsString('type-filter.phtml', $source, $path);
    self::assertMatchesRegularExpression('/\.search-layout__filters\s*\{[^}]*background:\s*transparent/s', $source, 'aside must be a transparent column shell; card chrome belongs on filter panels: ' . $path);
    self::assertMatchesRegularExpression('/\.search-layout__filters\s*\{[^}]*box-shadow:\s*none/s', $source, $path);
    self::assertMatchesRegularExpression('/\.storefront-search__filter-panel[^\{]*\{[^}]*box-shadow:/s', $source, 'filter panels keep raised card chrome: ' . $path);
}

private function assert_TypeFilterPrefetch_testTypeFilterPrefetchesLabelsBeforeRender(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Search' . '/view/templates/frontend/partials/type-filter.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('WidgetI18n::prefetchLabels', $source);
    self::assertStringContainsString('$collectLabels', $source);
    self::assertStringContainsString('WidgetI18n::label', $source);
    $emptyGuard = strpos($source, 'if ($types === [])');
    $prefetch = strpos($source, 'WidgetI18n::prefetchLabels');
    $renderTree = strpos($source, '$renderTree = static function');
    self::assertNotFalse($emptyGuard);
    self::assertNotFalse($prefetch);
    self::assertNotFalse($renderTree);
    self::assertGreaterThan($emptyGuard, $prefetch);
    self::assertLessThan($renderTree, $prefetch);
}
}
