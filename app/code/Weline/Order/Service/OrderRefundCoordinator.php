<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\InventoryRefundCapabilityInterface;
use Weline\Inventory\Api\WarehouseInventoryCapabilityInterface;
use Weline\Order\Api\RefundAssetReturnCapabilityInterface;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Order\Model\RefundCase;
use Weline\Order\Model\RefundOutbox;
use Weline\Payment\Api\Data\RefundOperationResult;
use Weline\Payment\Api\Data\RefundReserveCommand;
use Weline\Payment\Api\PaymentRefundFacadeInterface;

/**
 * 并发安全退款协调（MOD-P2F-005）。
 * 锁内：服务端重算 → 占额/占量 → 写 RefundCase + PaymentRefund + provider outbox。
 * pending/unknown 持续占额；仅权威 terminal failed 释放；迟到成功 → late_success_review。
 */
final class OrderRefundCoordinator
{
    public const ERROR_AMOUNT_EXCEEDS = 'refund_amount_exceeds_remaining';
    public const ERROR_QTY_EXCEEDS = 'refund_qty_exceeds_remaining';
    public const ERROR_NEW_DISABLED = 'refund_new_disabled';
    public const ERROR_ORDER_FROZEN = 'refund_order_frozen_late_success_review';
    public const ERROR_NOT_FOUND = 'refund_case_not_found';
    public const ERROR_ITEMS_REQUIRED = 'refund_items_required';
    public const ERROR_IDEMPOTENCY_CONFLICT = 'refund_idempotency_conflict';
    public const ERROR_PAYMENT_NOT_CAPTURED = 'refund_payment_not_captured';
    public const ERROR_TRANSACTION = 'refund_transaction_failed';

    public const CUSTOMER_VIEW_PROCESSING = 'processing';
    public const CUSTOMER_VIEW_SUCCEEDED = 'succeeded';
    public const CUSTOMER_VIEW_FAILED = 'failed';

    private const PAYMENT_CHANNEL_NOT_SUBMITTED = 'not_submitted';
    private const PAYMENT_CHANNEL_SUBMITTED = 'submitted';
    private const PAYMENT_CHANNEL_PENDING = 'pending';
    private const PAYMENT_CHANNEL_SUCCEEDED = 'succeeded';
    private const PAYMENT_CHANNEL_FAILED = 'failed';
    private const PAYMENT_CHANNEL_UNKNOWN = 'unknown';
    private const PAYMENT_STATUS_REQUESTED = 'requested';
    private const PAYMENT_STATUS_PROCESSING = 'processing';
    private const PAYMENT_STATUS_PENDING = 'pending';
    private const PAYMENT_STATUS_UNKNOWN = 'unknown';
    private const PAYMENT_STATUS_REFUNDED = 'refunded';
    private const PAYMENT_STATUS_FAILED = 'failed';
    private const PAYMENT_STATUS_LATE_SUCCESS_REVIEW = 'refund_late_success_review';

    private bool $newRefundsEnabled = true;
    private bool $crashBeforeSecondTransaction = false;

    private ?ObjectManager $objectManager;
    private ?WriteIntentTransactionCoordinatorInterface $transactions;
    private ?PaymentRefundFacadeInterface $paymentRefunds;
    private ?InventoryRefundCapabilityInterface $inventoryRefunds;
    private ?WarehouseInventoryCapabilityInterface $warehouseInventory;
    private ?RefundAssetReturnCapabilityInterface $assetReturns;
    private ?OriginalWarehouseLocator $originalWarehouseLocator;
    private ?OrderAssetAllocationSnapshotService $assetSnapshots;
    /** @var (\Closure(class-string<Model>): Model)|null */
    private readonly ?\Closure $modelFactory;

    /** Unit harness isolation：禁止读写 var harness 文件。 */
    private bool $isolated = false;

    /**
     * @var array{
     *   orders: array<string, array<string, mixed>>,
     *   cases: array<string, array<string, mixed>>,
     *   payments: array<string, array<string, mixed>>,
     *   by_idem: array<string, string>,
     *   outbox: array<string, array<string, mixed>>,
     *   ledger: list<array<string, mixed>>,
     *   urgent: list<array<string, mixed>>,
     *   frozen_orders: array<string, true>
     * }
     */
    private array $memory;

    public function __construct(
        ?ObjectManager $objectManager = null,
        ?WriteIntentTransactionCoordinatorInterface $transactions = null,
        ?PaymentRefundFacadeInterface $paymentRefunds = null,
        ?InventoryRefundCapabilityInterface $inventoryRefunds = null,
        ?RefundAssetReturnCapabilityInterface $assetReturns = null,
        bool $useMemory = false,
        ?WarehouseInventoryCapabilityInterface $warehouseInventory = null,
        ?OriginalWarehouseLocator $originalWarehouseLocator = null,
        ?callable $modelFactory = null,
        ?OrderAssetAllocationSnapshotService $assetSnapshots = null,
    ) {
        $this->objectManager = $objectManager;
        $this->transactions = $transactions;
        $this->paymentRefunds = $paymentRefunds;
        $this->inventoryRefunds = $inventoryRefunds;
        $this->warehouseInventory = $warehouseInventory;
        $this->assetReturns = $assetReturns;
        $this->originalWarehouseLocator = $originalWarehouseLocator;
        $this->assetSnapshots = $assetSnapshots;
        $this->modelFactory = $modelFactory !== null
            ? \Closure::fromCallable($modelFactory)
            : null;
        $this->isolated = $useMemory;
        $this->memory = [
            'orders' => [],
            'cases' => [],
            'payments' => [],
            'by_idem' => [],
            'outbox' => [],
            'ledger' => [],
            'urgent' => [],
            'frozen_orders' => [],
        ];
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    private function hydrateFromHarness(): void
    {
        // The old cross-Worker var/ harness is deliberately retired.
    }

    private function persistToHarness(): void
    {
        // Unit memory is process-local; production state is always ORM-backed.
    }

    public function setNewRefundsEnabled(bool $enabled): void
    {
        $this->hydrateFromHarness();
        $this->newRefundsEnabled = $enabled;
        $this->persistToHarness();
    }

    /**
     * @param list<array{item_uuid:string,qty_minor:int,shipped?:bool}> $items
     */
    public function seedPaidOrder(
        string $orderUuid,
        int $capturedAmountMinor,
        array $items = [],
        int $shippingFrozenMinor = 0,
        string $currency = 'CNY',
    ): void {
        if (!$this->isolated) {
            throw new \LogicException('refund_test_harness_disabled');
        }
        $this->hydrateFromHarness();
        $this->memory['orders'][$orderUuid] = [
            'order_uuid' => $orderUuid,
            'captured_amount_minor' => $capturedAmountMinor,
            'currency' => $currency,
            'shipping_frozen_minor' => $shippingFrozenMinor,
            'shipping_refunded_minor' => 0,
            'items' => $items,
            'payment_status' => 'paid',
        ];
        $this->persistToHarness();
    }

    /**
     * @param list<array{item_uuid:string,qty_minor:int}> $requestItems
     * @return array{ok:bool,error_code:?string,case:?array,payment:?array}
     */
    public function requestRefund(
        string $orderUuid,
        string $idempotencyKey,
        int $clientHintAmountMinor = 0,
        array $requestItems = [],
        int $shippingRefundMinor = 0,
        string $reason = '',
    ): array {
        if (!$this->isolated) {
            return $this->requestRefundPersistent(
                $orderUuid,
                $idempotencyKey,
                $requestItems,
                $shippingRefundMinor,
                $reason,
            );
        }
        $this->hydrateFromHarness();
        if (!$this->acceptsNewRefunds()) {
            return $this->fail(self::ERROR_NEW_DISABLED);
        }
        if (isset($this->memory['frozen_orders'][$orderUuid])) {
            return $this->fail(self::ERROR_ORDER_FROZEN);
        }
        if (isset($this->memory['by_idem'][$idempotencyKey])) {
            $uuid = $this->memory['by_idem'][$idempotencyKey];

            return [
                'ok' => true,
                'error_code' => null,
                'case' => $this->memory['cases'][$uuid],
                'payment' => $this->memory['payments'][$uuid] ?? null,
                'replayed' => true,
            ];
        }

        $order = $this->memory['orders'][$orderUuid] ?? null;
        if ($order === null) {
            return $this->fail('refund_order_not_found');
        }

        // Server recompute：忽略客户端金额，按行数量与运费规则重算。
        $amountMinor = 0;
        $normalizedItems = [];
        foreach ($requestItems as $line) {
            $itemUuid = (string) ($line['item_uuid'] ?? '');
            $qty = (int) ($line['qty_minor'] ?? 0);
            $catalog = $this->findOrderItem($order, $itemUuid);
            if ($catalog === null || $qty <= 0) {
                return $this->fail(self::ERROR_QTY_EXCEEDS);
            }
            $remainingQty = (int) ($catalog['qty_minor'] ?? 0) - $this->occupiedQty($orderUuid, $itemUuid);
            if ($qty > $remainingQty) {
                return $this->fail(self::ERROR_QTY_EXCEEDS);
            }
            $unit = (int) ($catalog['unit_price_minor'] ?? 0);
            $lineAmount = $unit * $qty;
            // 已发货默认不回库；金额仍可退商品价，不自动退运费。
            $normalizedItems[] = [
                'item_uuid' => $itemUuid,
                'qty_minor' => $qty,
                'amount_minor' => $lineAmount,
                'shipped' => !empty($catalog['shipped']),
                'restock' => empty($catalog['shipped']),
            ];
            $amountMinor += $lineAmount;
        }

        if ($requestItems === [] && $clientHintAmountMinor > 0) {
            // Amount-only path（测试用）：服务端仍校验额度。
            $amountMinor = $clientHintAmountMinor;
        }

        $shipRefund = 0;
        if ($shippingRefundMinor > 0) {
            // 仅未发货且全部需配送取消时才允许自动运费；显式运费退款≤冻结未退。
            $allUnshippedCancelled = $this->allNeedShipCancelled($order, $normalizedItems);
            if (!$allUnshippedCancelled && $shippingRefundMinor > 0) {
                // 显式管理员运费：仍受冻结未退上限。
            }
            $remainingShip = (int) $order['shipping_frozen_minor'] - (int) $order['shipping_refunded_minor']
                - $this->occupiedShipping($orderUuid);
            if ($shippingRefundMinor > $remainingShip) {
                return $this->fail(self::ERROR_AMOUNT_EXCEEDS);
            }
            $shipRefund = $shippingRefundMinor;
            $amountMinor += $shipRefund;
        }

        $occupied = $this->occupiedAmount($orderUuid);
        $remaining = (int) $order['captured_amount_minor'] - $occupied;
        if ($amountMinor <= 0 || $amountMinor > $remaining) {
            return $this->fail(self::ERROR_AMOUNT_EXCEEDS);
        }

        $caseUuid = $this->uuid();
        $refundCode = 'prf_' . bin2hex(random_bytes(6));
        $case = [
            'refund_case_uuid' => $caseUuid,
            'order_uuid' => $orderUuid,
            'payment_refund_code' => $refundCode,
            'idempotency_key' => $idempotencyKey,
            'amount_minor' => $amountMinor,
            'currency' => (string) $order['currency'],
            'items' => $normalizedItems,
            'shipping_refund_minor' => $shipRefund,
            'status' => RefundCase::STATUS_SUBMITTED,
            'reason' => $reason,
            'steps' => [],
            'customer_view' => self::CUSTOMER_VIEW_PROCESSING,
        ];
        $payment = [
            'refund_code' => $refundCode,
            'refund_case_uuid' => $caseUuid,
            'order_uuid' => $orderUuid,
            'amount_minor' => $amountMinor,
            'currency' => (string) $order['currency'],
            'status' => self::PAYMENT_STATUS_PROCESSING,
            'channel_status' => self::PAYMENT_CHANNEL_SUBMITTED,
            'provider_refund_id' => null,
        ];
        $effectKey = 'refund:' . $caseUuid . ':provider:submit:v1';
        $outbox = [
            'effect_key' => $effectKey,
            'refund_case_uuid' => $caseUuid,
            'refund_code' => $refundCode,
            'status' => 'pending',
        ];

        $this->memory['cases'][$caseUuid] = $case;
        $this->memory['payments'][$caseUuid] = $payment;
        $this->memory['by_idem'][$idempotencyKey] = $caseUuid;
        $this->memory['outbox'][$effectKey] = $outbox;
        $this->persistToHarness();

        return [
            'ok' => true,
            'error_code' => null,
            'case' => $case,
            'payment' => $payment,
            'replayed' => false,
        ];
    }

    /**
     * Provider / query 回写渠道状态。
     *
     * @return array{ok:bool,error_code:?string,case:?array,payment:?array}
     */
    public function applyChannelResult(string $refundCaseUuid, string $channelStatus, ?string $providerRefundId = null): array
    {
        if (!$this->isolated) {
            return $this->applyChannelResultPersistent(
                $refundCaseUuid,
                $channelStatus,
                $providerRefundId,
            );
        }
        $this->hydrateFromHarness();
        $case = $this->memory['cases'][$refundCaseUuid] ?? null;
        $payment = $this->memory['payments'][$refundCaseUuid] ?? null;
        if ($case === null || $payment === null) {
            return $this->fail(self::ERROR_NOT_FOUND);
        }

        $channelStatus = strtolower($channelStatus);
        $prev = (string) ($payment['channel_status'] ?? '');

        if ($channelStatus === self::PAYMENT_CHANNEL_UNKNOWN
            || $channelStatus === 'timeout'
        ) {
            $payment['channel_status'] = self::PAYMENT_CHANNEL_UNKNOWN;
            $payment['status'] = self::PAYMENT_STATUS_UNKNOWN;
            $case['customer_view'] = self::CUSTOMER_VIEW_PROCESSING;
            $case['status'] = RefundCase::STATUS_SUBMITTED;
        } elseif ($channelStatus === self::PAYMENT_CHANNEL_PENDING
            || $channelStatus === 'accepted'
        ) {
            $payment['channel_status'] = self::PAYMENT_CHANNEL_PENDING;
            $payment['status'] = self::PAYMENT_STATUS_PENDING;
            $case['customer_view'] = self::CUSTOMER_VIEW_PROCESSING;
        } elseif ($channelStatus === self::PAYMENT_CHANNEL_SUCCEEDED) {
            if ($prev === self::PAYMENT_CHANNEL_FAILED
                || ($case['status'] ?? '') === RefundCase::STATUS_FAILED
            ) {
                // Late success after authoritative failed release.
                return $this->enterLateSuccessReview($refundCaseUuid, $providerRefundId);
            }
            $payment['channel_status'] = self::PAYMENT_CHANNEL_SUCCEEDED;
            $payment['status'] = self::PAYMENT_STATUS_REFUNDED;
            $payment['provider_refund_id'] = $providerRefundId;
            $case['status'] = RefundCase::STATUS_SUCCEEDED;
            $case['customer_view'] = self::CUSTOMER_VIEW_SUCCEEDED;
            $this->runPostCashSteps($case);
        } elseif ($channelStatus === self::PAYMENT_CHANNEL_FAILED) {
            $payment['channel_status'] = self::PAYMENT_CHANNEL_FAILED;
            $payment['status'] = self::PAYMENT_STATUS_FAILED;
            $case['status'] = RefundCase::STATUS_FAILED;
            $case['customer_view'] = self::CUSTOMER_VIEW_FAILED;
            // 权威 failed 释放占额（从占用集合移除靠 channel_status=failed）。
        } else {
            return $this->fail('refund_channel_status_invalid');
        }

        $this->memory['cases'][$refundCaseUuid] = $case;
        $this->memory['payments'][$refundCaseUuid] = $payment;
        $this->persistToHarness();

        return ['ok' => true, 'error_code' => null, 'case' => $case, 'payment' => $payment];
    }

    /**
     * @return array{ok:bool,error_code:?string,case:?array,payment:?array}
     */
    private function enterLateSuccessReview(string $refundCaseUuid, ?string $providerRefundId): array
    {
        $case = $this->memory['cases'][$refundCaseUuid];
        $payment = $this->memory['payments'][$refundCaseUuid];
        $orderUuid = (string) $case['order_uuid'];

        $payment['channel_status'] = self::PAYMENT_CHANNEL_SUCCEEDED;
        $payment['status'] = self::PAYMENT_STATUS_LATE_SUCCESS_REVIEW;
        $payment['provider_refund_id'] = $providerRefundId;
        $case['status'] = RefundCase::STATUS_LATE_SUCCESS_REVIEW;
        $case['customer_view'] = self::CUSTOMER_VIEW_PROCESSING;

        $this->memory['frozen_orders'][$orderUuid] = true;
        $this->memory['ledger'][] = [
            'type' => 'external_observed_late_success',
            'refund_case_uuid' => $refundCaseUuid,
            'order_uuid' => $orderUuid,
            'amount_minor' => (int) $payment['amount_minor'],
        ];
        $this->memory['urgent'][] = [
            'type' => 'refund_late_success_review',
            'refund_case_uuid' => $refundCaseUuid,
            'order_uuid' => $orderUuid,
        ];

        $this->memory['cases'][$refundCaseUuid] = $case;
        $this->memory['payments'][$refundCaseUuid] = $payment;
        $this->persistToHarness();

        return ['ok' => true, 'error_code' => null, 'case' => $case, 'payment' => $payment];
    }

    /**
     * @param array<string, mixed> $case
     */
    private function runPostCashSteps(array &$case): void
    {
        // 现金已 succeeded：后处理失败可重试，绝不回滚现金。
        foreach (['inventory:restock:v1', 'asset:return:v1', 'notification:refunded:v1'] as $step) {
            $key = 'refund:' . $case['refund_case_uuid'] . ':' . $step;
            if (isset($case['steps'][$key]) && ($case['steps'][$key]['status'] ?? '') === 'done') {
                continue;
            }
            $case['steps'][$key] = ['status' => 'done', 'at' => time()];
            $this->memory['outbox'][$key] = [
                'effect_key' => $key,
                'refund_case_uuid' => $case['refund_case_uuid'],
                'status' => 'done',
            ];
        }
    }

    public function retryPostCashStep(string $refundCaseUuid, string $stepSuffix): bool
    {
        if (!$this->isolated) {
            return $this->retryPostCashStepPersistent($refundCaseUuid, $stepSuffix);
        }
        $this->hydrateFromHarness();
        $case = $this->memory['cases'][$refundCaseUuid] ?? null;
        if ($case === null || ($case['status'] ?? '') !== RefundCase::STATUS_SUCCEEDED) {
            return false;
        }
        $key = 'refund:' . $refundCaseUuid . ':' . $stepSuffix;
        if (isset($case['steps'][$key]) && ($case['steps'][$key]['status'] ?? '') === 'done') {
            return false; // at most once
        }
        $case['steps'][$key] = ['status' => 'done', 'at' => time()];
        $this->memory['cases'][$refundCaseUuid] = $case;
        $this->memory['outbox'][$key] = [
            'effect_key' => $key,
            'refund_case_uuid' => $refundCaseUuid,
            'status' => 'done',
        ];
        $this->persistToHarness();

        return true;
    }

    public function occupiedAmount(string $orderUuid): int
    {
        if (!$this->isolated) {
            return $this->paymentRefunds()->getOccupiedAmountMinor('order', trim($orderUuid));
        }
        $this->hydrateFromHarness();
        $sum = 0;
        foreach ($this->memory['payments'] as $payment) {
            if (($payment['order_uuid'] ?? '') !== $orderUuid) {
                continue;
            }
            if (!$this->paymentOccupiesAmount($payment)) {
                continue;
            }
            $sum += (int) ($payment['amount_minor'] ?? 0);
        }

        return $sum;
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function paymentOccupiesAmount(array $payment): bool
    {
        $status = (string) ($payment['status'] ?? '');
        $channel = (string) ($payment['channel_status'] ?? '');
        if ($status === self::PAYMENT_STATUS_LATE_SUCCESS_REVIEW) {
            return true;
        }
        if ($channel === self::PAYMENT_CHANNEL_FAILED) {
            return false;
        }

        return \in_array($channel, [
            self::PAYMENT_CHANNEL_SUBMITTED,
            self::PAYMENT_CHANNEL_PENDING,
            self::PAYMENT_CHANNEL_UNKNOWN,
            self::PAYMENT_CHANNEL_SUCCEEDED,
        ], true);
    }

    public function remainingAmount(string $orderUuid): int
    {
        if (!$this->isolated) {
            return max(
                0,
                $this->paymentRefunds()->getCapturedAmountMinor('order', trim($orderUuid))
                    - $this->occupiedAmount($orderUuid),
            );
        }
        $this->hydrateFromHarness();
        $order = $this->memory['orders'][$orderUuid] ?? null;
        if ($order === null) {
            return 0;
        }

        return (int) $order['captured_amount_minor'] - $this->occupiedAmount($orderUuid);
    }

    public function customerView(string $refundCaseUuid): string
    {
        if (!$this->isolated) {
            $case = $this->getCase($refundCaseUuid);

            return (string)($case['customer_view'] ?? '');
        }
        $this->hydrateFromHarness();
        return (string) ($this->memory['cases'][$refundCaseUuid]['customer_view'] ?? '');
    }

    public function isOrderFrozen(string $orderUuid): bool
    {
        if (!$this->isolated) {
            $case = $this->newModel(RefundCase::class)
                ->where(RefundCase::schema_fields_ORDER_UUID, trim($orderUuid))
                ->where(RefundCase::schema_fields_STATUS, RefundCase::STATUS_LATE_SUCCESS_REVIEW)
                ->find()
                ->fetch();

            return $case instanceof RefundCase && (bool)$case->getId();
        }
        $this->hydrateFromHarness();
        return isset($this->memory['frozen_orders'][$orderUuid]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function urgentEvents(): array
    {
        if (!$this->isolated) {
            return $this->outboxRowsByOperation(RefundOutbox::OPERATION_URGENT_REVIEW);
        }
        $this->hydrateFromHarness();
        return $this->memory['urgent'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ledger(): array
    {
        if (!$this->isolated) {
            return [];
        }
        $this->hydrateFromHarness();
        return $this->memory['ledger'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCase(string $refundCaseUuid): ?array
    {
        if (!$this->isolated) {
            $case = $this->newModel(RefundCase::class)
                ->where(RefundCase::schema_fields_REFUND_CASE_UUID, trim($refundCaseUuid))
                ->find()
                ->fetch();
            if (!$case instanceof RefundCase || !$case->getId()) {
                return null;
            }

            return $this->caseToArray($case);
        }
        $this->hydrateFromHarness();
        return $this->memory['cases'][$refundCaseUuid] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayment(string $refundCaseUuid): ?array
    {
        if (!$this->isolated) {
            $result = $this->paymentRefunds()->findByRefundCaseUuid($refundCaseUuid);

            return $result?->getData();
        }
        $this->hydrateFromHarness();
        return $this->memory['payments'][$refundCaseUuid] ?? null;
    }

    public function setCrashBeforeSecondTransaction(bool $crash): void
    {
        $this->crashBeforeSecondTransaction = $crash;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function processPendingOutbox(int $limit = 20): array
    {
        if ($this->isolated) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $rows = $this->newModel(RefundOutbox::class)
            ->where(RefundOutbox::schema_fields_STATUS, [
                RefundOutbox::STATUS_PENDING,
                RefundOutbox::STATUS_PROCESSING,
            ], 'IN')
            ->order(RefundOutbox::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        $results = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            $code = trim((string)($row[RefundOutbox::schema_fields_OUTBOX_CODE] ?? ''));
            if ($code !== '') {
                $results[] = $this->processOneOutbox($code);
            }
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function processOneOutbox(string $outboxCode): array
    {
        if ($this->isolated) {
            return ['ok' => false, 'error_code' => 'refund_persistent_outbox_required'];
        }
        $claim = $this->claimOutbox($outboxCode);
        if (empty($claim['ok']) || !empty($claim['replayed'])) {
            return $claim;
        }
        $outbox = \is_array($claim['outbox'] ?? null) ? $claim['outbox'] : [];
        $operation = (string)($outbox['operation'] ?? '');
        $claimToken = (string)($claim['claim_token'] ?? '');

        if ($operation === RefundOutbox::OPERATION_PROVIDER_REFUND) {
            $providerResult = $this->paymentRefunds()->submitToProvider(
                (string)$outbox['refund_code'],
                (string)$outbox['provider_request_key'],
            );
            if ($this->crashBeforeSecondTransaction) {
                return [
                    'ok' => false,
                    'error_code' => 'refund_crash_before_second_transaction',
                    'outbox_code' => $outboxCode,
                    'provider_request_key' => $outbox['provider_request_key'],
                ];
            }

            return $this->completeProviderOutbox(
                $outboxCode,
                $claimToken,
                $providerResult,
            );
        }

        try {
            return $this->completeEffectOutbox($outboxCode, $claimToken);
        } catch (\Throwable $throwable) {
            $this->releaseOutboxForRetry($outboxCode, $claimToken, $throwable->getMessage());

            return [
                'ok' => false,
                'error_code' => $throwable->getMessage(),
                'outbox_code' => $outboxCode,
                'retryable' => true,
            ];
        }
    }

    /**
     * @param list<array{item_uuid:string,qty_minor:int}> $requestItems
     * @return array{ok:bool,error_code:?string,case:?array,payment:?array,replayed?:bool}
     */
    private function requestRefundPersistent(
        string $orderUuid,
        string $idempotencyKey,
        array $requestItems,
        int $shippingRefundMinor,
        string $reason,
    ): array {
        $orderUuid = trim($orderUuid);
        $idempotencyKey = trim($idempotencyKey);
        if (!$this->acceptsNewRefunds()) {
            return $this->fail(self::ERROR_NEW_DISABLED);
        }
        if ($orderUuid === '' || \strlen($orderUuid) > 36) {
            return $this->fail('refund_order_not_found');
        }
        if ($idempotencyKey === '' || \strlen($idempotencyKey) > 128) {
            return $this->fail('refund_idempotency_key_invalid');
        }
        if ($requestItems === []) {
            return $this->fail(self::ERROR_ITEMS_REQUIRED);
        }
        if ($shippingRefundMinor < 0) {
            return $this->fail(self::ERROR_AMOUNT_EXCEEDS);
        }
        $requestHash = $this->refundRequestHash(
            $orderUuid,
            $requestItems,
            $shippingRefundMinor,
            $reason,
        );
        $transactionModel = $this->newModel(Order::class);

        try {
            return $this->transactions()->runWrite(
                $transactionModel->getConnection(),
                function () use (
                    $orderUuid,
                    $idempotencyKey,
                    $requestHash,
                    $requestItems,
                    $shippingRefundMinor,
                    $reason,
                ): array {
                    $order = $this->loadOrderForUpdate($orderUuid);
                    if (!$order instanceof Order) {
                        return $this->fail('refund_order_not_found');
                    }
                    if ((string)$order->getData(Order::schema_fields_PAYMENT_STATUS)
                        !== Order::PAYMENT_STATUS_PAID
                    ) {
                        return $this->fail(self::ERROR_PAYMENT_NOT_CAPTURED);
                    }

                    $existing = $this->loadCaseByIdempotency(
                        $orderUuid,
                        $idempotencyKey,
                        true,
                    );
                    if ($existing instanceof RefundCase) {
                        if (!hash_equals(
                            (string)$existing->getData(RefundCase::schema_fields_REQUEST_HASH),
                            $requestHash,
                        )) {
                            return $this->fail(self::ERROR_IDEMPOTENCY_CONFLICT);
                        }

                        return [
                            'ok' => true,
                            'error_code' => null,
                            'case' => $this->caseToArray($existing),
                            'payment' => $this->paymentRefunds()
                                ->findByRefundCaseUuid(
                                    (string)$existing->getData(
                                        RefundCase::schema_fields_REFUND_CASE_UUID,
                                    ),
                                )?->getData(),
                            'replayed' => true,
                        ];
                    }
                    if ($this->loadLateReviewCase($orderUuid, true) instanceof RefundCase) {
                        return $this->fail(self::ERROR_ORDER_FROZEN);
                    }

                    $orderItems = $this->loadOrderItemsForUpdate($order);
                    $occupied = $this->persistentOccupiedFacts($orderUuid);
                    $normalized = $this->normalizePersistentRefundItems(
                        $order,
                        $orderItems,
                        $requestItems,
                        $occupied,
                    );
                    if (isset($normalized['error_code'])) {
                        return $this->fail((string)$normalized['error_code']);
                    }
                    $items = \is_array($normalized['items'] ?? null)
                        ? $normalized['items']
                        : [];
                    $amountMinor = (int)($normalized['amount_minor'] ?? 0);
                    $shippingFrozenMinor = $this->shippingFrozenMinor($order);
                    $remainingShipping = max(
                        0,
                        $shippingFrozenMinor - (int)($occupied['shipping_minor'] ?? 0),
                    );
                    if ($shippingRefundMinor > $remainingShipping) {
                        return $this->fail(self::ERROR_AMOUNT_EXCEEDS);
                    }
                    $amountMinor += $shippingRefundMinor;
                    if ($amountMinor <= 0) {
                        return $this->fail(self::ERROR_AMOUNT_EXCEEDS);
                    }

                    $refundCaseUuid = $this->uuid();
                    $currency = strtoupper(
                        (string)$order->getData(Order::schema_fields_CURRENCY),
                    );
                    $refundSplit = $this->assetSnapshots()->allocateRefund(
                        $orderUuid,
                        $this->orderTotalMinor($order),
                        $amountMinor,
                        (int)($occupied['amount_minor'] ?? 0),
                        \is_array($occupied['asset_allocations'] ?? null)
                            ? $occupied['asset_allocations']
                            : [],
                    );
                    $cashAmountMinor = (int)$refundSplit['cash_amount_minor'];
                    $assetAmountMinor = (int)$refundSplit['asset_amount_minor'];
                    $assetAllocations = \is_array(
                        $refundSplit['asset_allocations'] ?? null,
                    ) ? array_values($refundSplit['asset_allocations']) : [];
                    $payment = null;
                    if ($cashAmountMinor > 0) {
                        $payment = $this->paymentRefunds()->reserve(
                            RefundReserveCommand::create(
                                refundCaseUuid: $refundCaseUuid,
                                payableType: 'order',
                                payableId: $orderUuid,
                                idempotencyKey: $idempotencyKey,
                                requestHash: $requestHash,
                                amountMinor: $cashAmountMinor,
                                currencyCode: $currency,
                                reason: $reason,
                                context: [
                                    'website_id' => (int)$order->getData(
                                        Order::schema_fields_WEBSITE_ID,
                                    ),
                                    'store_id' => (int)$order->getData(
                                        Order::schema_fields_STORE_ID,
                                    ),
                                    'scope' => $this->decodeJson(
                                        $order->getData(
                                            Order::schema_fields_SCOPE_SNAPSHOT_JSON,
                                        ),
                                    ),
                                ],
                            ),
                        );
                        if (!$payment->isOk()) {
                            return $this->fail(
                                $this->mapPaymentError($payment->getErrorCode()),
                            );
                        }
                    }

                    $now = date('Y-m-d H:i:s');
                    $case = $this->newModel(RefundCase::class);
                    $case->setData([
                        RefundCase::schema_fields_REFUND_CASE_UUID => $refundCaseUuid,
                        RefundCase::schema_fields_ORDER_UUID => $orderUuid,
                        RefundCase::schema_fields_PAYMENT_REFUND_CODE =>
                            $payment?->getRefundCode(),
                        RefundCase::schema_fields_IDEMPOTENCY_KEY => $idempotencyKey,
                        RefundCase::schema_fields_REQUEST_HASH => $requestHash,
                        RefundCase::schema_fields_AMOUNT_MINOR => $amountMinor,
                        RefundCase::schema_fields_CASH_AMOUNT_MINOR => $cashAmountMinor,
                        RefundCase::schema_fields_ASSET_AMOUNT_MINOR => $assetAmountMinor,
                        RefundCase::schema_fields_ASSET_ALLOCATIONS_JSON =>
                            $this->json($assetAllocations),
                        RefundCase::schema_fields_CURRENCY => $currency,
                        RefundCase::schema_fields_ITEMS_JSON => $this->json($items),
                        RefundCase::schema_fields_SHIPPING_REFUND_MINOR => $shippingRefundMinor,
                        RefundCase::schema_fields_STATUS => $payment instanceof
                            RefundOperationResult
                                ? RefundCase::STATUS_SUBMITTED
                                : RefundCase::STATUS_SUCCEEDED,
                        RefundCase::schema_fields_CUSTOMER_VIEW => $payment instanceof
                            RefundOperationResult
                                ? self::CUSTOMER_VIEW_PROCESSING
                                : self::CUSTOMER_VIEW_SUCCEEDED,
                        RefundCase::schema_fields_VERSION => 0,
                        RefundCase::schema_fields_REASON => trim($reason),
                        RefundCase::schema_fields_STEPS_JSON => '{}',
                        RefundCase::schema_fields_CREATED_AT => $now,
                        RefundCase::schema_fields_UPDATED_AT => $now,
                    ])->save();

                    if ($payment instanceof RefundOperationResult) {
                        $providerOutbox = $this->createOutbox(
                            $refundCaseUuid,
                            (string)$payment->getRefundCode(),
                            RefundOutbox::OPERATION_PROVIDER_REFUND,
                            'refund:' . $refundCaseUuid . ':provider:refund:v1',
                            [
                                'order_uuid' => $orderUuid,
                                'amount_minor' => $cashAmountMinor,
                                'currency_code' => $currency,
                            ],
                            (string)$payment->getRefundCode() . ':refund:v1',
                        );
                        $this->createQueueForOutbox($providerOutbox);
                    } else {
                        $this->createPostCashOutboxes($case, null);
                        $case->setData(
                            RefundCase::schema_fields_UPDATED_AT,
                            date('Y-m-d H:i:s'),
                        )->save();
                    }

                    return [
                        'ok' => true,
                        'error_code' => null,
                        'case' => $this->caseToArray($case),
                        'payment' => $payment?->getData(),
                        'replayed' => false,
                    ];
                },
            );
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_error')) {
                w_log_error(
                    '[OrderRefundCoordinator] request transaction rolled back',
                    ['error_code' => self::ERROR_TRANSACTION],
                    'order',
                );
            }

            return [
                'ok' => false,
                'error_code' => self::ERROR_TRANSACTION,
                'case' => null,
                'payment' => null,
                'message' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok:bool,error_code:?string,case:?array,payment:?array}
     */
    private function applyChannelResultPersistent(
        string $refundCaseUuid,
        string $channelStatus,
        ?string $providerRefundId,
    ): array {
        $case = $this->loadCase($refundCaseUuid, false);
        $payment = $this->paymentRefunds()->findByRefundCaseUuid($refundCaseUuid);
        if (!$case instanceof RefundCase || !$payment instanceof RefundOperationResult) {
            return $this->fail(self::ERROR_NOT_FOUND);
        }
        $orderUuid = (string)$case->getData(RefundCase::schema_fields_ORDER_UUID);

        try {
            return $this->transactions()->runWrite(
                $case->getConnection(),
                function () use (
                    $refundCaseUuid,
                    $orderUuid,
                    $channelStatus,
                    $providerRefundId,
                    $payment,
                ): array {
                    if (!$this->loadOrderForUpdate($orderUuid) instanceof Order) {
                        return $this->fail('refund_order_not_found');
                    }
                    $lockedCase = $this->loadCase($refundCaseUuid, true);
                    if (!$lockedCase instanceof RefundCase) {
                        return $this->fail(self::ERROR_NOT_FOUND);
                    }
                    $applied = $this->paymentRefunds()->applyChannelResult(
                        (string)$payment->getRefundCode(),
                        $channelStatus,
                        $providerRefundId,
                    );
                    if (!$applied->isOk()) {
                        return $this->fail((string)$applied->getErrorCode());
                    }
                    $this->applyPaymentResultToCase($lockedCase, $applied);

                    return [
                        'ok' => true,
                        'error_code' => null,
                        'case' => $this->caseToArray($lockedCase),
                        'payment' => $applied->getData(),
                    ];
                },
            );
        } catch (\Throwable) {
            return $this->fail(self::ERROR_TRANSACTION);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function claimOutbox(string $outboxCode): array
    {
        $model = $this->newModel(RefundOutbox::class);

        return $this->transactions()->runWrite(
            $model->getConnection(),
            function () use ($outboxCode): array {
                $outbox = $this->loadOutbox($outboxCode, true);
                if (!$outbox instanceof RefundOutbox) {
                    return ['ok' => false, 'error_code' => 'refund_outbox_not_found'];
                }
                $status = (string)$outbox->getData(RefundOutbox::schema_fields_STATUS);
                if ($status === RefundOutbox::STATUS_DONE) {
                    return [
                        'ok' => true,
                        'replayed' => true,
                        'outbox_code' => $outboxCode,
                    ];
                }
                if ($status === RefundOutbox::STATUS_DEAD) {
                    return [
                        'ok' => false,
                        'error_code' => (string)(
                            $outbox->getData(RefundOutbox::schema_fields_ERROR_CODE)
                            ?: 'refund_outbox_dead'
                        ),
                    ];
                }
                $claimedAt = strtotime((string)$outbox->getData(
                    RefundOutbox::schema_fields_CLAIMED_AT,
                )) ?: 0;
                if ($status === RefundOutbox::STATUS_PROCESSING
                    && $claimedAt + RefundOutbox::CLAIM_LEASE_SECONDS > time()
                ) {
                    return [
                        'ok' => false,
                        'error_code' => 'refund_outbox_claim_in_progress',
                    ];
                }
                $token = bin2hex(random_bytes(32));
                $outbox->setData(RefundOutbox::schema_fields_STATUS, RefundOutbox::STATUS_PROCESSING)
                    ->setData(RefundOutbox::schema_fields_CLAIM_TOKEN, $token)
                    ->setData(RefundOutbox::schema_fields_CLAIMED_AT, date('Y-m-d H:i:s'))
                    ->setData(
                        RefundOutbox::schema_fields_ATTEMPT_COUNT,
                        (int)$outbox->getData(RefundOutbox::schema_fields_ATTEMPT_COUNT) + 1,
                    )
                    ->save();

                return [
                    'ok' => true,
                    'claim_token' => $token,
                    'outbox' => $this->outboxToArray($outbox),
                ];
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function completeProviderOutbox(
        string $outboxCode,
        string $claimToken,
        RefundOperationResult $providerResult,
    ): array {
        $snapshot = $this->loadOutbox($outboxCode, false);
        if (!$snapshot instanceof RefundOutbox) {
            return ['ok' => false, 'error_code' => 'refund_outbox_not_found'];
        }
        $refundCaseUuid = (string)$snapshot->getData(
            RefundOutbox::schema_fields_REFUND_CASE_UUID,
        );
        $case = $this->loadCase($refundCaseUuid, false);
        if (!$case instanceof RefundCase) {
            return ['ok' => false, 'error_code' => self::ERROR_NOT_FOUND];
        }
        $orderUuid = (string)$case->getData(RefundCase::schema_fields_ORDER_UUID);

        try {
            return $this->transactions()->runWrite(
                $snapshot->getConnection(),
                function () use (
                    $outboxCode,
                    $claimToken,
                    $refundCaseUuid,
                    $orderUuid,
                    $providerResult,
                ): array {
                    if (!$this->loadOrderForUpdate($orderUuid) instanceof Order) {
                        throw new \RuntimeException('refund_order_not_found');
                    }
                    $lockedCase = $this->loadCase($refundCaseUuid, true);
                    $outbox = $this->loadOutbox($outboxCode, true);
                    if (!$lockedCase instanceof RefundCase || !$outbox instanceof RefundOutbox) {
                        throw new \RuntimeException('refund_second_transaction_fact_missing');
                    }
                    if (!hash_equals(
                        $claimToken,
                        (string)$outbox->getData(RefundOutbox::schema_fields_CLAIM_TOKEN),
                    )) {
                        throw new \RuntimeException('refund_outbox_claim_conflict');
                    }
                    $applied = $this->paymentRefunds()->applyChannelResult(
                        (string)$outbox->getData(RefundOutbox::schema_fields_REFUND_CODE),
                        $providerResult->getChannelStatus() !== ''
                            ? $providerResult->getChannelStatus()
                            : self::PAYMENT_CHANNEL_UNKNOWN,
                        $providerResult->getProviderRefundId(),
                        $providerResult->getProviderResponse(),
                    );
                    if (!$applied->isOk()) {
                        throw new \RuntimeException(
                            (string)($applied->getErrorCode() ?? 'refund_payment_apply_failed'),
                        );
                    }
                    $this->applyPaymentResultToCase($lockedCase, $applied);
                    $requiresReconciliation = \in_array(
                        $applied->getChannelStatus(),
                        [
                            self::PAYMENT_CHANNEL_PENDING,
                            self::PAYMENT_CHANNEL_UNKNOWN,
                            self::PAYMENT_CHANNEL_SUBMITTED,
                            self::PAYMENT_CHANNEL_NOT_SUBMITTED,
                        ],
                        true,
                    );
                    $outbox->setData(
                        RefundOutbox::schema_fields_STATUS,
                        $requiresReconciliation
                            ? RefundOutbox::STATUS_PENDING
                            : RefundOutbox::STATUS_DONE,
                    )
                        ->setData(
                            RefundOutbox::schema_fields_RESULT_JSON,
                            $this->json($applied->getData()),
                        )
                        ->setData(
                            RefundOutbox::schema_fields_ERROR_CODE,
                            $requiresReconciliation
                                ? 'refund_channel_reconciliation_required'
                                : null,
                        )
                        ->setData(RefundOutbox::schema_fields_CLAIM_TOKEN, '')
                        ->setData(RefundOutbox::schema_fields_CLAIMED_AT, null)
                        ->setData(
                            RefundOutbox::schema_fields_PROCESSED_AT,
                            $requiresReconciliation ? null : date('Y-m-d H:i:s'),
                        )
                        ->save();

                    return [
                        'ok' => !$requiresReconciliation,
                        'error_code' => $requiresReconciliation
                            ? 'refund_channel_reconciliation_required'
                            : null,
                        'outbox_code' => $outboxCode,
                        'case' => $this->caseToArray($lockedCase),
                        'payment' => $applied->getData(),
                        'retryable' => $requiresReconciliation,
                    ];
                },
            );
        } catch (\Throwable $throwable) {
            $this->releaseOutboxForRetry($outboxCode, $claimToken, $throwable->getMessage());

            return [
                'ok' => false,
                'error_code' => $throwable->getMessage(),
                'outbox_code' => $outboxCode,
                'retryable' => true,
            ];
        }
    }

    private function applyPaymentResultToCase(
        RefundCase $case,
        RefundOperationResult $payment,
    ): void {
        $status = RefundCase::STATUS_SUBMITTED;
        $customerView = self::CUSTOMER_VIEW_PROCESSING;
        if ($payment->isLateSuccessReview()) {
            $status = RefundCase::STATUS_LATE_SUCCESS_REVIEW;
            $urgentOutbox = $this->createOutbox(
                (string)$case->getData(RefundCase::schema_fields_REFUND_CASE_UUID),
                (string)$payment->getRefundCode(),
                RefundOutbox::OPERATION_URGENT_REVIEW,
                'refund:' . $case->getData(RefundCase::schema_fields_REFUND_CASE_UUID)
                    . ':urgent:late-success:v1',
                [
                    'order_uuid' => (string)$case->getData(RefundCase::schema_fields_ORDER_UUID),
                    'amount_minor' => $payment->getAmountMinor(),
                ],
            );
            $this->createQueueForOutbox($urgentOutbox);
        } elseif ($payment->getChannelStatus() === self::PAYMENT_CHANNEL_SUCCEEDED) {
            $status = RefundCase::STATUS_SUCCEEDED;
            $customerView = self::CUSTOMER_VIEW_SUCCEEDED;
            $this->createPostCashOutboxes($case, $payment);
        } elseif ($payment->getChannelStatus() === self::PAYMENT_CHANNEL_FAILED) {
            $status = RefundCase::STATUS_FAILED;
            $customerView = self::CUSTOMER_VIEW_FAILED;
        }
        $case->setData(RefundCase::schema_fields_STATUS, $status)
            ->setData(RefundCase::schema_fields_CUSTOMER_VIEW, $customerView)
            ->setData(
                RefundCase::schema_fields_VERSION,
                (int)$case->getData(RefundCase::schema_fields_VERSION) + 1,
            )
            ->setData(RefundCase::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    private function createPostCashOutboxes(
        RefundCase $case,
        ?RefundOperationResult $payment,
    ): void {
        $caseUuid = (string)$case->getData(RefundCase::schema_fields_REFUND_CASE_UUID);
        $refundCode = $payment instanceof RefundOperationResult
            ? (string)$payment->getRefundCode()
            : 'asset_only:' . $caseUuid;
        $items = $this->decodeJson($case->getData(RefundCase::schema_fields_ITEMS_JSON));
        $assetAllocations = $this->decodeJson(
            $case->getData(RefundCase::schema_fields_ASSET_ALLOCATIONS_JSON),
        );
        $restock = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => \is_array($item) && !empty($item['restock']),
        ));
        $created = [];
        if ($restock !== []) {
            $created[] = $this->createOutbox(
                $caseUuid,
                $refundCode,
                RefundOutbox::OPERATION_INVENTORY_RESTOCK,
                'refund:' . $caseUuid . ':inventory:restock:v1',
                ['items' => $restock],
            );
        }
        if ($assetAllocations !== []) {
            $created[] = $this->createOutbox(
                $caseUuid,
                $refundCode,
                RefundOutbox::OPERATION_ASSET_RETURN,
                'refund:' . $caseUuid . ':asset:return:v1',
                ['allocations' => array_values($assetAllocations)],
            );
        }
        $created[] = $this->createOutbox(
            $caseUuid,
            $refundCode,
            RefundOutbox::OPERATION_NOTIFY_REFUNDED,
            'refund:' . $caseUuid . ':notification:refunded:v1',
            [
                'order_uuid' => (string)$case->getData(RefundCase::schema_fields_ORDER_UUID),
                'amount_minor' => (int)$case->getData(
                    RefundCase::schema_fields_AMOUNT_MINOR,
                ),
                'currency_code' => (string)$case->getData(
                    RefundCase::schema_fields_CURRENCY,
                ),
            ],
        );
        $steps = $this->decodeJson($case->getData(RefundCase::schema_fields_STEPS_JSON));
        foreach ($created as $outbox) {
            $key = (string)$outbox->getData(RefundOutbox::schema_fields_EFFECT_KEY);
            $steps[$key] = ['status' => RefundOutbox::STATUS_PENDING];
            $this->createQueueForOutbox($outbox);
        }
        $case->setData(RefundCase::schema_fields_STEPS_JSON, $this->json($steps));
    }

    /**
     * @return array<string, mixed>
     */
    private function completeEffectOutbox(string $outboxCode, string $claimToken): array
    {
        $model = $this->newModel(RefundOutbox::class);

        return $this->transactions()->runWrite(
            $model->getConnection(),
            function () use ($outboxCode, $claimToken): array {
                $outbox = $this->loadOutbox($outboxCode, true);
                if (!$outbox instanceof RefundOutbox) {
                    throw new \RuntimeException('refund_outbox_not_found');
                }
                if (!hash_equals(
                    $claimToken,
                    (string)$outbox->getData(RefundOutbox::schema_fields_CLAIM_TOKEN),
                )) {
                    throw new \RuntimeException('refund_outbox_claim_conflict');
                }
                $payload = $this->decodeJson(
                    $outbox->getData(RefundOutbox::schema_fields_PAYLOAD_JSON),
                );
                $operation = (string)$outbox->getData(RefundOutbox::schema_fields_OPERATION);
                $effectKey = (string)$outbox->getData(RefundOutbox::schema_fields_EFFECT_KEY);
                $result = [];

                if ($operation === RefundOutbox::OPERATION_INVENTORY_RESTOCK) {
                    foreach (\is_array($payload['items'] ?? null) ? $payload['items'] : [] as $item) {
                        if (!\is_array($item) || empty($item['restock'])) {
                            continue;
                        }
                        $offerId = (int)($item['offer_id'] ?? 0);
                        $qty = (int)($item['qty_minor'] ?? 0);
                        if ($offerId <= 0 || $qty <= 0) {
                            continue;
                        }
                        $itemKey = $effectKey . ':' . (string)($item['item_uuid'] ?? $offerId);
                        $warehouseId = (int)($item['warehouse_id'] ?? 0);
                        $warehouseSource = (string)($item['warehouse_source'] ?? '');
                        if ($warehouseId > 0
                            && $warehouseSource === WarehouseFulfillmentService::SOURCE_WAREHOUSE
                        ) {
                            $this->warehouseInventory()->returnCommittedToWarehouse(
                                (int)($item['website_id'] ?? 0),
                                (int)($item['store_id'] ?? 0),
                                $warehouseId,
                                $offerId,
                                $qty,
                                substr($itemKey, 0, 128),
                                hash('sha256', $itemKey . '|' . $warehouseId . '|' . $qty),
                            );
                        } else {
                            $this->inventoryRefunds()->returnCommitted(
                                (int)($item['website_id'] ?? 0),
                                (int)($item['store_id'] ?? 0),
                                $offerId,
                                $qty,
                                substr($itemKey, 0, 128),
                                hash('sha256', $itemKey . '|' . $qty),
                            );
                        }
                    }
                    $result = ['restocked' => true];
                } elseif ($operation === RefundOutbox::OPERATION_ASSET_RETURN) {
                    $allocations = \is_array($payload['allocations'] ?? null)
                        ? $payload['allocations']
                        : [];
                    if ($allocations === []) {
                        $result = ['not_applicable' => true];
                    } elseif (!$this->assetReturns instanceof RefundAssetReturnCapabilityInterface) {
                        throw new \RuntimeException('refund_asset_return_capability_required');
                    } else {
                        $result = $this->assetReturns->returnCommittedAllocations(
                            (string)$outbox->getData(
                                RefundOutbox::schema_fields_REFUND_CASE_UUID,
                            ),
                            array_values($allocations),
                            $effectKey,
                        );
                        if (empty($result['ok'])) {
                            throw new \RuntimeException((string)(
                                $result['error_code'] ?? 'refund_asset_return_failed'
                            ));
                        }
                    }
                } elseif ($operation === RefundOutbox::OPERATION_NOTIFY_REFUNDED) {
                    \w_msg(
                        'payment_refund',
                        'success',
                        (string)__('退款已到账'),
                        (string)__('订单 %{1} 的退款已到账', [
                            (string)($payload['order_uuid'] ?? ''),
                        ]),
                        [
                            'dedupe_key' => $effectKey,
                            'source_module' => 'Weline_Order',
                            'metadata' => [
                                'refund_case_uuid' => (string)$outbox->getData(
                                    RefundOutbox::schema_fields_REFUND_CASE_UUID,
                                ),
                            ],
                        ],
                    );
                    $result = ['notified' => true];
                } elseif ($operation === RefundOutbox::OPERATION_URGENT_REVIEW) {
                    \w_msg(
                        'refund_late_success_review',
                        'urgent',
                        (string)__('退款迟到成功需要人工对账'),
                        (string)__('订单 %{1} 的失败退款出现迟到成功，已冻结新退款', [
                            (string)($payload['order_uuid'] ?? ''),
                        ]),
                        [
                            'priority' => 10,
                            'dedupe_key' => $effectKey,
                            'source_module' => 'Weline_Order',
                            'metadata' => [
                                'refund_case_uuid' => (string)$outbox->getData(
                                    RefundOutbox::schema_fields_REFUND_CASE_UUID,
                                ),
                                'amount_minor' => (int)($payload['amount_minor'] ?? 0),
                            ],
                        ],
                    );
                    $result = ['urgent_notified' => true];
                } else {
                    throw new \RuntimeException('refund_outbox_operation_invalid');
                }

                $outbox->setData(RefundOutbox::schema_fields_STATUS, RefundOutbox::STATUS_DONE)
                    ->setData(RefundOutbox::schema_fields_RESULT_JSON, $this->json($result))
                    ->setData(RefundOutbox::schema_fields_ERROR_CODE, null)
                    ->setData(RefundOutbox::schema_fields_PROCESSED_AT, date('Y-m-d H:i:s'))
                    ->save();
                $this->markCaseStepDone(
                    (string)$outbox->getData(RefundOutbox::schema_fields_REFUND_CASE_UUID),
                    $effectKey,
                );

                return [
                    'ok' => true,
                    'outbox_code' => $outboxCode,
                    'operation' => $operation,
                    'result' => $result,
                ];
            },
        );
    }

    private function retryPostCashStepPersistent(
        string $refundCaseUuid,
        string $stepSuffix,
    ): bool {
        $effectKey = 'refund:' . trim($refundCaseUuid) . ':' . trim($stepSuffix);
        $outbox = $this->newModel(RefundOutbox::class)
            ->where(RefundOutbox::schema_fields_EFFECT_KEY, $effectKey)
            ->find()
            ->fetch();
        if (!$outbox instanceof RefundOutbox || !$outbox->getId()) {
            return false;
        }
        if ((string)$outbox->getData(RefundOutbox::schema_fields_STATUS)
            === RefundOutbox::STATUS_DONE
        ) {
            return false;
        }
        $result = $this->processOneOutbox(
            (string)$outbox->getData(RefundOutbox::schema_fields_OUTBOX_CODE),
        );

        return !empty($result['ok']);
    }

    private function releaseOutboxForRetry(
        string $outboxCode,
        string $claimToken,
        string $errorCode,
    ): void {
        $model = $this->newModel(RefundOutbox::class);
        try {
            $this->transactions()->runWrite(
                $model->getConnection(),
                function () use ($outboxCode, $claimToken, $errorCode): void {
                    $outbox = $this->loadOutbox($outboxCode, true);
                    if (!$outbox instanceof RefundOutbox
                        || !hash_equals(
                            $claimToken,
                            (string)$outbox->getData(RefundOutbox::schema_fields_CLAIM_TOKEN),
                        )
                    ) {
                        return;
                    }
                    $outbox->setData(RefundOutbox::schema_fields_STATUS, RefundOutbox::STATUS_PENDING)
                        ->setData(
                            RefundOutbox::schema_fields_ERROR_CODE,
                            substr($errorCode, 0, 96),
                        )
                        ->setData(RefundOutbox::schema_fields_CLAIM_TOKEN, '')
                        ->setData(RefundOutbox::schema_fields_CLAIMED_AT, null)
                        ->save();
                },
            );
        } catch (\Throwable) {
        }
    }

    private function markCaseStepDone(string $refundCaseUuid, string $effectKey): void
    {
        $case = $this->loadCase($refundCaseUuid, true);
        if (!$case instanceof RefundCase) {
            return;
        }
        $steps = $this->decodeJson($case->getData(RefundCase::schema_fields_STEPS_JSON));
        $steps[$effectKey] = [
            'status' => RefundOutbox::STATUS_DONE,
            'at' => date('Y-m-d H:i:s'),
        ];
        $case->setData(RefundCase::schema_fields_STEPS_JSON, $this->json($steps))
            ->setData(RefundCase::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    private function createOutbox(
        string $refundCaseUuid,
        string $refundCode,
        string $operation,
        string $effectKey,
        array $payload,
        ?string $providerRequestKey = null,
    ): RefundOutbox {
        $existing = $this->newModel(RefundOutbox::class)
            ->where(RefundOutbox::schema_fields_EFFECT_KEY, $effectKey)
            ->find()
            ->fetch();
        if ($existing instanceof RefundOutbox && $existing->getId()) {
            return $existing;
        }
        $outbox = $this->newModel(RefundOutbox::class);
        $outbox->setData([
            RefundOutbox::schema_fields_OUTBOX_CODE => 'rof_'
                . substr(hash('sha256', $effectKey), 0, 48),
            RefundOutbox::schema_fields_EFFECT_KEY => $effectKey,
            RefundOutbox::schema_fields_REFUND_CASE_UUID => $refundCaseUuid,
            RefundOutbox::schema_fields_REFUND_CODE => $refundCode,
            RefundOutbox::schema_fields_OPERATION => $operation,
            RefundOutbox::schema_fields_PROVIDER_REQUEST_KEY => $providerRequestKey,
            RefundOutbox::schema_fields_STATUS => RefundOutbox::STATUS_PENDING,
            RefundOutbox::schema_fields_PAYLOAD_JSON => $this->json($payload),
            RefundOutbox::schema_fields_ATTEMPT_COUNT => 0,
            RefundOutbox::schema_fields_CLAIM_TOKEN => '',
            RefundOutbox::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        return $outbox;
    }

    private function createQueueForOutbox(RefundOutbox $outbox): void
    {
        \w_query('queue', 'createIfAbsent', [
            'class' => \Weline\Order\Queue\OrderRefundOutboxConsumer::class,
            'name' => (string)__('订单退款 Outbox %{1}', [
                (string)$outbox->getData(RefundOutbox::schema_fields_OUTBOX_CODE),
            ]),
            'module' => 'Weline_Order',
            'content' => [
                'outbox_code' => (string)$outbox->getData(
                    RefundOutbox::schema_fields_OUTBOX_CODE,
                ),
            ],
            'biz_key' => (string)$outbox->getData(RefundOutbox::schema_fields_EFFECT_KEY),
            'idempotency_scope' => 'order_refund_outbox',
            'idempotency_key' => (string)$outbox->getData(
                RefundOutbox::schema_fields_EFFECT_KEY,
            ),
            'auto' => true,
            'dispatch' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $occupied
     * @param list<array{item_uuid:string,qty_minor:int}> $requestItems
     * @return array{items?:list<array<string,mixed>>,amount_minor?:int,error_code?:string}
     */
    private function normalizePersistentRefundItems(
        Order $order,
        array $orderItems,
        array $requestItems,
        array $occupied,
    ): array {
        $byUuid = [];
        foreach ($orderItems as $item) {
            if ($item instanceof OrderItem) {
                $byUuid[(string)$item->getData(OrderItem::schema_fields_ITEM_UUID)] = $item;
            }
        }
        $seen = [];
        $normalized = [];
        $amountMinor = 0;
        foreach ($requestItems as $request) {
            $itemUuid = trim((string)($request['item_uuid'] ?? ''));
            $qty = (int)($request['qty_minor'] ?? 0);
            if ($itemUuid === '' || $qty <= 0 || isset($seen[$itemUuid])) {
                return ['error_code' => self::ERROR_QTY_EXCEEDS];
            }
            $seen[$itemUuid] = true;
            $item = $byUuid[$itemUuid] ?? null;
            if (!$item instanceof OrderItem) {
                return ['error_code' => self::ERROR_QTY_EXCEEDS];
            }
            $orderedQty = (int)$item->getData(OrderItem::schema_fields_QTY_MINOR);
            if ($orderedQty <= 0) {
                $orderedQty = (int)$item->getData(OrderItem::schema_fields_QTY_ORDERED);
            }
            $occupiedQty = (int)($occupied['items'][$itemUuid]['qty_minor'] ?? 0);
            $remainingQty = $orderedQty - $occupiedQty;
            if ($qty > $remainingQty) {
                return ['error_code' => self::ERROR_QTY_EXCEEDS];
            }
            $snapshot = $this->decodeJson(
                $item->getData(OrderItem::schema_fields_CATALOG_LINE_SNAPSHOT_JSON),
            );
            $frozenLineMinor = (int)($snapshot['row_total_minor'] ?? 0);
            if ($frozenLineMinor <= 0) {
                $unitMinor = (int)$item->getData(OrderItem::schema_fields_UNIT_PRICE_MINOR);
                $frozenLineMinor = $unitMinor * $orderedQty;
            }
            $occupiedLineMinor = (int)($occupied['items'][$itemUuid]['amount_minor'] ?? 0);
            $remainingLineMinor = max(0, $frozenLineMinor - $occupiedLineMinor);
            $lineAmount = $qty === $remainingQty
                ? $remainingLineMinor
                : min(
                    $remainingLineMinor,
                    intdiv($frozenLineMinor * $qty, max(1, $orderedQty)),
                );
            $shippedQty = (int)$item->getData(OrderItem::schema_fields_QTY_SHIPPED);
            $offerId = (int)$item->getData(OrderItem::schema_fields_OFFER_ID);
            $restock = $shippedQty <= 0 && $offerId > 0;
            $warehouse = $restock
                ? $this->originalWarehouseLocator()->forOffer(
                    (string)$order->getData(Order::schema_fields_ORDER_UUID),
                    $offerId,
                )
                : null;
            $normalizedItem = [
                'item_uuid' => $itemUuid,
                'offer_id' => $offerId,
                'qty_minor' => $qty,
                'amount_minor' => $lineAmount,
                'shipped' => $shippedQty > 0,
                'restock' => $restock,
                'website_id' => (int)$order->getData(Order::schema_fields_WEBSITE_ID),
                'store_id' => (int)$order->getData(Order::schema_fields_STORE_ID),
            ];
            if ($warehouse !== null) {
                $normalizedItem['warehouse_id'] = $warehouse['warehouse_id'];
                $normalizedItem['warehouse_source'] = $warehouse['warehouse_source'];
            }
            $normalized[] = $normalizedItem;
            $amountMinor += $lineAmount;
        }

        return ['items' => $normalized, 'amount_minor' => $amountMinor];
    }

    /**
     * @return array{
     *   items:array<string,array{qty_minor:int,amount_minor:int}>,
     *   shipping_minor:int,
     *   amount_minor:int,
     *   asset_allocations:list<array<string,mixed>>
     * }
     */
    private function persistentOccupiedFacts(string $orderUuid): array
    {
        $facts = [
            'items' => [],
            'shipping_minor' => 0,
            'amount_minor' => 0,
            'asset_allocations' => [],
        ];
        $rows = $this->newModel(RefundCase::class)
            ->where(RefundCase::schema_fields_ORDER_UUID, $orderUuid)
            ->order(RefundCase::schema_fields_ID, 'ASC')
            ->select()
            ->fetch();
        foreach ($this->modelItems($rows, RefundCase::class) as $case) {
            $cashAmountMinor = (int)$case->getData(
                RefundCase::schema_fields_CASH_AMOUNT_MINOR,
            );
            if ($cashAmountMinor === 0
                && (int)$case->getData(RefundCase::schema_fields_ASSET_AMOUNT_MINOR) === 0
                && trim((string)$case->getData(
                    RefundCase::schema_fields_PAYMENT_REFUND_CODE,
                )) !== ''
            ) {
                // Additive-schema compatibility for pre-allocation cash cases.
                $cashAmountMinor = (int)$case->getData(
                    RefundCase::schema_fields_AMOUNT_MINOR,
                );
            }
            if ($cashAmountMinor > 0) {
                $payment = $this->paymentRefunds()->findByRefundCaseUuid(
                    (string)$case->getData(RefundCase::schema_fields_REFUND_CASE_UUID),
                );
                if (!$payment instanceof RefundOperationResult
                    || ($payment->getChannelStatus() === self::PAYMENT_CHANNEL_FAILED
                        && !$payment->isLateSuccessReview())
                ) {
                    continue;
                }
            } elseif (\in_array(
                (string)$case->getData(RefundCase::schema_fields_STATUS),
                [RefundCase::STATUS_FAILED, RefundCase::STATUS_CANCELLED],
                true,
            )) {
                continue;
            }
            foreach ($this->decodeJson(
                $case->getData(RefundCase::schema_fields_ITEMS_JSON),
            ) as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $uuid = (string)($item['item_uuid'] ?? '');
                if ($uuid === '') {
                    continue;
                }
                $facts['items'][$uuid]['qty_minor'] = (int)(
                    $facts['items'][$uuid]['qty_minor'] ?? 0
                ) + (int)($item['qty_minor'] ?? 0);
                $facts['items'][$uuid]['amount_minor'] = (int)(
                    $facts['items'][$uuid]['amount_minor'] ?? 0
                ) + (int)($item['amount_minor'] ?? 0);
            }
            $facts['shipping_minor'] += (int)$case->getData(
                RefundCase::schema_fields_SHIPPING_REFUND_MINOR,
            );
            $facts['amount_minor'] += (int)$case->getData(
                RefundCase::schema_fields_AMOUNT_MINOR,
            );
            foreach ($this->decodeJson(
                $case->getData(RefundCase::schema_fields_ASSET_ALLOCATIONS_JSON),
            ) as $allocation) {
                if (\is_array($allocation)) {
                    $facts['asset_allocations'][] = $allocation;
                }
            }
        }

        return $facts;
    }

    private function loadOrderForUpdate(string $orderUuid): ?Order
    {
        $model = $this->newModel(Order::class)
            ->where(Order::schema_fields_ORDER_UUID, trim($orderUuid));
        if (!$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }
        $order = $model->find()->fetch();

        return $order instanceof Order && $order->getId() ? $order : null;
    }

    /**
     * @return OrderItem[]
     */
    private function loadOrderItemsForUpdate(Order $order): array
    {
        $model = $this->newModel(OrderItem::class)
            ->where(OrderItem::schema_fields_ORDER_ID, (int)$order->getId())
            ->order(OrderItem::schema_fields_ID, 'ASC');
        if (!$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }

        return $this->modelItems($model->select()->fetch(), OrderItem::class);
    }

    private function loadCase(string $refundCaseUuid, bool $forUpdate): ?RefundCase
    {
        $model = $this->newModel(RefundCase::class)
            ->where(RefundCase::schema_fields_REFUND_CASE_UUID, trim($refundCaseUuid));
        if ($forUpdate && !$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }
        $case = $model->find()->fetch();

        return $case instanceof RefundCase && $case->getId() ? $case : null;
    }

    private function loadCaseByIdempotency(
        string $orderUuid,
        string $idempotencyKey,
        bool $forUpdate,
    ): ?RefundCase {
        $model = $this->newModel(RefundCase::class)
            ->where(RefundCase::schema_fields_ORDER_UUID, $orderUuid)
            ->where(RefundCase::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey);
        if ($forUpdate && !$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }
        $case = $model->find()->fetch();

        return $case instanceof RefundCase && $case->getId() ? $case : null;
    }

    private function loadLateReviewCase(string $orderUuid, bool $forUpdate): ?RefundCase
    {
        $model = $this->newModel(RefundCase::class)
            ->where(RefundCase::schema_fields_ORDER_UUID, $orderUuid)
            ->where(RefundCase::schema_fields_STATUS, RefundCase::STATUS_LATE_SUCCESS_REVIEW);
        if ($forUpdate && !$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }
        $case = $model->find()->fetch();

        return $case instanceof RefundCase && $case->getId() ? $case : null;
    }

    private function loadOutbox(string $outboxCode, bool $forUpdate): ?RefundOutbox
    {
        $model = $this->newModel(RefundOutbox::class)
            ->where(RefundOutbox::schema_fields_OUTBOX_CODE, trim($outboxCode));
        if ($forUpdate && !$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }
        $outbox = $model->find()->fetch();

        return $outbox instanceof RefundOutbox && $outbox->getId() ? $outbox : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function caseToArray(RefundCase $case): array
    {
        return [
            'refund_case_uuid' => (string)$case->getData(
                RefundCase::schema_fields_REFUND_CASE_UUID,
            ),
            'order_uuid' => (string)$case->getData(RefundCase::schema_fields_ORDER_UUID),
            'payment_refund_code' => (string)$case->getData(
                RefundCase::schema_fields_PAYMENT_REFUND_CODE,
            ),
            'idempotency_key' => (string)$case->getData(
                RefundCase::schema_fields_IDEMPOTENCY_KEY,
            ),
            'amount_minor' => (int)$case->getData(RefundCase::schema_fields_AMOUNT_MINOR),
            'cash_amount_minor' => (int)$case->getData(
                RefundCase::schema_fields_CASH_AMOUNT_MINOR,
            ),
            'asset_amount_minor' => (int)$case->getData(
                RefundCase::schema_fields_ASSET_AMOUNT_MINOR,
            ),
            'asset_allocations' => $this->decodeJson(
                $case->getData(RefundCase::schema_fields_ASSET_ALLOCATIONS_JSON),
            ),
            'currency' => (string)$case->getData(RefundCase::schema_fields_CURRENCY),
            'items' => $this->decodeJson($case->getData(RefundCase::schema_fields_ITEMS_JSON)),
            'shipping_refund_minor' => (int)$case->getData(
                RefundCase::schema_fields_SHIPPING_REFUND_MINOR,
            ),
            'status' => (string)$case->getData(RefundCase::schema_fields_STATUS),
            'customer_view' => (string)$case->getData(
                RefundCase::schema_fields_CUSTOMER_VIEW,
            ),
            'steps' => $this->decodeJson($case->getData(RefundCase::schema_fields_STEPS_JSON)),
            'reason' => (string)$case->getData(RefundCase::schema_fields_REASON),
            'version' => (int)$case->getData(RefundCase::schema_fields_VERSION),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outboxToArray(RefundOutbox $outbox): array
    {
        return [
            'outbox_code' => (string)$outbox->getData(RefundOutbox::schema_fields_OUTBOX_CODE),
            'effect_key' => (string)$outbox->getData(RefundOutbox::schema_fields_EFFECT_KEY),
            'refund_case_uuid' => (string)$outbox->getData(
                RefundOutbox::schema_fields_REFUND_CASE_UUID,
            ),
            'refund_code' => (string)$outbox->getData(RefundOutbox::schema_fields_REFUND_CODE),
            'operation' => (string)$outbox->getData(RefundOutbox::schema_fields_OPERATION),
            'provider_request_key' => (string)$outbox->getData(
                RefundOutbox::schema_fields_PROVIDER_REQUEST_KEY,
            ),
            'status' => (string)$outbox->getData(RefundOutbox::schema_fields_STATUS),
            'payload' => $this->decodeJson($outbox->getData(
                RefundOutbox::schema_fields_PAYLOAD_JSON,
            )),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function outboxRowsByOperation(string $operation): array
    {
        $rows = $this->newModel(RefundOutbox::class)
            ->where(RefundOutbox::schema_fields_OPERATION, $operation)
            ->order(RefundOutbox::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return \is_array($rows) ? array_values($rows) : [];
    }

    private function shippingFrozenMinor(Order $order): int
    {
        $money = $this->decodeJson($order->getData(Order::schema_fields_MONEY_SNAPSHOT_JSON));
        if (isset($money['shipping_amount_minor'])) {
            return max(0, (int)$money['shipping_amount_minor']);
        }

        return max(
            0,
            (int)round((float)$order->getData(Order::schema_fields_SHIPPING_AMOUNT) * 100),
        );
    }

    private function orderTotalMinor(Order $order): int
    {
        $money = $this->decodeJson(
            $order->getData(Order::schema_fields_MONEY_SNAPSHOT_JSON),
        );
        $total = isset($money['grand_total_minor'])
            ? (int)$money['grand_total_minor']
            : (int)round(
                (float)$order->getData(Order::schema_fields_GRAND_TOTAL) * 100,
            );
        if ($total <= 0) {
            throw new \LogicException('order_refund_frozen_total_invalid');
        }

        return $total;
    }

    private function acceptsNewRefunds(): bool
    {
        if (!$this->newRefundsEnabled) {
            return false;
        }
        $runtimeGate = getenv('WELINE_ORDER_NEW_REFUNDS_ENABLED');
        if ($runtimeGate === false || trim($runtimeGate) === '') {
            return true;
        }

        return !\in_array(
            strtolower(trim($runtimeGate)),
            ['0', 'false', 'off', 'no', 'disabled'],
            true,
        );
    }

    /**
     * @param list<array{item_uuid:string,qty_minor:int}> $items
     */
    private function refundRequestHash(
        string $orderUuid,
        array $items,
        int $shippingRefundMinor,
        string $reason,
    ): string {
        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = [
                'item_uuid' => trim((string)($item['item_uuid'] ?? '')),
                'qty_minor' => (int)($item['qty_minor'] ?? 0),
            ];
        }
        usort(
            $normalized,
            static fn (array $a, array $b): int => strcmp($a['item_uuid'], $b['item_uuid']),
        );

        return hash('sha256', $this->json([
            'order_uuid' => $orderUuid,
            'items' => $normalized,
            'shipping_refund_minor' => $shippingRefundMinor,
            'reason' => trim($reason),
        ]));
    }

    private function mapPaymentError(?string $errorCode): string
    {
        return match ($errorCode) {
            'payment_refund_amount_exceeds_remaining_amount' => self::ERROR_AMOUNT_EXCEEDS,
            'payment_refund_payment_not_captured' => self::ERROR_PAYMENT_NOT_CAPTURED,
            'payment_refund_idempotency_conflict' => self::ERROR_IDEMPOTENCY_CONFLICT,
            null, '' => self::ERROR_TRANSACTION,
            default => $errorCode,
        };
    }

    private function paymentRefunds(): PaymentRefundFacadeInterface
    {
        return $this->paymentRefunds ??= $this->objectManager()->get(
            PaymentRefundFacadeInterface::class,
        );
    }

    private function inventoryRefunds(): InventoryRefundCapabilityInterface
    {
        return $this->inventoryRefunds ??= $this->objectManager()->get(
            InventoryRefundCapabilityInterface::class,
        );
    }

    private function warehouseInventory(): WarehouseInventoryCapabilityInterface
    {
        return $this->warehouseInventory ??= $this->objectManager()->get(
            WarehouseInventoryCapabilityInterface::class,
        );
    }

    private function originalWarehouseLocator(): OriginalWarehouseLocator
    {
        return $this->originalWarehouseLocator ??= $this->objectManager()->get(
            OriginalWarehouseLocator::class,
        );
    }

    private function assetSnapshots(): OrderAssetAllocationSnapshotService
    {
        return $this->assetSnapshots ??= $this->objectManager()->get(
            OrderAssetAllocationSnapshotService::class,
        );
    }

    private function transactions(): WriteIntentTransactionCoordinatorInterface
    {
        return $this->transactions ??= $this->objectManager()->get(
            WriteIntentTransactionCoordinatorInterface::class,
        );
    }

    private function objectManager(): ObjectManager
    {
        return $this->objectManager ??= ObjectManager::getInstance();
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return T
     */
    private function newModel(string $class): Model
    {
        if ($this->modelFactory !== null) {
            $model = ($this->modelFactory)($class);
            if (!$model instanceof $class) {
                throw new \LogicException('order_refund_model_factory_type_invalid');
            }

            return $model;
        }
        return $this->objectManager()->getInstance($class, [], false);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T[]
     */
    private function modelItems(mixed $collection, string $class): array
    {
        if (\is_object($collection) && method_exists($collection, 'getItems')) {
            $collection = $collection->getItems();
        }
        if (!\is_array($collection)) {
            return [];
        }

        return array_values(array_filter(
            $collection,
            static fn (mixed $item): bool => $item instanceof $class,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (!\is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed>|list<mixed> $value
     */
    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function isSqlite(Model $model): bool
    {
        return strtolower((string)$model->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }

    private function occupiedQty(string $orderUuid, string $itemUuid): int
    {
        $sum = 0;
        foreach ($this->memory['cases'] as $case) {
            if (($case['order_uuid'] ?? '') !== $orderUuid) {
                continue;
            }
            $payment = $this->memory['payments'][$case['refund_case_uuid']] ?? null;
            if ($payment === null) {
                continue;
            }
            if (($payment['channel_status'] ?? '') === self::PAYMENT_CHANNEL_FAILED
                && ($payment['status'] ?? '') !== self::PAYMENT_STATUS_LATE_SUCCESS_REVIEW
            ) {
                continue;
            }
            if (!\in_array((string) ($payment['channel_status'] ?? ''), [
                self::PAYMENT_CHANNEL_SUBMITTED,
                self::PAYMENT_CHANNEL_PENDING,
                self::PAYMENT_CHANNEL_UNKNOWN,
                self::PAYMENT_CHANNEL_SUCCEEDED,
            ], true) && ($payment['status'] ?? '') !== self::PAYMENT_STATUS_LATE_SUCCESS_REVIEW) {
                continue;
            }
            foreach ($case['items'] as $line) {
                if (($line['item_uuid'] ?? '') === $itemUuid) {
                    $sum += (int) ($line['qty_minor'] ?? 0);
                }
            }
        }

        return $sum;
    }

    private function occupiedShipping(string $orderUuid): int
    {
        $sum = 0;
        foreach ($this->memory['cases'] as $case) {
            if (($case['order_uuid'] ?? '') !== $orderUuid) {
                continue;
            }
            $payment = $this->memory['payments'][$case['refund_case_uuid']] ?? null;
            if ($payment === null || ($payment['channel_status'] ?? '') === self::PAYMENT_CHANNEL_FAILED) {
                continue;
            }
            if (\in_array((string) ($payment['channel_status'] ?? ''), [
                self::PAYMENT_CHANNEL_SUBMITTED,
                self::PAYMENT_CHANNEL_PENDING,
                self::PAYMENT_CHANNEL_UNKNOWN,
                self::PAYMENT_CHANNEL_SUCCEEDED,
            ], true)) {
                $sum += (int) ($case['shipping_refund_minor'] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    private function findOrderItem(array $order, string $itemUuid): ?array
    {
        foreach ($order['items'] as $item) {
            if (($item['item_uuid'] ?? '') === $itemUuid) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $normalizedItems
     */
    private function allNeedShipCancelled(array $order, array $normalizedItems): bool
    {
        if ($normalizedItems === []) {
            return false;
        }
        foreach ($order['items'] as $item) {
            if (!empty($item['shipped'])) {
                return false;
            }
            $need = (int) ($item['qty_minor'] ?? 0);
            $req = 0;
            foreach ($normalizedItems as $line) {
                if (($line['item_uuid'] ?? '') === ($item['item_uuid'] ?? '')) {
                    $req = (int) ($line['qty_minor'] ?? 0);
                }
            }
            if ($req < $need) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{ok:bool,error_code:?string,case:?array,payment:?array}
     */
    private function fail(string $code): array
    {
        return ['ok' => false, 'error_code' => $code, 'case' => null, 'payment' => null];
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
