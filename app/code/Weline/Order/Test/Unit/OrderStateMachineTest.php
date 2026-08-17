<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Test\Unit;

use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Order\Service\OrderStateMachine;
use Weline\Order\Service\OrderStateTransitionException;
use Weline\Order\Model\Order;

/**
 * 订单状态机单元测试
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class OrderStateMachineTest extends TestCase
{
    private OrderStateMachine $stateMachine;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = ObjectManager::getInstance(OrderStateMachine::class);
    }
    
    public function testCanTransition(): void
    {
        $this->assertTrue($this->stateMachine->canTransition(Order::STATUS_PENDING, Order::STATUS_PROCESSING));
        $this->assertTrue($this->stateMachine->canTransition(Order::STATUS_PROCESSING, Order::STATUS_PAID));
        $this->assertTrue($this->stateMachine->canTransition(Order::STATUS_PAID, Order::STATUS_FULFILLED));
        $this->assertTrue($this->stateMachine->canTransition(Order::STATUS_PENDING, Order::STATUS_PENDING));
        $this->assertFalse($this->stateMachine->canTransition(Order::STATUS_PENDING, Order::STATUS_COMPLETED));
        $this->assertFalse($this->stateMachine->canTransition(Order::STATUS_CANCELLED, Order::STATUS_PAID));
    }

    public function testTransitionUsesCasAndSameStateRetryIsNoop(): void
    {
        [$path, $connection, $connector, $model] = $this->database();
        $events = [];
        try {
            $machine = $this->machine(
                $model,
                static function (string $name, array $data) use (&$events): array {
                    $events[] = $name;
                    return $data;
                },
            );
            $updated = $machine->transition(1, Order::STATUS_PROCESSING, 'start');
            self::assertSame(Order::STATUS_PROCESSING, $updated->getData(Order::schema_fields_STATUS));
            self::assertSame(1, (int)$updated->getData(Order::schema_fields_STATE_VERSION));
            self::assertSame([
                'Weline_Order::order_status_can_transition',
                'Weline_Order::order_status_change_before',
                'Weline_Order::order_status_changed',
            ], $events);

            $events = [];
            $retried = $machine->transition(1, Order::STATUS_PROCESSING, 'retry');
            self::assertSame(1, (int)$retried->getData(Order::schema_fields_STATE_VERSION));
            self::assertSame([], $events);
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
    }

    public function testConcurrentMutationFailsWithStableConflictAndRollsBack(): void
    {
        [$path, $connection, $connector, $model] = $this->database();
        try {
            $machine = $this->machine(
                $model,
                static fn (string $name, array $data): array => $data,
                static fn (int $orderId, string $from, int $version, string $to): bool => false,
            );
            try {
                $machine->transition(1, Order::STATUS_PROCESSING);
                self::fail('stale transition must fail');
            } catch (OrderStateTransitionException $exception) {
                self::assertSame(OrderStateMachine::ERROR_STALE, $exception->errorCode());
            }
            $row = $connector->query(
                'SELECT status,state_version FROM weline_order WHERE order_id=1'
            )->fetch();
            self::assertSame(Order::STATUS_PENDING, $row[0]['status'] ?? null);
            self::assertSame(0, (int)($row[0]['state_version'] ?? -1));
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
    }

    public function testSequentialTransitionsInsideOneTransactionUseFreshCasPostImages(): void
    {
        [$path, $connection, $connector, $model] = $this->database();
        $model->beginTransaction();
        try {
            $machine = $this->machine(
                $model,
                static fn (string $name, array $data): array => $data,
            );

            $machine->transition(1, Order::STATUS_PROCESSING, 'confirm');
            $paid = $machine->transition(1, Order::STATUS_PAID, 'paid');

            self::assertSame(Order::STATUS_PAID, $paid->getData(Order::schema_fields_STATUS));
            self::assertSame(2, (int)$paid->getData(Order::schema_fields_STATE_VERSION));
            $row = $connector->query(
                'SELECT status,state,state_version FROM weline_order WHERE order_id=1'
            )->fetch()[0] ?? [];
            self::assertSame(Order::STATUS_PAID, $row['status'] ?? null);
            self::assertSame(Order::STATUS_PAID, $row['state'] ?? null);
            self::assertSame(2, (int)($row['state_version'] ?? -1));
        } finally {
            $model->rollBack();
            $this->cleanup($path, $connection, $connector);
        }
    }

    public function testModelLoadIdentityMapIsIsolatedByFrameworkRequestId(): void
    {
        [$path, $connection, $connector] = $this->database();
        try {
            $fiberA = new \Fiber(static function () use ($connection): array {
                Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
                RequestContext::setId('order-request-a');
                $model = new Order();
                $model->setConnection($connection);
                $model->__init();
                $first = (string)$model->load(1)->getData(Order::schema_fields_STATUS);
                \Fiber::suspend($first);
                $cached = (string)$model->load(1)->getData(Order::schema_fields_STATUS);
                RequestContext::cleanup();
                Context::leave();

                return [$first, $cached];
            });
            self::assertSame(Order::STATUS_PENDING, $fiberA->start());

            $connector->query(
                "UPDATE weline_order SET status='processing',state='processing',state_version=1 WHERE order_id=1"
            )->fetch();

            $fiberB = new \Fiber(static function () use ($connection): string {
                Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
                RequestContext::setId('order-request-b');
                $model = new Order();
                $model->setConnection($connection);
                $model->__init();
                $status = (string)$model->load(1)->getData(Order::schema_fields_STATUS);
                RequestContext::cleanup();
                Context::leave();

                return $status;
            });
            self::assertNull($fiberB->start());
            self::assertTrue($fiberB->isTerminated());
            self::assertSame(Order::STATUS_PROCESSING, $fiberB->getReturn());

            self::assertNull($fiberA->resume());
            self::assertTrue($fiberA->isTerminated());
            self::assertSame(
                [Order::STATUS_PENDING, Order::STATUS_PENDING],
                $fiberA->getReturn(),
            );

            $connector->query(
                "UPDATE weline_order SET status='paid',state='paid',state_version=2 WHERE order_id=1"
            )->fetch();
            $fiberC = new \Fiber(static function () use ($connection): string {
                Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
                RequestContext::setId('order-request-a');
                $model = new Order();
                $model->setConnection($connection);
                $model->__init();
                $status = (string)$model->load(1)->getData(Order::schema_fields_STATUS);
                RequestContext::cleanup();
                Context::leave();

                return $status;
            });
            self::assertNull($fiberC->start());
            self::assertTrue($fiberC->isTerminated());
            self::assertSame(Order::STATUS_PAID, $fiberC->getReturn());
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
    }

    public function testGetAvailableTransitions(): void
    {
        $transitions = $this->stateMachine->getAvailableTransitions(Order::STATUS_PENDING);
        $this->assertIsArray($transitions);
        $this->assertContains(Order::STATUS_PROCESSING, $transitions);
        $this->assertContains(Order::STATUS_CANCELLED, $transitions);
    }

    /**
     * @return array{string,ConnectionFactory,ConnectorInterface,Order}
     */
    private function database(): array
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        $path = sys_get_temp_dir() . '/weline_order_state_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $path,
            'persistent' => false,
        ]));
        $connector = $connection->getConnector();
        $connector->query(
            'CREATE TABLE weline_order ('
            . 'order_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'status VARCHAR(50) NOT NULL, state VARCHAR(50) NOT NULL, '
            . 'state_version INTEGER NOT NULL DEFAULT 0)'
        )->fetch();
        $connector->query(
            "INSERT INTO weline_order(status,state,state_version) VALUES('pending','pending',0)"
        )->fetch();
        $model = new Order();
        $model->setConnection($connection);
        $model->__init();

        return [$path, $connection, $connector, $model];
    }

    private function machine(
        Order $model,
        \Closure $dispatcher,
        ?\Closure $stateCompareAndSet = null,
    ): OrderStateMachine
    {
        return new OrderStateMachine(
            ObjectManager::getInstance(ObjectManager::class),
            ObjectManager::getInstance(EventsManager::class),
            $model,
            $dispatcher,
            $stateCompareAndSet,
        );
    }

    private function cleanup(
        string $path,
        ConnectionFactory $connection,
        ConnectorInterface $connector,
    ): void {
        $connector->close();
        $connection->close();
        if (is_file($path)) {
            unlink($path);
        }
    }
}
