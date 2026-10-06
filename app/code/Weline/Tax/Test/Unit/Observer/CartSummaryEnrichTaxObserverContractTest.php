<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class CartSummaryEnrichTaxObserverContractTest extends TestCase
{
    public function testObserverRegisteredAndUsesAdvisorWithoutHardCartDependency(): void
    {
        $eventXml = file_get_contents(dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertIsString($eventXml);
        self::assertStringContainsString('Weline_Cart::cart_summary::enrich', $eventXml);
        self::assertStringContainsString('CartSummaryEnrichTaxObserver', $eventXml);

        $src = file_get_contents(dirname(__DIR__, 3) . '/Observer/CartSummaryEnrichTaxObserver.php');
        self::assertIsString($src);
        self::assertStringContainsString('CheckoutTaxAdvisorInterface', $src);
        self::assertStringContainsString('tax_amount_minor', $src);
        self::assertStringNotContainsString('Weline\\Cart\\Service\\CartService', $src);
    }
}
