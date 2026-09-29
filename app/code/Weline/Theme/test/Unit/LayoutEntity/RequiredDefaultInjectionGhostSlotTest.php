<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;
use Weline\Theme\Service\SlotBoundaryScanner;

final class RequiredDefaultInjectionGhostSlotTest extends TestCase
{
    public function testSlotInnerPresenceIgnoresLooseActionMarkers(): void
    {
        self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode('   ','Weline_Cart','product-add-to-cart'));
        self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode('<button data-action="add">Add</button>','Weline_Cart','product-add-to-cart'));
        self::assertTrue(RequiredDefaultInjectionContract::slotInnerHasWidgetCode('<button data-testid="product-add-to-cart" data-action="add">Add</button>','Weline_Cart','product-add-to-cart'));
    }
    public function testEditorScannerFindsEmptyNativeAndLegacySlotCarriers(): void
    {
        $scanner=new SlotBoundaryScanner();
        foreach(['data-wslot','data-slot-id'] as $attribute){
            $html='<div class="account-social" '.$attribute.'="account-login-social-providers"></div>';
            $regions=$scanner->enumerateRegions($html,'account-login-social-providers');
            self::assertCount(1,$regions);
            self::assertSame('account-login-social-providers',$scanner->enumerateRegions($html)[0]['id']??null);
        }
    }
}
