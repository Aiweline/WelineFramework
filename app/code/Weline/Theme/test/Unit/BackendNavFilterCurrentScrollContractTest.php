<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

/**
 * Backend nav-filter must restore scroll-to-current after Upzet→Weline UI migration.
 */
final class BackendNavFilterCurrentScrollContractTest extends TestCase
{
    public function testNavFilterScrollsCurrentMenuItemIntoSidebar(): void
    {
        $runtime = $this->read('app/code/Weline/Theme/view/ui/js/weline-ui.js');
        $published = $this->read('app/code/Weline/Theme/view/statics/ui/weline-ui.js');

        $backendCss = $this->read('app/code/Weline/Theme/view/ui/css/backend.css');
        self::assertStringContainsString('.w-backend-nav__disclosure[open] > summary.w-backend-nav__item', $backendCss);
        self::assertStringContainsString('position: sticky', $backendCss);

        foreach ([$runtime, $published] as $source) {
            self::assertStringContainsString("function registerNavFilter()", $source);
            self::assertStringContainsString('stripLocalizationSegments', $source);
            self::assertStringContainsString('scrollCurrentIntoView', $source);
            self::assertStringContainsString('clearCurrentRoute', $source);
            self::assertStringContainsString('matchesSubtree', $source);
            self::assertStringContainsString('entryMatchesOwnLabel', $source);
            self::assertStringContainsString('hasMatchingAncestor', $source);
            self::assertStringContainsString('expandDescendants', $source);
            self::assertStringContainsString('setEntryExpandedForFilter(entry, selfMatch)', $source);
            self::assertStringContainsString('data-w-nav-filtering', $source);
            self::assertStringContainsString('data-w-nav-filter-source', $source);
            self::assertStringContainsString('data-search-text', $source);
            self::assertStringContainsString('isFilterSourceEntry', $source);
            self::assertStringContainsString('isFilterSourceGroup', $source);
            self::assertStringContainsString("!item.closest('[data-w-nav-filter-source]')", $source);
            self::assertStringContainsString('syncCurrentRouteAndScrollUnlessFiltering', $source);
            self::assertStringContainsString('.w-backend-nav__item[aria-current="page"]', $source);
            self::assertStringContainsString("element.querySelector(':scope > nav')", $source);
            self::assertStringContainsString("element.closest('.w-backend-sidebar')", $source);
            self::assertStringContainsString("weline:ui:drawer:open", $source);
            self::assertStringContainsString('scroller.scrollTop += delta', $source);
            self::assertStringContainsString('expandSidebarForSearch', $source);
            self::assertStringContainsString('expandSidebarOverlay', $source);
            self::assertStringContainsString('expandTopLevelEntry', $source);
            self::assertStringContainsString('?.expandOverlay?.()', $source);
            self::assertStringContainsString('maybeDismissSearchOverlay', $source);
            self::assertStringContainsString('if (currentDisclosures.has(disclosure))', $source);
            self::assertStringContainsString('suppressSummaryClick', $source);
            self::assertStringContainsString('pinOpenedDisclosure', $source);
            self::assertStringContainsString("trigger.tagName === 'SUMMARY'", $source);
            self::assertStringContainsString('event.isTrusted', $source);
        }
    }

    private function read(string $relative): string
    {
        $path = BP . $relative;
        self::assertFileExists($path);
        $content = \file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }
}
