<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CheckoutShippingFreightOverlayEventContractTest extends TestCase
{
    public function testFreezeAndQuoteDispatchesShippingQuoteOverlay(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutGroupSubmitService.php'
        );
        self::assertStringContainsString(
            "Weline_Checkout::checkout::shipping_quote::overlay",
            $src,
        );
        self::assertStringContainsString('dropship_freight_degraded', $src);
        self::assertStringContainsString('货源运费暂不可用', $src);
    }

    public function testLoadShippingMethodsPassesOfferIdAndEnrichEvent(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php'
        );
        self::assertStringContainsString("'offer_id'", $src);
        self::assertStringContainsString(
            "Weline_Checkout::checkout::shipping_methods::enrich",
            $src,
        );
    }

    public function testEventCatalogDocumentsOverlayEvents(): void
    {
        $catalog = (string)file_get_contents(dirname(__DIR__, 3) . '/event.php');
        self::assertStringContainsString(
            "Weline_Checkout::checkout::shipping_quote::overlay",
            $catalog,
        );
        self::assertStringContainsString(
            "Weline_Checkout::checkout::shipping_methods::enrich",
            $catalog,
        );
    }
}
