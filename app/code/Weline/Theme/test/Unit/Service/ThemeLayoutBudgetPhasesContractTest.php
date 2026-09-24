<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeLayoutBudgetPhases;

/**
 * WS2 UC-layout-budget: L0–L4 phase tags wired into layout chain (trace only).
 */
final class ThemeLayoutBudgetPhasesContractTest extends TestCase
{
    public function testBudgetPhaseConstantsMatchUcLayoutBudgetIds(): void
    {
        self::assertSame([
            'theme.layout.L0-context',
            'theme.layout.L1-chrome',
            'theme.layout.L2-header',
            'theme.layout.L3-slots',
            'theme.layout.L4-page-body',
        ], ThemeLayoutBudgetPhases::all());
    }

    public function testBudgetPhasesAreWiredIntoLayoutOwners(): void
    {
        $themeRoot = dirname(__DIR__, 3);

        $l1Chrome = (string)file_get_contents($themeRoot . '/Service/LayoutEntity/ThemeLayoutEntityChrome.php');
        $l1Filler = (string)file_get_contents($themeRoot . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php');
        self::assertStringContainsString('ThemeLayoutBudgetPhases::L1_CHROME', $l1Chrome);
        self::assertStringContainsString('theme.chrome.resolve', $l1Chrome);
        self::assertStringContainsString('theme.chrome.render', $l1Chrome);
        self::assertStringContainsString('ThemeLayoutBudgetPhases::L1_CHROME', $l1Filler);

        $l0 = (string)file_get_contents($themeRoot . '/Observer/ControllerFetchFileBefore.php');
        self::assertStringContainsString('ThemeLayoutBudgetPhases::L0_CONTEXT', $l0);
        self::assertStringContainsString('theme.layout.context', $l0);

        $l2 = (string)file_get_contents($themeRoot . '/Block/Partials.php');
        self::assertStringContainsString('ThemeLayoutBudgetPhases::L2_HEADER', $l2);
        self::assertStringContainsString('theme.header.bar', $l2);

        $l3 = (string)file_get_contents($themeRoot . '/Service/SlotRendererService.php');
        self::assertStringContainsString('ThemeLayoutBudgetPhases::L3_SLOTS', $l3);
        self::assertStringContainsString('theme.slots.dictionary_prefetch', $l3);

        $l4 = (string)file_get_contents($themeRoot . '/Observer/ControllerFetchFileAfter.php');
        self::assertStringContainsString('ThemeLayoutBudgetPhases::L4_PAGE_BODY', $l4);

        $headerNav = (string)file_get_contents($themeRoot . '/Helper/HeaderNavFragment.php');
        self::assertStringContainsString('theme.header.search_types', $headerNav);
        self::assertStringContainsString('theme.header.horizontal.cache', $headerNav);
        self::assertStringContainsString('theme.header.mega_panel.cache', $headerNav);
    }
}
