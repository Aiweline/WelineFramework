<?php

declare(strict_types=1);

namespace Tests\Unit\WeShop\Checkout;

use PHPUnit\Framework\TestCase;
use WeShop\Address\Service\AddressService;
use WeShop\Cart\Service\CartService;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Checkout\Service\CheckoutService;
use WeShop\Order\Service\OrderService;
use WeShop\Shipping\Service\ShippingService;
use Weline\Framework\Env\WelineEnv;
use Weline\I18n\Model\I18n;

final class CheckoutRuntimeMethodLocalDescriptionTest extends TestCase
{
    protected function tearDown(): void
    {
        WelineEnv::setLang('zh_Hans_CN');
        parent::tearDown();
    }

    public function testCheckoutShippingMapperUsesMethodLocalDescriptionInEnglishLocale(): void
    {
        WelineEnv::setLang('en_US');

        $methods = $this->invokeMapper('mapShippingMethods', [[
            'code' => 'flat_rate',
            'name' => '固定运费',
            'description' => '按固定运费配送。',
            'is_default' => true,
            'sort_order' => 10,
        ]]);

        self::assertSame('Flat Rate', $methods[0]['name']);
        self::assertSame('Standard delivery with a fixed shipping fee.', $methods[0]['description']);
    }

    public function testCheckoutPaymentMapperUsesMethodLocalDescriptionInEnglishLocale(): void
    {
        WelineEnv::setLang('en_US');

        $methods = $this->invokeMapper('mapPaymentMethods', [[
            'code' => 'manual_transfer',
            'title' => '银行转账',
            'description' => '下单后通过银行转账付款。',
            'config' => [
                'instructions' => '请将订单金额转入配置的银行账户，并使用订单号作为付款备注。',
                'reference_note' => '请使用订单号作为付款备注。',
            ],
            'is_default' => true,
            'sort_order' => 10,
        ]]);

        self::assertSame('Manual Transfer', $methods[0]['title']);
        self::assertSame('Pay by bank transfer after the order is created.', $methods[0]['description']);
        self::assertSame(
            'Please transfer the order amount to the configured bank account and use the order number as the payment reference.',
            $methods[0]['checkout_note']
        );
    }

    /**
     * @param array<int, mixed> $methods
     * @return array<int, array<string, mixed>>
     */
    private function invokeMapper(string $methodName, array $methods): array
    {
        $service = new CheckoutPageDataService(
            $this->createMock(CartService::class),
            $this->createMock(AddressService::class),
            $this->createMock(ShippingService::class),
            $this->createMock(CheckoutService::class),
            $this->createMock(I18n::class),
            $this->createMock(OrderService::class)
        );

        $method = new \ReflectionMethod($service, $methodName);
        $method->setAccessible(true);

        return $method->invoke($service, $methods);
    }
}
