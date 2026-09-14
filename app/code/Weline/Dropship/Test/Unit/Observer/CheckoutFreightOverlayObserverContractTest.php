<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class CheckoutFreightOverlayObserverContractTest extends TestCase
{
    public function testEventXmlRegistersCheckoutFreightObservers(): void
    {
        $xml = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertStringContainsString('Weline_Checkout::checkout::shipping_quote::overlay', $xml);
        self::assertStringContainsString('CheckoutShippingQuoteOverlayObserver', $xml);
        self::assertStringContainsString('Weline_Checkout::checkout::shipping_methods::enrich', $xml);
        self::assertStringContainsString('CheckoutShippingMethodsEnrichObserver', $xml);
    }
}
