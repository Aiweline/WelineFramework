<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\SlotBoundaryMarkers;

/**
 * wave8-8s3: published response HTML must carry 0× data-wslot —
 * bake/widget debris sanitized; LayoutSlot early-returns when published host active.
 */
final class PublishedStorefrontZeroDataWslotContractTest extends TestCase
{
    public function testBoundaryStripRemovesDataWslotFromBakeDebris(): void
    {
        $bake = '<!--@weline-slot:content-->'
            . '<div class="theme-layout-entity-slot" data-slot-id="content">'
            . '<div data-wslot="widget-hero" data-wslot-exclusive="true" class="slot-hero">hero</div>'
            . '</div>'
            . '<!--@/weline-slot:content-->';

        $sanitized = SlotBoundaryMarkers::stripReactiveSlotAttributes($bake);
        self::assertSame(0, \preg_match_all('/\bdata-wslot\s*=/', $sanitized));
        self::assertStringContainsString('data-slot-id="content"', $sanitized);
        self::assertStringContainsString('@weline-slot:content', $sanitized);

        $outbound = SlotBoundaryMarkers::strip($bake);
        self::assertSame(0, \preg_match_all('/\bdata-wslot\s*=/', $outbound));
        self::assertStringNotContainsString('@weline-slot', $outbound);
        self::assertStringContainsString('hero', $outbound);
    }

    public function testStripPromotesDataWslotToPublishedSlotIdentity(): void
    {
        $reactive = '<div class="w-auth-login__social-slot" data-wslot="account-login-social-providers"></div>';
        $outbound = SlotBoundaryMarkers::strip($reactive);

        self::assertSame(0, \preg_match_all('/\bdata-wslot\s*=/', $outbound));
        self::assertStringContainsString('data-slot-id="account-login-social-providers"', $outbound);
        self::assertStringContainsString('theme-published-slot', $outbound);
        self::assertStringContainsString('w-auth-login__social-slot', $outbound);
    }





    public function testSlotBoundaryMarkersExposesStripReactiveApi(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/SlotBoundaryMarkers.php'
        );

        self::assertStringContainsString('function stripReactiveSlotAttributes', $src);
        self::assertStringContainsString('wave8-8s3', $src);
        self::assertStringContainsString('stripReactiveSlotAttributes($html)', $src);
    }
}
