<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionStorefrontOverlay;
use Weline\Theme\Service\SlotBoundaryScanner;

final class RequiredDefaultInjectionGhostSlotTest extends TestCase
{
    public function testSlotInnerPresenceIgnoresLooseActionMarkers(): void
    {
        self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode(
            '                                    ',
            'Weline_Cart',
            'product-add-to-cart',
        ));
        self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode(
            '<button data-action="add">Add</button>',
            'Weline_Cart',
            'product-add-to-cart',
        ));
        self::assertTrue(RequiredDefaultInjectionContract::slotInnerHasWidgetCode(
            '<button data-testid="product-add-to-cart" data-action="add">Add</button>',
            'Weline_Cart',
            'product-add-to-cart',
        ));
    }

    public function testOverlayListsRegionsAndThrowsInsteadOfSoftLog(): void
    {
        $overlay = new RequiredDefaultInjectionStorefrontOverlay(new SlotBoundaryScanner());
        $list = new \ReflectionMethod($overlay, 'listSlotRegions');
        $list->setAccessible(true);

        $rendered = '<!--@weline-slot:product-purchase-actions-->'
            . '                                    '
            . '<!--@/weline-slot:product-purchase-actions-->';
        $regions = $list->invoke($overlay, $rendered, 'product-purchase-actions');
        self::assertNotSame([], $regions);
        self::assertSame('', \trim((string)$regions[0]['inner']));

        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionStorefrontOverlay.php'
        );
        self::assertStringContainsString('listSlotRegions', $src);
        self::assertStringContainsString('required_default_injection_render_failed', $src);
        self::assertStringContainsString('InjectionPlanner', $src);
        self::assertStringContainsString('Do not create ghost slots', $src);
        self::assertStringContainsString('findSlotWrapperBounds', $src);
        self::assertStringContainsString('attr-wrapper', $src);
        self::assertStringNotContainsString(
            "\$rendered .= SlotBoundaryMarkers::open(\$slotId)",
            $src,
        );
        self::assertStringNotContainsString("error_log('[RequiredDefaultInjection]", $src);
        self::assertStringNotContainsString('有槽才注', $src);
    }

    public function testListSlotRegionsFindsDataSlotIdWithoutMarkers(): void
    {
        $overlay = new RequiredDefaultInjectionStorefrontOverlay(new SlotBoundaryScanner());
        $list = new \ReflectionMethod($overlay, 'listSlotRegions');
        $list->setAccessible(true);

        $html = '<div class="theme-published-slot w-auth-login__social-slot"'
            . ' data-slot-id="account-login-social-providers"></div>';
        $regions = $list->invoke($overlay, $html, 'account-login-social-providers');

        self::assertCount(1, $regions);
        self::assertContains($regions[0]['via'], ['attr-wrapper', 'scanner']);
        self::assertSame('', \trim((string)$regions[0]['inner']));

        $scanner = new SlotBoundaryScanner();
        self::assertCount(1, $scanner->enumerateRegions($html, 'account-login-social-providers'));
        self::assertSame(
            'account-login-social-providers',
            $scanner->enumerateRegions($html)[0]['id'] ?? null,
        );
    }
}
