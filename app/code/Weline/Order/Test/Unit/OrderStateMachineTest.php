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
use Weline\Order\Service\OrderStateMachine;
use Weline\Order\Model\Order;

/**
 * 订单状态机单元测试
 */
class OrderStateMachineTest extends TestCase
{
    private OrderStateMachine $stateMachine;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = ObjectManager::getInstance(OrderStateMachine::class);
    }
    
    /**
     * 测试状态转换检查
     */
    public function testCanTransition()
    {
        $this->markTestIncomplete('TDD: 测试待实现');
        
        // 测试允许的转换
        $this->assertTrue($this->stateMachine->canTransition(Order::STATUS_PENDING, Order::STATUS_PROCESSING));
        $this->assertTrue($this->stateMachine->canTransition(Order::STATUS_PROCESSING, Order::STATUS_PAID));
        $this->assertTrue($this->stateMachine->canTransition(Order::STATUS_PAID, Order::STATUS_FULFILLED));
        
        // 测试不允许的转换
        $this->assertFalse($this->stateMachine->canTransition(Order::STATUS_PENDING, Order::STATUS_COMPLETED));
        $this->assertFalse($this->stateMachine->canTransition(Order::STATUS_CANCELLED, Order::STATUS_PAID));
    }
    
    /**
     * 测试执行状态转换
     */
    public function testTransition()
    {
        $this->markTestIncomplete('TDD: 测试待实现');
        
        // 先创建订单
        $orderService = ObjectManager::getInstance(\Weline\Order\Service\OrderService::class);
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
        
        $order = $orderService->createOrder($data);
        $orderId = $order->getId();
        
        // 执行状态转换
        $this->stateMachine->transition($orderId, Order::STATUS_PROCESSING, '开始处理订单');
        
        $updatedOrder = $orderService->getOrder($orderId);
        $this->assertEquals(Order::STATUS_PROCESSING, $updatedOrder->getData(Order::schema_fields_STATUS));
    }
    
    /**
     * 测试获取可用状态转换
     */
    public function testGetAvailableTransitions()
    {
        $this->markTestIncomplete('TDD: 测试待实现');
        
        $transitions = $this->stateMachine->getAvailableTransitions(Order::STATUS_PENDING);
        
        $this->assertIsArray($transitions);
        $this->assertContains(Order::STATUS_PROCESSING, $transitions);
        $this->assertContains(Order::STATUS_CANCELLED, $transitions);
    }
}

