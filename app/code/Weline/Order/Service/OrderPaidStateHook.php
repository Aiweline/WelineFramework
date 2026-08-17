<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\MoneySnapshot;
use Weline\Order\Api\Data\OrderPaidContext;
use Weline\Order\Api\Data\ScopeSnapshot;
use Weline\Order\Api\OrderFacadeConflictException;
use Weline\Order\Api\OrderPostPaymentHookInterface;
use Weline\Order\Model\DisplayNumberRegistry;
use Weline\Order\Model\Order;

/**
 * Order-owned, payment-provider-neutral paid-state projection.
 *
 * The hook trusts only the persisted immutable Order snapshots. Payment
 * metadata remains extension data and can never select another Order, scope,
 * amount or display number.
 */
final class OrderPaidStateHook implements OrderPostPaymentHookInterface
{
    public const ERROR_NOT_FOUND = 'order_paid_order_not_found';
    public const ERROR_CONTEXT_MISMATCH = 'order_paid_context_persisted_fact_mismatch';
    public const ERROR_INELIGIBLE_STATE = 'order_paid_state_ineligible';
    public const ERROR_STALE = 'order_paid_state_conflict';

    private ?Order $orderModel;
    private ?OrderStateMachine $stateMachine;

    /** @var (\Closure(string,array<string,mixed>):void)|null */
    private ?\Closure $eventDispatcher;

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly EventsManager $eventsManager,
        ?Order $orderModel = null,
        ?OrderStateMachine $stateMachine = null,
        ?\Closure $eventDispatcher = null,
    ) {
        $this->orderModel = $orderModel;
        $this->stateMachine = $stateMachine;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function afterOrderPaid(OrderPaidContext $context): void
    {
        $transaction = $this->newOrder();
        $ownsTransaction = TransactionContext::transactionState(
            $transaction->getConnection()->getConnector(),
        ) === null;
        if ($ownsTransaction) {
            $transaction->beginTransaction();
        }

        try {
            $order = $this->loadForUpdate(trim($context->orderUuid));
            if ($order === null) {
                throw new OrderFacadeConflictException(
                    self::ERROR_NOT_FOUND,
                    \__('Order 不存在：%{1}', [$context->orderUuid]),
                    ['order_uuid' => $context->orderUuid],
                );
            }
            $this->assertPersistedContext($order, $context);

            $orderId = (int)$order->getId();
            $status = (string)$order->getData(Order::schema_fields_STATUS);
            $paymentStatus = (string)$order->getData(Order::schema_fields_PAYMENT_STATUS);
            $this->assertEligible($orderId, $status, $paymentStatus);

            $firstPaidProjection = $paymentStatus !== Order::PAYMENT_STATUS_PAID;
            if ($firstPaidProjection) {
                $this->markPaymentPaid($orderId, $paymentStatus);
            }
            $this->advanceToPaid($orderId, $status);

            $publishedOrder = $this->findByIdFresh($orderId);
            if ($publishedOrder === null) {
                throw new OrderFacadeConflictException(
                    self::ERROR_STALE,
                    \__('订单支付状态提交后无法重新读取'),
                    ['order_id' => $orderId],
                );
            }
            if ($firstPaidProjection) {
                $this->dispatch('Weline_Order::order_paid', [
                    'order' => $publishedOrder,
                    'order_id' => $orderId,
                    'order_uuid' => $context->orderUuid,
                    'context' => $context,
                    'metadata' => $context->metadata,
                    // Legacy observers may read this key. The generic Facade
                    // path deliberately does not expose a Payment Model.
                    'payment' => null,
                ]);
            }

            if ($ownsTransaction) {
                $transaction->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    private function loadForUpdate(string $orderUuid): ?Order
    {
        $query = $this->newOrder()->where(Order::schema_fields_ORDER_UUID, $orderUuid);
        if (!$this->isSqlite($query)) {
            $query->additional('FOR UPDATE');
        }
        $order = $query->find()->fetch();

        return $order instanceof Order && $order->getId() ? $order : null;
    }

    private function assertPersistedContext(Order $order, OrderPaidContext $context): void
    {
        try {
            $moneyData = $this->decodeSnapshot(
                (string)$order->getData(Order::schema_fields_MONEY_SNAPSHOT_JSON),
            );
            $scopeData = $this->decodeSnapshot(
                (string)$order->getData(Order::schema_fields_SCOPE_SNAPSHOT_JSON),
            );
            $this->assertRequiredKeys($moneyData, [
                'currency',
                'subtotal_minor',
                'shipping_amount_minor',
                'tax_amount_minor',
                'discount_amount_minor',
                'grand_total_minor',
            ]);
            $this->assertRequiredKeys($scopeData, [
                'website_id',
                'store_id',
                'currency',
                'locale',
            ]);
            $persistedMoney = MoneySnapshot::fromArray($moneyData);
            $persistedScope = ScopeSnapshot::fromArray([
                'website_id' => (int)$order->getData(Order::schema_fields_WEBSITE_ID),
                'store_id' => (int)$order->getData(Order::schema_fields_STORE_ID),
                'currency' => (string)$order->getData(Order::schema_fields_CURRENCY),
                ...$scopeData,
            ]);
        } catch (\Throwable $exception) {
            throw new OrderFacadeConflictException(
                self::ERROR_CONTEXT_MISMATCH,
                \__('Order 持久化快照无效'),
                ['order_uuid' => $context->orderUuid],
                $exception,
            );
        }

        $matches = (string)$order->getData(Order::schema_fields_ORDER_UUID)
                === $context->orderUuid
            && (string)$order->getData(Order::schema_fields_ORDER_NUMBER)
                === $context->displayNumber->displayNumber
            && $context->displayNumber->numberKind === DisplayNumberRegistry::KIND_ORDER
            && $persistedMoney->currency
                === (string)$order->getData(Order::schema_fields_CURRENCY)
            && $persistedScope->websiteId
                === (int)$order->getData(Order::schema_fields_WEBSITE_ID)
            && $persistedScope->storeId
                === (int)$order->getData(Order::schema_fields_STORE_ID)
            && $persistedScope->currency
                === (string)$order->getData(Order::schema_fields_CURRENCY)
            && $persistedMoney->toArray() === $context->money->toArray()
            && $persistedScope->toArray() === $context->scope->toArray();
        if (!$matches) {
            throw new OrderFacadeConflictException(
                self::ERROR_CONTEXT_MISMATCH,
                \__('支付通知与 Order 持久化事实不一致'),
                ['order_uuid' => $context->orderUuid],
            );
        }
    }

    private function assertEligible(int $orderId, string $status, string $paymentStatus): void
    {
        $eligibleStates = [
            Order::STATUS_PENDING,
            Order::STATUS_PROCESSING,
            Order::STATUS_PAID,
            Order::STATUS_FULFILLED,
            Order::STATUS_COMPLETED,
        ];
        $eligiblePaymentStates = [
            Order::PAYMENT_STATUS_PENDING,
            Order::PAYMENT_STATUS_PARTIAL,
            Order::PAYMENT_STATUS_PAID,
        ];
        if (!\in_array($status, $eligibleStates, true)
            || !\in_array($paymentStatus, $eligiblePaymentStates, true)
        ) {
            throw new OrderFacadeConflictException(
                self::ERROR_INELIGIBLE_STATE,
                \__('当前订单状态不能接受支付成功通知'),
                [
                    'order_id' => $orderId,
                    'status' => $status,
                    'payment_status' => $paymentStatus,
                ],
            );
        }
    }

    private function markPaymentPaid(int $orderId, string $paymentStatus): void
    {
        $writer = $this->newOrder()
            ->where(Order::schema_fields_ID, $orderId)
            ->where(Order::schema_fields_PAYMENT_STATUS, $paymentStatus);
        $writer->update([
            Order::schema_fields_PAYMENT_STATUS => Order::PAYMENT_STATUS_PAID,
        ])->fetch();
        $reloaded = $this->findByIdFresh($orderId);
        if ($reloaded === null
            || (string)$reloaded->getData(Order::schema_fields_PAYMENT_STATUS)
                !== Order::PAYMENT_STATUS_PAID
        ) {
            throw new OrderFacadeConflictException(
                self::ERROR_STALE,
                \__('订单支付状态已被并发修改'),
                ['order_id' => $orderId, 'payment_status' => $paymentStatus],
            );
        }
    }

    private function advanceToPaid(int $orderId, string $status): void
    {
        try {
            if ($status === Order::STATUS_PENDING) {
                $this->stateMachine()->transition(
                    $orderId,
                    Order::STATUS_PROCESSING,
                    \__('支付成功，订单进入处理'),
                );
                $status = Order::STATUS_PROCESSING;
            }
            if ($status === Order::STATUS_PROCESSING) {
                $this->stateMachine()->transition(
                    $orderId,
                    Order::STATUS_PAID,
                    \__('订单已支付'),
                );
            }
        } catch (OrderStateTransitionException $exception) {
            $errorCode = match ($exception->errorCode()) {
                OrderStateMachine::ERROR_NOT_FOUND => self::ERROR_NOT_FOUND,
                OrderStateMachine::ERROR_STALE => self::ERROR_STALE,
                default => self::ERROR_INELIGIBLE_STATE,
            };
            throw new OrderFacadeConflictException(
                $errorCode,
                $exception->getMessage(),
                $exception->context(),
                $exception,
            );
        }
    }

    /** @return array<string, mixed> */
    private function decodeSnapshot(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('order_paid_snapshot_invalid');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param list<string> $requiredKeys
     */
    private function assertRequiredKeys(array $snapshot, array $requiredKeys): void
    {
        foreach ($requiredKeys as $requiredKey) {
            if (!\array_key_exists($requiredKey, $snapshot)) {
                throw new \UnexpectedValueException('order_paid_snapshot_field_missing:' . $requiredKey);
            }
        }
    }

    private function stateMachine(): OrderStateMachine
    {
        return $this->stateMachine ??= $this->objectManager->getInstance(OrderStateMachine::class);
    }

    private function newOrder(): Order
    {
        $this->orderModel ??= $this->objectManager->getInstance(Order::class);

        return (clone $this->orderModel)->clear();
    }

    private function findByIdFresh(int $orderId): ?Order
    {
        $order = $this->newOrder()
            ->where(Order::schema_fields_ID, $orderId)
            ->find()
            ->fetch();

        return $order instanceof Order && $order->getId() ? $order : null;
    }

    /** @param array<string, mixed> $data */
    private function dispatch(string $eventName, array $data): void
    {
        if ($this->eventDispatcher !== null) {
            ($this->eventDispatcher)($eventName, $data);
            return;
        }
        $this->eventsManager->dispatch($eventName, $data);
    }

    private function isSqlite(Order $order): bool
    {
        return \strtolower((string)$order->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }
}
