<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Service\OrderService;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;

/**
 * 订单服务单元测试
 */
class OrderServiceTest extends TestCase
{
    private OrderService $orderService;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->orderService = ObjectManager::getInstance(OrderService::class);
    }
    
    /**
     * 测试创建订单
     */
    public function testCreateOrder()
    {
        $this->markTestIncomplete('TDD: 测试待实现');
        
        $data = [
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'customer_email' => 'test@example.com',
            'items' => [
                [
                    'product_name' => '测试商品',
                    'qty_ordered' => 2,
                    'price' => 100.00,
                ],
            ],
        ];
        
        $order = $this->orderService->createOrder($data);
        
        $this->assertInstanceOf(Order::class, $order);
        $this->assertNotEmpty($order->getId());
        $this->assertNotEmpty($order->getData(Order::fields_ORDER_NUMBER));
    }
    
    /**
     * 测试获取订单详情
     */
    public function testGetOrder()
    {
        $this->markTestIncomplete('TDD: 测试待实现');
        
        // 先创建订单
        $data = [
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'items' => [
                [
                    'product_name' => '测试商品',
                    'qty_ordered' => 1,
                    'price' => 100.00,
                ],
            ],
        ];
        
        $order = $this->orderService->createOrder($data);
        $orderId = $order->getId();
        
        // 获取订单
        $retrievedOrder = $this->orderService->getOrder($orderId);
        
        $this->assertEquals($orderId, $retrievedOrder->getId());
    }
    
    /**
     * 测试取消订单
     */
    public function testCancelOrder()
    {
        $this->markTestIncomplete('TDD: 测试待实现');
        
        // 先创建订单
        $data = [
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'items' => [
                [
                    'product_name' => '测试商品',
                    'qty_ordered' => 1,
                    'price' => 100.00,
                ],
            ],
        ];
        
        $order = $this->orderService->createOrder($data);
        $orderId = $order->getId();
        
        // 取消订单
        $this->orderService->cancelOrder($orderId, '测试取消原因');
        
        $cancelledOrder = $this->orderService->getOrder($orderId);
        $this->assertEquals(Order::STATUS_CANCELLED, $cancelledOrder->getData(Order::fields_STATUS));
    }
    
    /**
     * 测试计算订单总额
     */
    public function testCalculateTotals()
    {
        $this->markTestIncomplete('TDD: 测试待实现');
        
        $order = ObjectManager::getInstance(Order::class);
        $items = [
            [
                'qty_ordered' => 2,
                'price' => 100.00,
                'tax_amount' => 10.00,
                'discount_amount' => 5.00,
            ],
            [
                'qty_ordered' => 1,
                'price' => 50.00,
                'tax_amount' => 5.00,
                'discount_amount' => 0.00,
            ],
        ];
        
        $this->orderService->calculateTotals($order, $items);
        
        $this->assertEquals(250.00, $order->getData(Order::fields_SUBTOTAL));
        $this->assertEquals(15.00, $order->getData(Order::fields_TAX_AMOUNT));
        $this->assertEquals(5.00, $order->getData(Order::fields_DISCOUNT_AMOUNT));
    }
}

