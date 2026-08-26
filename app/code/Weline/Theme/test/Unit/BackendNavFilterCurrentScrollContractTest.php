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

        foreach ([$runtime, $published] as $source) {
            self::assertStringContainsString("function registerNavFilter()", $source);
            self::assertStringContainsString('scrollCurrentIntoView', $source);
            self::assertStringContainsString('.w-backend-nav__item[aria-current="page"]', $source);
            self::assertStringContainsString("element.querySelector(':scope > nav')", $source);
            self::assertStringContainsString("element.closest('.w-backend-sidebar')", $source);
            self::assertStringContainsString("weline:ui:drawer:open", $source);
            self::assertStringContainsString('scroller.scrollTop += delta', $source);
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
