<?php

declare(strict_types=1);

namespace WeShop\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Model\Cart;
use WeShop\Cart\Service\CartService;
use Weline\Framework\Event\EventsManager;

class CartServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::removeInstance(Cart::class);
        ObjectManager::removeInstance(EventsManager::class);
        parent::tearDown();
    }

    public function testCalculateTotalsUsesInjectedEventsManagerForDispatch(): void
    {
        $capturedEvents = [];
        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (string $eventName, mixed &$eventData) use (&$capturedEvents, $eventsManager) {
                $capturedEvents[] = [$eventName, $eventData];
                return $eventsManager;
            });

        $service = $this->getMockBuilder(CartService::class)
            ->setConstructorArgs([$eventsManager])
            ->onlyMethods(['getCartItems'])
            ->getMock();

        $service->expects($this->once())
            ->method('getCartItems')
            ->with(23)
            ->willReturn([
                ['price' => 12.0, 'quantity' => 3],
            ]);

        $totals = $service->calculateTotals(23);

        $this->assertSame(36.0, (float) ($totals['subtotal'] ?? 0));
        $this->assertSame(36.0, (float) ($totals['total'] ?? 0));
        $this->assertCount(2, $capturedEvents);
        $this->assertSame('WeShop_Cart::totals_collect', $capturedEvents[0][0] ?? null);
        $this->assertSame('WeShop_Cart::totals_collected', $capturedEvents[1][0] ?? null);
        $this->assertSame(23, (int) ($capturedEvents[0][1]['customer_id'] ?? 0));
        $this->assertSame(36.0, (float) ($capturedEvents[0][1]['totals']['subtotal'] ?? 0));
        $this->assertSame(36.0, (float) ($capturedEvents[1][1]['totals']['total'] ?? 0));
    }

    public function testAddToCartHydratesNewCartIdFromNumericSaveResult(): void
    {
        $eventsManager = $this->createDispatchingEventsManager();
        $service = new CartService($eventsManager);

        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clear', 'clearData', 'setData', 'save', 'getId', 'setId'])
            ->addMethods(['where', 'find', 'fetch', 'fetchArray'])
            ->getMock();

        $cartId = 0;
        $cart->method('clear')->willReturnSelf();
        $cart->method('where')->willReturnSelf();
        $cart->method('find')->willReturnSelf();
        $cart->expects($this->once())
            ->method('fetch')
            ->willReturn(null);
        $cart->method('fetchArray')
            ->willReturn([
                Cart::schema_fields_ID => 88,
                Cart::schema_fields_CUSTOMER_ID => 7,
                Cart::schema_fields_PRODUCT_ID => 99,
            ]);
        $cart->method('clearData')->willReturnSelf();
        $cart->method('setData')->willReturnSelf();
        $cart->expects($this->once())
            ->method('save')
            ->willReturn(88);
        $cart->method('getId')
            ->willReturnCallback(function () use (&$cartId): int {
                return $cartId;
            });
        $cart->expects($this->atLeastOnce())
            ->method('setId')
            ->with(88)
            ->willReturnCallback(function (int $id) use (&$cartId, $cart) {
                $cartId = $id;
                return $cart;
            });

        ObjectManager::setInstance(Cart::class, $cart);

        $result = $service->addToCart(7, 99, 2, 19.5);

        $this->assertSame(88, $result->getId());
    }

    public function testRemoveFromCartReturnsTrueAfterSuccessfulDelete(): void
    {
        $eventsManager = $this->createDispatchingEventsManager();
        $service = new CartService($eventsManager);

        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getData', 'getId', 'delete'])
            ->getMock();

        $cart->expects($this->once())
            ->method('load')
            ->with(18)
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('getData')
            ->with(Cart::schema_fields_CUSTOMER_ID)
            ->willReturn(9);
        $cart->expects($this->once())
            ->method('getId')
            ->willReturn(18);
        $cart->expects($this->once())
            ->method('delete')
            ->willReturnSelf();

        ObjectManager::setInstance(Cart::class, $cart);

        $this->assertTrue($service->removeFromCart(18, 9));
    }

    public function testClearCartReturnsTrueAfterDeleteChainCompletes(): void
    {
        $eventsManager = $this->createDispatchingEventsManager();
        $service = new CartService($eventsManager);

        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clear', 'delete'])
            ->addMethods(['where'])
            ->getMock();

        $cart->expects($this->once())
            ->method('clear')
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('where')
            ->with(Cart::schema_fields_CUSTOMER_ID, 23)
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('delete')
            ->willReturnSelf();

        ObjectManager::setInstance(Cart::class, $cart);

        $this->assertTrue($service->clearCart(23));
    }

    public function testUpdateCartRemovesItemWhenQuantityIsZero(): void
    {
        $eventsManager = $this->createDispatchingEventsManager();
        $service = new CartService($eventsManager);

        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getData', 'getId', 'delete'])
            ->getMock();

        $cart->expects($this->once())
            ->method('load')
            ->with(42)
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('getData')
            ->with(Cart::schema_fields_CUSTOMER_ID)
            ->willReturn(7);
        $cart->expects($this->once())
            ->method('getId')
            ->willReturn(42);
        $cart->expects($this->once())
            ->method('delete')
            ->willReturnSelf();

        ObjectManager::setInstance(Cart::class, $cart);

        $this->assertTrue($service->updateCart(42, 0, 7));
    }

    public function testGetCartItemCountReturnsTotalQuantityAcrossItems(): void
    {
        $eventsManager = $this->createDispatchingEventsManager();
        $service = new CartService($eventsManager);

        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clear'])
            ->addMethods(['where', 'select', 'fetchArray'])
            ->getMock();

        $cart->expects($this->once())
            ->method('clear')
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('where')
            ->with(Cart::schema_fields_CUSTOMER_ID, 7)
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('select')
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('fetchArray')
            ->willReturn([
                [Cart::schema_fields_ID => 1, Cart::schema_fields_QUANTITY => 3],
                [Cart::schema_fields_ID => 2, Cart::schema_fields_QUANTITY => 2],
                [Cart::schema_fields_ID => 3, Cart::schema_fields_QUANTITY => 1],
            ]);

        ObjectManager::setInstance(Cart::class, $cart);

        $this->assertSame(6, $service->getCartItemCount(7));
    }

    /**
     * TDD: 登录用户加购测试
     */
    public function testAddToCartAuthenticated(): void
    {
        $eventsManager = $this->createDispatchingEventsManager();
        $service = new CartService($eventsManager);

        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clear', 'clearData', 'setData', 'save', 'getId', 'setId', 'load'])
            ->addMethods(['where', 'find', 'fetch', 'fetchArray'])
            ->getMock();

        $cartId = 0;
        $cart->method('clear')->willReturnSelf();
        $cart->method('where')->willReturnSelf();
        $cart->method('find')->willReturnSelf();
        $cart->expects($this->once())
            ->method('fetch')
            ->willReturn(null);
        $cart->method('fetchArray')
            ->willReturn([
                Cart::schema_fields_ID => 201,
                Cart::schema_fields_CUSTOMER_ID => 55,
                Cart::schema_fields_PRODUCT_ID => 100,
                Cart::schema_fields_QUANTITY => 3,
            ]);
        $cart->method('clearData')->willReturnSelf();
        $cart->method('setData')->willReturnSelf();
        $cart->expects($this->once())
            ->method('save')
            ->willReturn(201);
        $cart->method('getId')
            ->willReturnCallback(function () use (&$cartId): int {
                return $cartId;
            });
        $cart->expects($this->atLeastOnce())
            ->method('setId')
            ->with(201)
            ->willReturnCallback(function (int $id) use (&$cartId, $cart) {
                $cartId = $id;
                return $cart;
            });

        ObjectManager::setInstance(Cart::class, $cart);

        $result = $service->addToCart(55, 100, 3, 29.99);

        $this->assertSame(201, $result->getId());
    }

    /**
     * TDD: 更新购物车商品数量测试
     */
    public function testUpdateCartItemQuantity(): void
    {
        $eventsManager = $this->createDispatchingEventsManager();
        $service = new CartService($eventsManager);

        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getData', 'getId', 'setData', 'save'])
            ->getMock();

        $cart->expects($this->once())
            ->method('load')
            ->with(42)
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('getData')
            ->with(Cart::schema_fields_CUSTOMER_ID)
            ->willReturn(7);
        $cart->expects($this->once())
            ->method('getId')
            ->willReturn(42);
        $cart->expects($this->once())
            ->method('setData')
            ->with(Cart::schema_fields_QUANTITY, 5)
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('save')
            ->willReturn(true);

        ObjectManager::setInstance(Cart::class, $cart);

        $result = $service->updateCart(42, 5, 7);

        $this->assertTrue($result);
    }

    public function testUpdateCartThrowsNotFoundBeforeOwnershipCheckWhenItemIsMissing(): void
    {
        $eventsManager = $this->createDispatchingEventsManager();
        $service = new CartService($eventsManager);

        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getId', 'getData'])
            ->getMock();

        $cart->expects($this->once())
            ->method('load')
            ->with(404)
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('getId')
            ->willReturn(0);
        $cart->expects($this->never())
            ->method('getData');

        ObjectManager::setInstance(Cart::class, $cart);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('购物车项不存在');
        $service->updateCart(404, 1, 7);
    }

    /**
     * TDD: 移除购物车商品测试
     */
    public function testRemoveCartItem(): void
    {
        $eventsManager = $this->createDispatchingEventsManager();
        $service = new CartService($eventsManager);

        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getData', 'getId', 'delete'])
            ->getMock();

        $cart->expects($this->once())
            ->method('load')
            ->with(18)
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('getData')
            ->with(Cart::schema_fields_CUSTOMER_ID)
            ->willReturn(9);
        $cart->expects($this->once())
            ->method('getId')
            ->willReturn(18);
        $cart->expects($this->once())
            ->method('delete')
            ->willReturnSelf();

        ObjectManager::setInstance(Cart::class, $cart);

        $result = $service->removeFromCart(18, 9);

        $this->assertTrue($result);
    }

    public function testRemoveFromCartThrowsNotFoundBeforeOwnershipCheckWhenItemIsMissing(): void
    {
        $eventsManager = $this->createDispatchingEventsManager();
        $service = new CartService($eventsManager);

        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getId', 'getData'])
            ->getMock();

        $cart->expects($this->once())
            ->method('load')
            ->with(404)
            ->willReturnSelf();
        $cart->expects($this->once())
            ->method('getId')
            ->willReturn(0);
        $cart->expects($this->never())
            ->method('getData');

        ObjectManager::setInstance(Cart::class, $cart);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('购物车项不存在');
        $service->removeFromCart(404, 7);
    }

    /**
     * TDD: 获取 mini-cart 数据测试
     */
    public function testGetMiniCartData(): void
    {
        $eventsManager = $this->createDispatchingEventsManager();
        $service = new CartService($eventsManager);

        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clear'])
            ->addMethods(['where', 'select', 'fetchArray'])
            ->getMock();

        $cart->expects($this->once())
            ->method('clear')
            ->willReturnSelf();
        $cart->method('where')->willReturnSelf();
        $cart->method('select')->willReturnSelf();
        $cart->method('fetchArray')
            ->willReturn([
                [
                    Cart::schema_fields_ID => 1,
                    Cart::schema_fields_CUSTOMER_ID => 7,
                    Cart::schema_fields_PRODUCT_ID => 10,
                    Cart::schema_fields_QUANTITY => 2,
                    Cart::schema_fields_PRICE => 25.00,
                ],
                [
                    Cart::schema_fields_ID => 2,
                    Cart::schema_fields_CUSTOMER_ID => 7,
                    Cart::schema_fields_PRODUCT_ID => 20,
                    Cart::schema_fields_QUANTITY => 1,
                    Cart::schema_fields_PRICE => 15.00,
                ],
            ]);

        ObjectManager::setInstance(Cart::class, $cart);

        $count = $service->getCartItemCount(7);

        $this->assertSame(3, $count);
    }

    private function createDispatchingEventsManager(): EventsManager
    {
        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->method('dispatch')
            ->willReturnCallback(function (string $eventName, mixed &$eventData) use ($eventsManager) {
                return $eventsManager;
            });

        return $eventsManager;
    }
}
