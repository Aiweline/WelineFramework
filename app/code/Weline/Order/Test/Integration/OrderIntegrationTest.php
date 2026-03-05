<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Order\Model\OrderPayment;
use Weline\Order\Model\OrderShipment;
use Weline\Order\Service\OrderService;
use Weline\Order\Service\PaymentService;
use Weline\Order\Service\FulfillmentService;

/**
 * 订单模块集成测试
 */
class OrderIntegrationTest extends TestCase
{
    private OrderService $orderService;
    private PaymentService $paymentService;
    private FulfillmentService $fulfillmentService;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->orderService = ObjectManager::getInstance(OrderService::class);
        $this->paymentService = ObjectManager::getInstance(PaymentService::class);
        $this->fulfillmentService = ObjectManager::getInstance(FulfillmentService::class);
    }
    
    /**
     * 测试完整的订单流程
     */
    public function testCompleteOrderFlow()
    {
        $this->markTestIncomplete('TDD: 测试待实现');
        
        // 1. 创建订单
        $orderData = [
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'customer_email' => 'test@example.com',
            'items' => [
                [
                    'product_name' => '测试商品1',
                    'qty_ordered' => 2,
                    'price' => 100.00,
                ],
                [
                    'product_name' => '测试商品2',
                    'qty_ordered' => 1,
                    'price' => 50.00,
                ],
            ],
        ];
        
        $order = $this->orderService->createOrder($orderData);
        $orderId = $order->getId();
        
        $this->assertNotEmpty($orderId);
        $this->assertEquals(Order::STATUS_PENDING, $order->getData(Order::schema_fields_STATUS));
        
        // 2. 处理支付
        $paymentData = [
            'amount' => 250.00,
            'payment_method' => 'alipay',
            'transaction_id' => 'TXN123456',
        ];
        
        $payment = $this->paymentService->processPayment($orderId, $paymentData);
        $this->assertNotEmpty($payment->getId());
        
        // 3. 创建发货记录
        $shipmentData = [
            'tracking_number' => 'SF1234567890',
            'carrier' => '顺丰速运',
        ];
        
        $shipment = $this->fulfillmentService->createShipment($orderId, $shipmentData);
        $this->assertNotEmpty($shipment->getId());
        
        // 4. 验证订单状态
        $finalOrder = $this->orderService->getOrder($orderId);
        $this->assertEquals(Order::STATUS_FULFILLED, $finalOrder->getData(Order::schema_fields_STATUS));
    }
    
    /**
     * 测试数据库表存在性
     */
    public function testDatabaseTablesExist()
    {
        $this->markTestIncomplete('TDD: 测试待实现');
        
        $tables = [
            'weline_order' => ObjectManager::getInstance(Order::class),
            'weline_order_item' => ObjectManager::getInstance(OrderItem::class),
            'weline_order_payment' => ObjectManager::getInstance(OrderPayment::class),
            'weline_order_shipment' => ObjectManager::getInstance(OrderShipment::class),
        ];
        
        foreach ($tables as $tableName => $model) {
            $connection = $model->getConnection();
            $tableExists = $connection->getConnector()->tableExist($model->getTable());
            $this->assertTrue($tableExists, "表 {$tableName} 不存在");
        }
    }
}

