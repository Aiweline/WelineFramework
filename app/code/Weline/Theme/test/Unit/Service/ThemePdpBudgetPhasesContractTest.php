<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemePdpBudgetPhases;

/**
 * WS4 UC-pdp-cold: pdp.main / related_stack / personalization phase tags.
 */
final class ThemePdpBudgetPhasesContractTest extends TestCase
{
    public function testBudgetPhaseConstantsMatchUcPdpColdIds(): void
    {
        self::assertSame([
            'pdp.main',
            'pdp.related_stack',
            'pdp.personalization',
        ], ThemePdpBudgetPhases::all());
    }

    public function testSlotMapCoversRequiredRecommendationSlots(): void
    {
        self::assertSame(ThemePdpBudgetPhases::MAIN, ThemePdpBudgetPhases::forSlot('product-main'));
        self::assertSame(ThemePdpBudgetPhases::MAIN, ThemePdpBudgetPhases::forSlot('product-info'));
        self::assertSame(ThemePdpBudgetPhases::RELATED_STACK, ThemePdpBudgetPhases::forSlot('product-related-products'));
        self::assertSame(ThemePdpBudgetPhases::RELATED_STACK, ThemePdpBudgetPhases::forSlot('product-bestsellers'));
        self::assertSame(ThemePdpBudgetPhases::RELATED_STACK, ThemePdpBudgetPhases::forSlot('product-cross-sell'));
        self::assertSame(ThemePdpBudgetPhases::PERSONALIZATION, ThemePdpBudgetPhases::forSlot('product-you-may-like'));
        self::assertSame(ThemePdpBudgetPhases::PERSONALIZATION, ThemePdpBudgetPhases::forSlot('product-recently-viewed'));
        self::assertNull(ThemePdpBudgetPhases::forSlot('header'));
    }

    public function testPhasesWiredIntoSlotRenderer(): void
    {
        $themeRoot = dirname(__DIR__, 3);
        $slotRenderer = (string)file_get_contents($themeRoot . '/Service/SlotRendererService.php');
        self::assertStringContainsString('ThemePdpBudgetPhases::forSlot', $slotRenderer);
        self::assertStringContainsString('renderSlotWidgets($layoutWidgets, $slotId)', $slotRenderer);

        $phases = (string)file_get_contents($themeRoot . '/Service/ThemePdpBudgetPhases.php');
        self::assertStringContainsString("public const MAIN = 'pdp.main'", $phases);
        self::assertStringContainsString("public const RELATED_STACK = 'pdp.related_stack'", $phases);
        self::assertStringContainsString("public const PERSONALIZATION = 'pdp.personalization'", $phases);
    }
}
