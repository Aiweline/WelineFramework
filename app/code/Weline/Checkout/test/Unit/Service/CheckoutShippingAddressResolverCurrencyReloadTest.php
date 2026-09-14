<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutShippingAddressResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Service\DeliveryAddressService;

/**
 * Regression: selected US book address must win over cascade CN after currency reload.
 */
final class CheckoutShippingAddressResolverCurrencyReloadTest extends TestCase
{
    public function testAddressIdOverridesCascadeCnDefault(): void
    {
        require dirname(__DIR__, 5) . '/bootstrap.php';
        /** @var DeliveryAddressService $book */
        $book = ObjectManager::getInstance(DeliveryAddressService::class);
        $us = null;
        foreach ($book->getListByCustomer(47, ['is_enabled' => 1]) as $model) {
            if (!$model instanceof DeliveryAddress) {
                continue;
            }
            if (strtoupper(trim((string)$model->getData(DeliveryAddress::schema_fields_COUNTRY_CODE))) === 'US') {
                $us = $model;
                break;
            }
        }
        if ($us === null) {
            self::markTestSkipped('No US delivery address for local fixture customer 47');
        }
        $id = (string)$us->getId();
        /** @var CheckoutShippingAddressResolver $resolver */
        $resolver = ObjectManager::getInstance(CheckoutShippingAddressResolver::class);
        $resolved = $resolver->resolve(
            [
                'country_code' => 'CN',
                'province' => '四川省',
                'city' => '成都市',
                'shipping_address_id' => $id,
            ],
            [],
        );
        self::assertSame('US', strtoupper((string)($resolved['country_code'] ?? '')));
        self::assertSame($id, (string)($resolved['shipping_address_id'] ?? $resolved['address_id'] ?? ''));
    }
}
