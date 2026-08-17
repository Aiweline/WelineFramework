<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\DisplayNumberRef;
use Weline\Order\Api\Data\MoneySnapshot;
use Weline\Order\Api\Data\OrderPaidContext;
use Weline\Order\Api\Data\ScopeSnapshot;
use Weline\Order\Api\OrderFacadeConflictException;
use Weline\Order\Api\OrderPostPaymentHookInterface;
use Weline\Order\Model\Order;
use Weline\Order\Service\OrderPaidStateHook;
use Weline\Order\Service\OrderStateMachine;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrderPaidStateHookTest extends TestCase
{
    public function testModuleProviderTargetsTheGenericPaidStateHook(): void
    {
        /** @var array<string, mixed> $module */
        $module = require dirname(__DIR__, 3) . '/etc/module.php';

        self::assertSame(
            OrderPaidStateHook::class,
            $module['provides'][OrderPostPaymentHookInterface::class] ?? null,
        );
        self::assertTrue(is_subclass_of(
            OrderPaidStateHook::class,
            OrderPostPaymentHookInterface::class,
        ));
    }

    public function testPendingOrderAdvancesThroughTheGenericStateMachineOnlyOnce(): void
    {
        [$path, $connection, $connector, $model] = $this->database();
        $paidEvents = [];
        try {
            $hook = $this->hook($model, $paidEvents);
            $context = $this->context();

            $hook->afterOrderPaid($context);

            $row = $this->row($connector);
            self::assertSame(Order::STATUS_PAID, $row['status'] ?? null);
            self::assertSame(Order::STATUS_PAID, $row['state'] ?? null);
            self::assertSame(Order::PAYMENT_STATUS_PAID, $row['payment_status'] ?? null);
            self::assertSame(2, (int)($row['state_version'] ?? -1));
            self::assertCount(1, $paidEvents);
            self::assertSame('Weline_Order::order_paid', $paidEvents[0]['name'] ?? null);
            self::assertSame($context, $paidEvents[0]['data']['context'] ?? null);
            self::assertSame($context->orderUuid, $paidEvents[0]['data']['order_uuid'] ?? null);
            self::assertSame(
                Order::STATUS_PAID,
                $paidEvents[0]['data']['order']?->getData(Order::schema_fields_STATUS),
            );

            $hook->afterOrderPaid($context);

            $replayed = $this->row($connector);
            self::assertSame(2, (int)($replayed['state_version'] ?? -1));
            self::assertSame(Order::PAYMENT_STATUS_PAID, $replayed['payment_status'] ?? null);
            self::assertCount(1, $paidEvents);
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
    }

    public function testProcessingOrderAdvancesDirectlyToPaid(): void
    {
        [$path, $connection, $connector, $model] = $this->database(
            Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS_PENDING,
            7,
        );
        $paidEvents = [];
        try {
            $this->hook($model, $paidEvents)->afterOrderPaid($this->context());

            $row = $this->row($connector);
            self::assertSame(Order::STATUS_PAID, $row['status'] ?? null);
            self::assertSame(Order::PAYMENT_STATUS_PAID, $row['payment_status'] ?? null);
            self::assertSame(8, (int)($row['state_version'] ?? -1));
            self::assertCount(1, $paidEvents);
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
    }

    public function testPersistedContextMismatchFailsClosedWithoutMutation(): void
    {
        [$path, $connection, $connector, $model] = $this->database();
        $paidEvents = [];
        try {
            try {
                $this->hook($model, $paidEvents)->afterOrderPaid($this->context(websiteId: 99));
                self::fail('A caller-supplied scope must not replace the persisted Order scope.');
            } catch (OrderFacadeConflictException $exception) {
                self::assertSame(OrderPaidStateHook::ERROR_CONTEXT_MISMATCH, $exception->errorCode());
            }

            $row = $this->row($connector);
            self::assertSame(Order::STATUS_PENDING, $row['status'] ?? null);
            self::assertSame(Order::PAYMENT_STATUS_PENDING, $row['payment_status'] ?? null);
            self::assertSame(0, (int)($row['state_version'] ?? -1));
            self::assertSame([], $paidEvents);
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
    }

    public function testCancelledOrderRejectsLatePaidNotification(): void
    {
        [$path, $connection, $connector, $model] = $this->database(
            Order::STATUS_CANCELLED,
        );
        $paidEvents = [];
        try {
            try {
                $this->hook($model, $paidEvents)->afterOrderPaid($this->context());
                self::fail('A cancelled Order must not be revived by a late payment notification.');
            } catch (OrderFacadeConflictException $exception) {
                self::assertSame(OrderPaidStateHook::ERROR_INELIGIBLE_STATE, $exception->errorCode());
            }

            $row = $this->row($connector);
            self::assertSame(Order::STATUS_CANCELLED, $row['status'] ?? null);
            self::assertSame(Order::PAYMENT_STATUS_PENDING, $row['payment_status'] ?? null);
            self::assertSame([], $paidEvents);
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
    }

    public function testUnknownOrderFailsClosed(): void
    {
        [$path, $connection, $connector, $model] = $this->database(insertOrder: false);
        $paidEvents = [];
        try {
            try {
                $this->hook($model, $paidEvents)->afterOrderPaid($this->context());
                self::fail('An unknown Order UUID must fail closed.');
            } catch (OrderFacadeConflictException $exception) {
                self::assertSame(OrderPaidStateHook::ERROR_NOT_FOUND, $exception->errorCode());
            }
            self::assertSame([], $paidEvents);
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
    }

    /**
     * @param list<array{name:string,data:array<string,mixed>}> $paidEvents
     */
    private function hook(Order $model, array &$paidEvents): OrderPaidStateHook
    {
        $objectManager = ObjectManager::getInstance(ObjectManager::class);
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $stateMachine = new OrderStateMachine(
            $objectManager,
            $eventsManager,
            $model,
            static fn (string $name, array $data): array => $data,
        );

        return new OrderPaidStateHook(
            $objectManager,
            $eventsManager,
            $model,
            $stateMachine,
            static function (string $name, array $data) use (&$paidEvents): void {
                $paidEvents[] = ['name' => $name, 'data' => $data];
            },
        );
    }

    private function context(int $websiteId = 1): OrderPaidContext
    {
        return new OrderPaidContext(
            orderUuid: 'order-universal-1',
            money: new MoneySnapshot('CNY', 100, 20, 10, 5, 125),
            scope: new ScopeSnapshot($websiteId, 2, 'CNY', 'zh_CN'),
            displayNumber: new DisplayNumberRef(
                'order',
                'ORD-UNIVERSAL-1',
                'order-universal-1',
                $websiteId,
                2,
            ),
            metadata: ['intent_code' => 'intent-universal-1'],
        );
    }

    /**
     * @return array{string,ConnectionFactory,ConnectorInterface,Order}
     */
    private function database(
        string $status = Order::STATUS_PENDING,
        string $paymentStatus = Order::PAYMENT_STATUS_PENDING,
        int $stateVersion = 0,
        bool $insertOrder = true,
    ): array {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        $path = sys_get_temp_dir() . '/weline_order_paid_hook_' . bin2hex(random_bytes(8)) . '.sqlite';
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
            . 'order_number VARCHAR(64) NOT NULL, order_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, currency VARCHAR(3) NOT NULL, '
            . 'status VARCHAR(50) NOT NULL, state VARCHAR(50) NOT NULL, '
            . 'payment_status VARCHAR(50) NOT NULL, state_version INTEGER NOT NULL DEFAULT 0, '
            . 'money_snapshot_json TEXT NOT NULL, scope_snapshot_json TEXT NOT NULL)'
        )->fetch();
        if ($insertOrder) {
            $money = json_encode([
                'currency' => 'CNY',
                'subtotal_minor' => 100,
                'shipping_amount_minor' => 20,
                'tax_amount_minor' => 10,
                'discount_amount_minor' => 5,
                'grand_total_minor' => 125,
            ], JSON_THROW_ON_ERROR);
            $scope = json_encode([
                'website_id' => 1,
                'store_id' => 2,
                'currency' => 'CNY',
                'locale' => 'zh_CN',
            ], JSON_THROW_ON_ERROR);
            $connector->query(sprintf(
                "INSERT INTO weline_order (order_number,order_uuid,website_id,store_id,currency,"
                . "status,state,payment_status,state_version,money_snapshot_json,scope_snapshot_json) "
                . "VALUES ('ORD-UNIVERSAL-1','order-universal-1',1,2,'CNY','%s','%s','%s',%d,'%s','%s')",
                $status,
                $status,
                $paymentStatus,
                $stateVersion,
                $money,
                $scope,
            ))->fetch();
        }
        $model = new Order();
        $model->setConnection($connection);
        $model->__init();

        return [$path, $connection, $connector, $model];
    }

    /** @return array<string, mixed> */
    private function row(ConnectorInterface $connector): array
    {
        return $connector->query(
            "SELECT status,state,payment_status,state_version FROM weline_order "
            . "WHERE order_uuid='order-universal-1'"
        )->fetch()[0] ?? [];
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
