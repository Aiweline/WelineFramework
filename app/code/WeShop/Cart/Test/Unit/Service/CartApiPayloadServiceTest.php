<?php

declare(strict_types=1);

namespace WeShop\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Cart\Model\Cart as CartModel;
use WeShop\Cart\Service\CartApiPayloadService;
use WeShop\Cart\Service\CartService;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Service\ConfigurableProductService;
use WeShop\Product\Service\ProductService;

class CartApiPayloadServiceTest extends TestCase
{
    public function testBuildAddResponseRequiresLoginWhenCustomerIsMissing(): void
    {
        $service = $this->createService();

        $payload = $service->buildAddResponse(null, ['product_id' => 3, 'qty' => 1]);

        $this->assertSame(401, $payload['code'] ?? null);
        $this->assertNotSame('', (string) ($payload['msg'] ?? ''));
        $this->assertFalse((bool) ($payload['data']['success'] ?? true));
        $this->assertTrue((bool) ($payload['data']['requires_login'] ?? false));
    }

    public function testBuildMiniItemsResponseReturnsEmptyPayloadForGuest(): void
    {
        $service = $this->createService();

        $payload = $service->buildMiniItemsResponse(null);

        $this->assertSame(200, $payload['code'] ?? null);
        $this->assertTrue((bool) ($payload['data']['success'] ?? false));
        $this->assertSame([], $payload['data']['items'] ?? null);
        $this->assertSame(0, $payload['data']['totals']['count'] ?? null);
        $this->assertSame('$0.00', $payload['data']['totals']['subtotal_formatted'] ?? null);
    }

    public function testBuildUpdateResponseReturnsFormattedTotalsAfterUpdate(): void
    {
        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->once())
            ->method('updateCart')
            ->with(12, 3, 9);
        $cartService->expects($this->once())
            ->method('calculateTotals')
            ->with(9)
            ->willReturn([
                'subtotal' => 88.5,
                'total' => 92.1,
            ]);
        $cartService->expects($this->once())
            ->method('getCartItemCount')
            ->with(9)
            ->willReturn(4);

        $service = $this->createService(cartService: $cartService);

        $payload = $service->buildUpdateResponse(9, 12, 3);

        $this->assertSame(200, $payload['code'] ?? null);
        $this->assertTrue((bool) ($payload['data']['success'] ?? false));
        $this->assertSame(4, $payload['data']['totals']['count'] ?? null);
        $this->assertSame('$88.50', $payload['data']['totals']['subtotal_formatted'] ?? null);
        $this->assertSame('$92.10', $payload['data']['totals']['total_formatted'] ?? null);
    }

    public function testBuildOptionsResponseReturnsSimpleProductPayload(): void
    {
        $product = $this->createMock(\WeShop\Product\Model\Product::class);
        $product->method('getId')->willReturn(15);
        $product->method('getName')->willReturn('Road Helmet');
        $product->method('getImage')->willReturn('helmet.jpg');

        $productService = $this->createMock(ProductService::class);
        $productService->expects($this->once())
            ->method('getProduct')
            ->with(15)
            ->willReturn($product);

        $configurableProductService = $this->createMock(ConfigurableProductService::class);
        $configurableProductService->expects($this->once())
            ->method('isConfigurable')
            ->with(15)
            ->willReturn(false);

        $priceService = $this->createMock(PriceService::class);
        $priceService->method('calculatePrice')->with(15)->willReturn(128.8);
        $priceService->method('formatPrice')->willReturnCallback(
            static fn(float $price): string => '$' . number_format($price, 2)
        );

        $service = $this->createService(
            productService: $productService,
            configurableProductService: $configurableProductService,
            priceService: $priceService
        );

        $payload = $service->buildOptionsResponse(15);

        $this->assertSame(200, $payload['code'] ?? null);
        $this->assertTrue((bool) ($payload['data']['success'] ?? false));
        $this->assertFalse((bool) ($payload['data']['is_configurable'] ?? true));
        $this->assertSame('Road Helmet', $payload['data']['product']['name'] ?? null);
        $this->assertSame(128.8, $payload['data']['product']['price'] ?? null);
    }

    public function testBuildAddResponseFallsBackToResolvedCartItemIdWhenModelIdIsMissing(): void
    {
        $product = $this->createMock(\WeShop\Product\Model\Product::class);
        $product->method('getId')->willReturn(15);
        $product->method('getStatus')->willReturn(1);
        $product->method('getStock')->willReturn(8);
        $product->method('getName')->willReturn('Road Helmet');
        $product->method('getImage')->willReturn('helmet.jpg');

        $productService = $this->createMock(ProductService::class);
        $productService->expects($this->once())
            ->method('getProduct')
            ->with(15)
            ->willReturn($product);

        $configurableProductService = $this->createMock(ConfigurableProductService::class);
        $configurableProductService->expects($this->once())
            ->method('isConfigurable')
            ->with(15)
            ->willReturn(false);

        $cartModel = $this->createMock(CartModel::class);
        $cartModel->method('getId')->willReturn(0);
        $cartModel->expects($this->once())
            ->method('setId')
            ->with(55)
            ->willReturnSelf();

        $cartService = $this->createMock(CartService::class);
        $cartService->expects($this->once())
            ->method('addToCart')
            ->with(9, 15, 2, 128.8)
            ->willReturn($cartModel);
        $cartService->expects($this->once())
            ->method('findCartItemId')
            ->with(9, 15)
            ->willReturn(55);
        $cartService->expects($this->once())
            ->method('getCartItemCount')
            ->with(9)
            ->willReturn(2);
        $cartService->expects($this->once())
            ->method('calculateTotals')
            ->with(9)
            ->willReturn([
                'total' => 257.6,
            ]);

        $priceService = $this->createMock(PriceService::class);
        $priceService->expects($this->once())
            ->method('calculatePrice')
            ->with(15, 9, 2)
            ->willReturn(128.8);
        $priceService->method('formatPrice')->willReturnCallback(
            static fn(float $price): string => '$' . number_format($price, 2)
        );

        $service = $this->createService(
            cartService: $cartService,
            productService: $productService,
            configurableProductService: $configurableProductService,
            priceService: $priceService
        );

        $payload = $service->buildAddResponse(9, [
            'product_id' => 15,
            'qty' => 2,
        ]);

        $this->assertSame(200, $payload['code'] ?? null);
        $this->assertTrue((bool) ($payload['data']['success'] ?? false));
        $this->assertSame(55, $payload['data']['cart_item_id'] ?? null);
        $this->assertSame('$257.60', $payload['data']['cart_total_formatted'] ?? null);
    }

    private function createService(
        ?CartService $cartService = null,
        ?ProductService $productService = null,
        ?ConfigurableProductService $configurableProductService = null,
        ?PriceService $priceService = null
    ): CartApiPayloadService {
        if ($priceService === null) {
            $priceService = $this->createMock(PriceService::class);
            $priceService->method('formatPrice')->willReturnCallback(
                static fn(float $price): string => '$' . number_format($price, 2)
            );
        }

        return new CartApiPayloadService(
            $cartService ?? $this->createMock(CartService::class),
            $productService ?? $this->createMock(ProductService::class),
            $configurableProductService ?? $this->createMock(ConfigurableProductService::class),
            $priceService
        );
    }
}
