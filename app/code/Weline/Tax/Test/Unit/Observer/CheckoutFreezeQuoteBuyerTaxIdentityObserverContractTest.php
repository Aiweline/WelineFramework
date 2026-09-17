<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class CheckoutFreezeQuoteBuyerTaxIdentityObserverContractTest extends TestCase
{
    public function testEventXmlRegistersFreezeEnrichObserver(): void
    {
        $xml = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertStringContainsString('Weline_Checkout::checkout::freeze_quote::enrich', $xml);
        self::assertStringContainsString('CheckoutFreezeQuoteBuyerTaxIdentityObserver', $xml);
    }

    public function testObserverClearsIdentityOutsideEu(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/CheckoutFreezeQuoteBuyerTaxIdentityObserver.php',
        );
        self::assertStringContainsString('LEGACY_PAYLOAD_KEY', $src);
        self::assertStringContainsString('PAYLOAD_KEY', $src);
        self::assertStringContainsString('mergeIntoTaxSnapshot', $src);
        self::assertStringContainsString('prevCountry', $src);
        self::assertStringContainsString('buyer_tax_country', $src);
    }
}