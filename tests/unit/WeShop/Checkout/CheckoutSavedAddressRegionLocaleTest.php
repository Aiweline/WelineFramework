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

final class CheckoutSavedAddressRegionLocaleTest extends TestCase
{
    protected function tearDown(): void
    {
        WelineEnv::setLang('zh_Hans_CN');
        parent::tearDown();
    }

    public function testSavedAddressRegionDisplayUsesEnglishLocaleWithoutChangingRawRegion(): void
    {
        WelineEnv::setLang('en_US');

        $addresses = $this->mapSavedAddresses([
            [
                'address_id' => 7,
                'firstname' => 'Grace',
                'lastname' => 'Hopper',
                'region' => '加利福尼亚州',
                'country_id' => 'US',
            ],
        ]);

        self::assertSame('California', $addresses[0]['state']);
        self::assertSame('加利福尼亚州', $addresses[0]['region']);
    }

    public function testSavedAddressRegionDisplayUsesDefaultChineseLocale(): void
    {
        WelineEnv::setLang('zh_Hans_CN');

        $addresses = $this->mapSavedAddresses([
            [
                'address_id' => 8,
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'region' => 'CA',
                'country_id' => 'US',
            ],
        ]);

        self::assertSame('加利福尼亚州', $addresses[0]['state']);
        self::assertSame('CA', $addresses[0]['region']);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapSavedAddresses(array $rows): array
    {
        $service = new CheckoutPageDataService(
            $this->createMock(CartService::class),
            $this->createMock(AddressService::class),
            $this->createMock(ShippingService::class),
            $this->createMock(CheckoutService::class),
            $this->createMock(I18n::class),
            $this->createMock(OrderService::class)
        );

        $method = new \ReflectionMethod($service, 'mapSavedAddresses');
        $method->setAccessible(true);

        return $method->invoke($service, $rows);
    }
}
