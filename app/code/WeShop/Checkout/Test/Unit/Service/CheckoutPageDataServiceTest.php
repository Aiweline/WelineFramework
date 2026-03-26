<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Address\Service\AddressService;
use WeShop\Cart\Service\CartService;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Checkout\Service\CheckoutService;
use WeShop\Order\Service\OrderService;
use WeShop\Shipping\Service\ShippingService;
use Weline\I18n\Model\I18n;

class CheckoutPageDataServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['WELINE_USER_CURRENCY']);
        parent::tearDown();
    }

    public function testBuildMapsCheckoutPageDataForDefaultThemeRendering(): void
    {
        $_SERVER['WELINE_USER_CURRENCY'] = 'USD';

        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->once())
            ->method('getCartItems')
            ->with(12)
            ->willReturn([
                [
                    'item_id' => 7,
                    'product_id' => 101,
                    'quantity' => 2,
                    'price' => 12.0,
                    'product' => [
                        'name' => 'Traveler Backpack',
                        'image' => '/media/backpack.jpg',
                    ],
                ],
                [
                    'cart_id' => 8,
                    'product_id' => 202,
                    'qty' => 1,
                    'price' => 26.0,
                    'product_name' => 'Desk Lamp',
                    'image' => '/media/lamp.jpg',
                ],
                'invalid-row',
            ]);
        $cartService->expects($this->once())
            ->method('calculateTotals')
            ->with(12)
            ->willReturn([
                'subtotal' => 50.0,
                'shipping' => 8.5,
                'discount' => 5.0,
                'tax' => 10.0,
                'total' => 63.5,
            ]);

        $addressService = $this->createMock(AddressService::class);
        $addressService->expects($this->once())
            ->method('getCustomerAddresses')
            ->with(12)
            ->willReturn([
                [
                    'address_id' => 2,
                    'firstname' => 'Grace',
                    'lastname' => 'Hopper',
                    'street' => '456 Broadway',
                    'city' => 'New York',
                    'region' => 'NY',
                    'country_id' => 'US',
                    'postcode' => '10012',
                    'telephone' => '987654321',
                    'is_default' => false,
                ],
                [
                    'address_id' => 3,
                    'firstname' => 'Ada',
                    'lastname' => 'Lovelace',
                    'street' => '123 Market Street',
                    'city' => 'London',
                    'region' => 'LDN',
                    'country_id' => 'GB',
                    'postcode' => 'EC1A',
                    'telephone' => '123456789',
                    'is_default' => true,
                ],
            ]);

        $shippingService = $this->createMock(ShippingService::class);
        $shippingService->expects($this->never())
            ->method('getAvailableShippingMethods');

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->expects($this->once())
            ->method('getCheckoutShippingMethods')
            ->with(12, [
                'area' => 'frontend',
                'currency' => 'USD',
                'country' => 'GB',
                'country_id' => 'GB',
                'region' => 'LDN',
            ])
            ->willReturn([
                [
                    'code' => 'flat_rate',
                    'name' => 'Flat Rate',
                    'description' => 'Delivery in 3-5 business days.',
                    'is_default' => true,
                    'sort_order' => 10,
                ],
            ]);
        $checkoutService->expects($this->once())
            ->method('getCheckoutPaymentMethods')
            ->with(12, [
                'area' => 'frontend',
                'currency' => 'USD',
            ])
            ->willReturn([
                [
                    'code' => 'paypal',
                    'title' => 'PayPal',
                    'description' => 'Pay online with your PayPal account.',
                    'is_default' => false,
                ],
                [
                    'code' => 'manual_transfer',
                    'title' => 'Manual Transfer',
                    'description' => 'Pay by bank transfer after the order is created.',
                    'is_default' => true,
                    'config' => [
                        'instructions' => 'Transfer the total to our bank account after the order is created.',
                        'reference_note' => 'Use the order number as the payment reference.',
                    ],
                ],
            ]);

        $i18n = $this->createMock(I18n::class);
        $i18n->expects($this->once())
            ->method('getCountries')
            ->with('en')
            ->willReturn([
                'GB' => 'United Kingdom',
                'US' => 'United States',
                'CN' => 'China',
            ]);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->never())
            ->method('getRetryPaymentContext');

        $service = new CheckoutPageDataService(
            $cartService,
            $addressService,
            $shippingService,
            $checkoutService,
            $i18n,
            $orderService
        );

        $result = $service->build(12, 9);

        $this->assertSame(3, $result['current_step']);
        $this->assertSame(3, $result['cart_count']);
        $this->assertSame(63.5, $result['cart_summary']['grand_total']);
        $this->assertFalse((bool) $result['is_retry_payment']);
        $this->assertSame('Traveler Backpack', $result['cart_items'][0]['name']);
        $this->assertSame(3, $result['selected_shipping_address_id']);
        $this->assertSame('Ada Lovelace', $result['saved_addresses'][1]['name']);
        $this->assertSame('GB', $result['saved_addresses'][1]['country_id']);
        $this->assertTrue((bool) $result['saved_addresses'][1]['is_default']);
        $this->assertSame('paypal', $result['payment_methods'][0]['code']);
        $this->assertSame('redirect', $result['payment_methods'][0]['flow']);
        $this->assertContains($result['payment_methods'][1]['badge'], ['Offline', '线下']);
        $this->assertStringContainsString('Use the order number as the payment reference.', $result['payment_methods'][1]['checkout_note']);
        $this->assertSame(
            [
                ['code' => 'GB', 'name' => 'United Kingdom'],
                ['code' => 'US', 'name' => 'United States'],
                ['code' => 'CN', 'name' => 'China'],
            ],
            $result['countries']
        );
    }

    public function testBuildUsesRetryOrderContextWithoutCartLookups(): void
    {
        $_SERVER['WELINE_USER_CURRENCY'] = 'USD';

        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->never())->method('getCartItems');
        $cartService->expects($this->never())->method('calculateTotals');

        $addressService = $this->createMock(AddressService::class);
        $addressService->method('getCustomerAddresses')->willReturn([]);

        $shippingService = $this->createMock(ShippingService::class);
        $shippingService->method('getAvailableShippingMethods')->willReturn([]);

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->method('getCheckoutShippingMethods')->willReturn([]);
        $checkoutService->method('getCheckoutPaymentMethods')->willReturn([
            ['code' => 'paypal', 'title' => 'PayPal', 'is_default' => true],
        ]);

        $i18n = $this->createMock(I18n::class);
        $i18n->method('getCountries')->willReturn([]);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getRetryPaymentContext')
            ->with(88, 12)
            ->willReturn([
                'order_id' => 88,
                'increment_id' => 'WS000088',
                'items' => [
                    ['item_id' => 1, 'product_id' => 101, 'name' => 'Trail Backpack', 'price' => 19.5, 'qty' => 1, 'row_total' => 19.5],
                    ['item_id' => 2, 'product_id' => 102, 'name' => 'Travel Bottle', 'price' => 20.0, 'qty' => 2, 'row_total' => 40.0],
                ],
                'summary' => [
                    'subtotal' => 59.5,
                    'shipping' => 0,
                    'discount' => 0,
                    'tax' => 0,
                    'grand_total' => 59.5,
                ],
            ]);

        $service = new CheckoutPageDataService(
            $cartService,
            $addressService,
            $shippingService,
            $checkoutService,
            $i18n,
            $orderService
        );

        $result = $service->build(12, 1, 88);

        $this->assertTrue((bool) $result['is_retry_payment']);
        $this->assertSame(88, $result['retry_order_id']);
        $this->assertSame('WS000088', $result['retry_order_increment_id']);
        $this->assertSame(3, $result['cart_count']);
        $this->assertSame(59.5, $result['cart_summary']['grand_total']);
        $this->assertSame('Trail Backpack', $result['cart_items'][0]['name']);
    }
}
