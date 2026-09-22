<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * R2a: empty view_cart / begin_checkout / search must be dropped client-side;
 * page_view must carry page_location/title into additional persistence path.
 */
final class PixelRequiredParamGateContractTest extends TestCase
{
    public function testPixelJsDropsIncompleteEcommerceAndSearch(): void
    {
        $root = \dirname(__DIR__, 3);
        $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');

        self::assertStringContainsString('function __evaluateRequiredParamGate', $pixel);
        self::assertStringContainsString("return 'payment_recovery'", $pixel);
        self::assertStringContainsString("return ['search_term']", $pixel);
        self::assertStringContainsString("return ['currency', 'value', 'items']", $pixel);
        self::assertStringContainsString('weline-cart-shell__line', $pixel);
        self::assertStringContainsString('[WelinePixel] drop incomplete', $pixel);
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.09.22-param-shell1'", $pixel);
    }

    public function testPersistenceWritesPageViewAdditionalWhenUrlPresent(): void
    {
        $src = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/Service/PixelEventPersistenceService.php'
        );
        self::assertStringContainsString('page_location', $src);
        self::assertStringContainsString('page_title', $src);
        self::assertStringContainsString('PASSIVE_EVENTS_WITH_BROWSER_INFO', $src);
        // Must NOT unconditionally skip all passive events anymore.
        self::assertStringNotContainsString(
            'return !isset(self::PASSIVE_EVENTS_WITH_BROWSER_INFO[$event]);',
            $src
        );
    }

    public function testServerSkipsEmptyCartAndRecoveryCheckout(): void
    {
        $src = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/Service/PixelEventService.php'
        );
        self::assertStringContainsString('skipReasonForIncompleteRequiredParams', $src);
        self::assertStringContainsString('missing_required_params_items', $src);
        self::assertStringContainsString('payment_recovery_begin_checkout_forbidden', $src);
        self::assertStringContainsString('missing_required_params_search_term', $src);
    }
}
