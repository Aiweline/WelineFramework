<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;
use Weline\Payment\Interface\ProviderShipmentTrackingInterface;
use Weline\Payment\Observer\OrderShippedShipmentTrackingObserver;
use Weline\Payment\Service\PaymentShipmentTrackingDispatcher;

final class PaymentShipmentTrackingShellContractTest extends TestCase
{
    public function testPayPalImplementsTrackingAndFakeDoesNot(): void
    {
        self::assertTrue(is_subclass_of(PayPalProvider::class, ProviderShipmentTrackingInterface::class));
        self::assertFalse(is_subclass_of(FakeProvider::class, ProviderShipmentTrackingInterface::class));
        self::assertTrue(method_exists(PayPalProvider::class, 'syncShipmentTracking'));
    }

    public function testEventXmlUsesShellObserverNotPayPalHardcode(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertStringContainsString('OrderShippedShipmentTrackingObserver', $xml);
        self::assertStringContainsString('order_shipped_shipment_tracking', $xml);
        self::assertStringNotContainsString('PayPalOrderShippedTrackingObserver', $xml);
        self::assertStringNotContainsString('paypal_order_shipped_tracking', $xml);
    }

    public function testShellObserverDelegatesToDispatcher(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Observer/OrderShippedShipmentTrackingObserver.php'
        );
        self::assertStringContainsString(PaymentShipmentTrackingDispatcher::class, $src);
        self::assertStringNotContainsString('PayPalShipmentTrackingSyncService', $src);
        self::assertTrue(class_exists(OrderShippedShipmentTrackingObserver::class));
    }

    public function testDispatcherResolvesByMethodCode(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentShipmentTrackingDispatcher.php'
        );
        self::assertStringContainsString('ProviderShipmentTrackingInterface', $src);
        self::assertStringContainsString('resolveProviderRoute', $src);
        self::assertStringContainsString('PAYMENT_METHOD', $src);
    }
}
