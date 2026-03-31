<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Cart\Model\Cart;
use WeShop\Cart\Service\CartService;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Service\StorefrontShellDataService;
use WeShop\Store\Model\Store;
use WeShop\Store\Service\StoreContextService;

class StorefrontShellDataServiceTest extends TestCase
{
    public function testBuildReturnsConfiguredStoreAndCartSummaryForLoggedInCustomer(): void
    {
        $storeContextService = $this->createMock(StoreContextService::class);
        $storeContextService->expects($this->once())
            ->method('getCurrentStore')
            ->willReturn([
                Store::schema_fields_NAME => 'Global Hub',
                Store::schema_fields_CURRENCY => 'eur',
            ]);

        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(42);

        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->once())
            ->method('getCartItems')
            ->with(42)
            ->willReturn([
                [
                    Cart::schema_fields_QUANTITY => 2,
                    Cart::schema_fields_PRICE => 12.5,
                ],
                [
                    'qty' => 1,
                    'price' => 4.0,
                ],
            ]);

        $service = new StorefrontShellDataService($storeContextService, $customerContext, $cartService);
        $result = $service->build();

        $this->assertSame('Global Hub', $result['store_name']);
        $this->assertSame('EUR', $result['store_currency']);
        $this->assertSame(3, $result['cart_count']);
        $this->assertSame(29.0, $result['cart_total']);
    }

    public function testBuildFallsBackToDefaultsForGuestWithoutEnabledStore(): void
    {
        $storeContextService = $this->createMock(StoreContextService::class);
        $storeContextService->expects($this->once())
            ->method('getCurrentStore')
            ->willReturn(null);

        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->never())->method('getCartItems');

        $service = new StorefrontShellDataService($storeContextService, $customerContext, $cartService);
        $result = $service->build();

        $this->assertSame('WeShop', $result['store_name']);
        $this->assertSame('USD', $result['store_currency']);
        $this->assertSame(0, $result['cart_count']);
        $this->assertSame(0.0, $result['cart_total']);
    }
}
