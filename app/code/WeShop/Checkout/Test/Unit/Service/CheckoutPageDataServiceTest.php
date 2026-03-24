<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Address\Service\AddressService;
use WeShop\Cart\Service\CartService;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Checkout\Service\CheckoutService;
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
                    'address_id' => 3,
                    'firstname' => 'Ada',
                    'lastname' => 'Lovelace',
                    'street' => '123 Market Street',
                    'city' => 'London',
                    'region' => 'LDN',
                    'postcode' => 'EC1A',
                    'telephone' => '123456789',
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
            ])
            ->willReturn([
                [
                    'code' => 'flat_rate',
                    'name' => 'Flat Rate',
                    'description' => 'Delivery in 3-5 business days.',
                    'is_default' => true,
                    'sort_order' => 10,
                ],
                [
                    'code' => 'local_pickup',
                    'name' => 'Local Pickup',
                    'description' => 'Pick up from the local store.',
                    'is_default' => false,
                    'sort_order' => 20,
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
                'US' => 'United States',
                'CN' => 'China',
            ]);

        $service = new CheckoutPageDataService(
            $cartService,
            $addressService,
            $shippingService,
            $checkoutService,
            $i18n
        );

        $result = $service->build(12, 9);

        $this->assertSame(3, $result['current_step']);
        $this->assertSame(3, $result['cart_count']);
        $this->assertSame(3, $result['item_count']);
        $this->assertSame(50.0, $result['cart_total']);
        $this->assertSame(63.5, $result['cart_summary']['grand_total']);
        $this->assertCount(2, $result['cart_items']);
        $this->assertSame('Traveler Backpack', $result['cart_items'][0]['name']);
        $this->assertSame(24.0, $result['cart_items'][0]['row_total']);
        $this->assertSame('Ada Lovelace', $result['saved_addresses'][0]['name']);
        $this->assertSame('flat_rate', $result['shipping_methods'][0]['code']);
        $this->assertTrue((bool) ($result['shipping_methods'][0]['is_default'] ?? false));
        $this->assertSame('paypal', $result['payment_methods'][0]['code']);
        $this->assertSame('redirect', $result['payment_methods'][0]['flow']);
        $this->assertContains(
            $result['payment_methods'][0]['flow_label'],
            ['Redirect after order placement', '下单后跳转支付']
        );
        $this->assertContains(
            $result['payment_methods'][1]['badge'],
            ['Offline', '线下']
        );
        $this->assertStringContainsString('Use the order number as the payment reference.', $result['payment_methods'][1]['checkout_note']);
        $this->assertSame(
            [
                ['code' => 'US', 'name' => 'United States'],
                ['code' => 'CN', 'name' => 'China'],
            ],
            $result['countries']
        );
    }
}
