<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\OrderAssetAllocationSnapshot;
use Weline\Payment\Api\OrderAssetAllocationSnapshotSinkInterface;

/**
 * Order implementation of Payment's optional allocation snapshot sink.
 */
final class OrderAssetAllocationSnapshotService implements
    OrderAssetAllocationSnapshotSinkInterface
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $memory = null;

    public function __construct(bool $useMemory = false)
    {
        if ($useMemory) {
            $this->memory = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(true);
    }

    public function recordCommittedAllocations(
        string $payableType,
        string $payableId,
        string $intentCode,
        ?string $attemptCode,
        array $allocations,
        string $effectKey,
    ): array {
        $payableType = strtolower(trim($payableType));
        if (!in_array($payableType, ['order', 'weline_order'], true)) {
            return ['ok' => true, 'not_applicable' => true, 'snapshots' => []];
        }
        $orderUuid = trim($payableId);
        $intentCode = trim($intentCode);
        $attemptCode = $attemptCode !== null ? trim($attemptCode) : null;
        $effectKey = trim($effectKey);
        if ($orderUuid === '' || strlen($orderUuid) > 36) {
            throw new \InvalidArgumentException('order_asset_snapshot_order_uuid_invalid');
        }
        if ($intentCode === '' || $effectKey === '') {
            throw new \InvalidArgumentException('order_asset_snapshot_payment_identity_required');
        }

        $snapshots = [];
        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) {
                throw new \InvalidArgumentException('order_asset_snapshot_allocation_invalid');
            }
            $payload = $this->canonicalPayload(
                $orderUuid,
                $intentCode,
                $attemptCode,
                $allocation,
                $effectKey,
            );
            $existing = $this->findByAllocationCode($payload['allocation_code']);
            if ($existing !== null) {
                if (!hash_equals(
                    (string) $existing['payload_hash'],
                    (string) $payload['payload_hash'],
                )) {
                    throw new \LogicException(
                        'order_asset_snapshot_immutable_conflict:'
                        . $payload['allocation_code'],
                    );
                }
                $snapshots[] = $existing + ['replayed' => true];
                continue;
            }
            $snapshots[] = $this->insert($payload) + ['replayed' => false];
        }

        return [
            'ok' => true,
            'not_applicable' => $snapshots === [],
            'snapshots' => $snapshots,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listForOrder(string $orderUuid): array
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return [];
        }
        if ($this->memory !== null) {
            $rows = array_values(array_filter(
                $this->memory,
                static fn (array $row): bool =>
                    (string) ($row['order_uuid'] ?? '') === $orderUuid,
            ));
            usort(
                $rows,
                static fn (array $left, array $right): int => strcmp(
                    (string) $left['allocation_code'],
                    (string) $right['allocation_code'],
                ),
            );
            return $rows;
        }
        $rows = $this->newModel()->clear()
            ->where(OrderAssetAllocationSnapshot::schema_fields_ORDER_UUID, $orderUuid)
            ->order(OrderAssetAllocationSnapshot::schema_fields_ID, 'asc')
            ->select()
            ->fetchArray();

        return array_map($this->normalize(...), $rows);
    }

    /**
     * Freeze the non-cash portion of one refund from the original committed
     * tender. Cumulative targets make partial refunds independent of request
     * chunking; allocation_code ordering makes replays deterministic.
     *
     * @param list<array<string, mixed>> $previousAssetAllocations
     * @return array{
     *   total_amount_minor:int,
     *   cash_amount_minor:int,
     *   asset_amount_minor:int,
     *   asset_allocations:list<array<string, mixed>>
     * }
     */
    public function allocateRefund(
        string $orderUuid,
        int $orderTotalMinor,
        int $refundAmountMinor,
        int $previousRefundedTotalMinor = 0,
        array $previousAssetAllocations = [],
    ): array {
        if ($orderTotalMinor <= 0
            || $refundAmountMinor <= 0
            || $previousRefundedTotalMinor < 0
            || $previousRefundedTotalMinor > $orderTotalMinor
            || $refundAmountMinor > $orderTotalMinor - $previousRefundedTotalMinor
        ) {
            throw new \InvalidArgumentException('order_asset_refund_amount_invalid');
        }
        $snapshots = $this->listForOrder($orderUuid);
        if ($snapshots === []) {
            return [
                'total_amount_minor' => $refundAmountMinor,
                'cash_amount_minor' => $refundAmountMinor,
                'asset_amount_minor' => 0,
                'asset_allocations' => [],
            ];
        }
        $assetTenderTotal = array_sum(array_map(
            static fn (array $row): int => (int) ($row['amount_minor'] ?? 0),
            $snapshots,
        ));
        if ($assetTenderTotal <= 0 || $assetTenderTotal > $orderTotalMinor) {
            throw new \LogicException('order_asset_refund_snapshot_total_invalid');
        }

        $cumulativeRefundMinor = $previousRefundedTotalMinor + $refundAmountMinor;
        $previousAssetTarget = $this->proportionalFloor(
            $previousRefundedTotalMinor,
            $assetTenderTotal,
            $orderTotalMinor,
        );
        $cumulativeAssetTarget = $cumulativeRefundMinor === $orderTotalMinor
            ? $assetTenderTotal
            : $this->proportionalFloor(
                $cumulativeRefundMinor,
                $assetTenderTotal,
                $orderTotalMinor,
            );
        $previousByCode = $this->aggregatePreviousAllocations(
            $previousAssetAllocations,
        );
        $previousTargets = $this->distributePaymentTarget(
            $snapshots,
            $previousAssetTarget,
            $assetTenderTotal,
        );
        $cumulativeTargets = $this->distributePaymentTarget(
            $snapshots,
            $cumulativeAssetTarget,
            $assetTenderTotal,
        );

        $allocations = [];
        $assetAmountMinor = 0;
        foreach ($snapshots as $snapshot) {
            $allocationCode = (string) $snapshot['allocation_code'];
            $previous = $previousByCode[$allocationCode] ?? [
                'payment_refund_amount_minor' => 0,
                'asset_return_amount_minor' => 0,
            ];
            $expectedPreviousPayment = $previousTargets[$allocationCode] ?? 0;
            if ($previous['payment_refund_amount_minor'] !== $expectedPreviousPayment) {
                throw new \LogicException(
                    'order_asset_refund_previous_payment_drift:' . $allocationCode,
                );
            }
            $originalPaymentMinor = (int) $snapshot['amount_minor'];
            $originalAssetMinor = (int) $snapshot['asset_amount_minor'];
            $expectedPreviousAsset = $expectedPreviousPayment === $originalPaymentMinor
                ? $originalAssetMinor
                : $this->proportionalFloor(
                    $expectedPreviousPayment,
                    $originalAssetMinor,
                    $originalPaymentMinor,
                );
            if ($previous['asset_return_amount_minor'] !== $expectedPreviousAsset) {
                throw new \LogicException(
                    'order_asset_refund_previous_asset_drift:' . $allocationCode,
                );
            }

            $targetPaymentMinor = $cumulativeTargets[$allocationCode] ?? 0;
            $targetAssetMinor = $targetPaymentMinor === $originalPaymentMinor
                ? $originalAssetMinor
                : $this->proportionalFloor(
                    $targetPaymentMinor,
                    $originalAssetMinor,
                    $originalPaymentMinor,
                );
            $paymentDelta = $targetPaymentMinor - $expectedPreviousPayment;
            $assetDelta = $targetAssetMinor - $expectedPreviousAsset;
            if ($paymentDelta < 0 || $assetDelta < 0) {
                throw new \LogicException(
                    'order_asset_refund_cumulative_regression:' . $allocationCode,
                );
            }
            if ($paymentDelta === 0) {
                continue;
            }
            if ($assetDelta <= 0) {
                throw new \LogicException(
                    'order_asset_refund_quantization_unrepresentable:' . $allocationCode,
                );
            }
            $assetAmountMinor += $paymentDelta;
            $allocations[] = [
                'allocation_code' => $allocationCode,
                'reservation_id' => (string) $snapshot['reservation_id'],
                'customer_id' => (string) $snapshot['customer_id'],
                'website_id' => (int) $snapshot['website_id'],
                'asset_code' => (string) $snapshot['asset_code'],
                'source_code' => (string) $snapshot['source_code'],
                'role' => (string) $snapshot['role'],
                'namespace' => (string) $snapshot['namespace'],
                'currency_code' => (string) $snapshot['currency_code'],
                'precision' => (int) $snapshot['precision'],
                'payment_refund_amount_minor' => $paymentDelta,
                'asset_return_amount_minor' => $assetDelta,
                'cumulative_payment_refunded_minor' => $targetPaymentMinor,
                'cumulative_asset_returned_minor' => $targetAssetMinor,
            ];
        }
        if ($assetAmountMinor !== $cumulativeAssetTarget - $previousAssetTarget) {
            throw new \LogicException('order_asset_refund_allocation_conservation_failed');
        }

        return [
            'total_amount_minor' => $refundAmountMinor,
            'cash_amount_minor' => $refundAmountMinor - $assetAmountMinor,
            'asset_amount_minor' => $assetAmountMinor,
            'asset_allocations' => $allocations,
        ];
    }

    /** @param array<string, mixed> $allocation @return array<string, mixed> */
    private function canonicalPayload(
        string $orderUuid,
        string $intentCode,
        ?string $attemptCode,
        array $allocation,
        string $effectKey,
    ): array {
        $required = [
            'allocation_code',
            'customer_id',
            'asset_code',
            'source_code',
            'role',
            'namespace',
            'reservation_id',
            'currency_code',
        ];
        foreach ($required as $field) {
            if (trim((string) ($allocation[$field] ?? '')) === '') {
                throw new \InvalidArgumentException(
                    'order_asset_snapshot_field_required:' . $field,
                );
            }
        }
        if ((string) ($allocation['status'] ?? '') !== 'committed') {
            throw new \LogicException('order_asset_snapshot_requires_committed_allocation');
        }
        $assetAmountMinor = (int) ($allocation['asset_amount_minor'] ?? 0);
        $amountMinor = (int) ($allocation['amount_minor'] ?? 0);
        $websiteId = (int) ($allocation['website_id'] ?? -1);
        if ($assetAmountMinor <= 0 || $amountMinor <= 0 || $websiteId < 0) {
            throw new \InvalidArgumentException('order_asset_snapshot_amount_scope_invalid');
        }
        $canonical = [
            'allocation_code' => (string) $allocation['allocation_code'],
            'order_uuid' => $orderUuid,
            'intent_code' => $intentCode,
            'attempt_code' => $attemptCode,
            'customer_id' => (string) $allocation['customer_id'],
            'website_id' => $websiteId,
            'asset_code' => (string) $allocation['asset_code'],
            'source_code' => (string) $allocation['source_code'],
            'role' => (string) $allocation['role'],
            'namespace' => (string) $allocation['namespace'],
            'reservation_id' => (string) $allocation['reservation_id'],
            'asset_amount_minor' => $assetAmountMinor,
            'amount_minor' => $amountMinor,
            'currency_code' => strtoupper((string) $allocation['currency_code']),
            'precision' => (int) ($allocation['precision'] ?? 2),
            'effect_key' => $effectKey,
        ];
        $json = json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $canonical + [
            'snapshot_code' => 'oas_' . substr(
                hash('sha256', $orderUuid . '|' . $canonical['allocation_code']),
                0,
                40,
            ),
            'payload_hash' => hash('sha256', $json),
            'snapshot_json' => $json,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function insert(array $payload): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = $payload + [
            'committed_at' => $now,
            'created_at' => $now,
        ];
        if ($this->memory !== null) {
            $this->memory[$payload['allocation_code']] = $row;
            return $row;
        }
        $this->newModel()->clear()->setData([
            OrderAssetAllocationSnapshot::schema_fields_SNAPSHOT_CODE =>
                $row['snapshot_code'],
            OrderAssetAllocationSnapshot::schema_fields_ALLOCATION_CODE =>
                $row['allocation_code'],
            OrderAssetAllocationSnapshot::schema_fields_ORDER_UUID => $row['order_uuid'],
            OrderAssetAllocationSnapshot::schema_fields_INTENT_CODE => $row['intent_code'],
            OrderAssetAllocationSnapshot::schema_fields_ATTEMPT_CODE => $row['attempt_code'],
            OrderAssetAllocationSnapshot::schema_fields_CUSTOMER_ID => $row['customer_id'],
            OrderAssetAllocationSnapshot::schema_fields_WEBSITE_ID => $row['website_id'],
            OrderAssetAllocationSnapshot::schema_fields_ASSET_CODE => $row['asset_code'],
            OrderAssetAllocationSnapshot::schema_fields_SOURCE_CODE => $row['source_code'],
            OrderAssetAllocationSnapshot::schema_fields_ROLE => $row['role'],
            OrderAssetAllocationSnapshot::schema_fields_NAMESPACE => $row['namespace'],
            OrderAssetAllocationSnapshot::schema_fields_RESERVATION_ID =>
                $row['reservation_id'],
            OrderAssetAllocationSnapshot::schema_fields_ASSET_AMOUNT_MINOR =>
                $row['asset_amount_minor'],
            OrderAssetAllocationSnapshot::schema_fields_AMOUNT_MINOR => $row['amount_minor'],
            OrderAssetAllocationSnapshot::schema_fields_CURRENCY_CODE =>
                $row['currency_code'],
            OrderAssetAllocationSnapshot::schema_fields_PRECISION => $row['precision'],
            OrderAssetAllocationSnapshot::schema_fields_EFFECT_KEY => $row['effect_key'],
            OrderAssetAllocationSnapshot::schema_fields_PAYLOAD_HASH => $row['payload_hash'],
            OrderAssetAllocationSnapshot::schema_fields_SNAPSHOT_JSON =>
                $row['snapshot_json'],
            OrderAssetAllocationSnapshot::schema_fields_COMMITTED_AT => $now,
            OrderAssetAllocationSnapshot::schema_fields_CREATED_AT => $now,
        ])->save();

        return $this->findByAllocationCode((string) $payload['allocation_code'])
            ?? throw new \RuntimeException('order_asset_snapshot_write_not_visible');
    }

    /** @return array<string, mixed>|null */
    private function findByAllocationCode(string $allocationCode): ?array
    {
        if ($this->memory !== null) {
            return $this->memory[$allocationCode] ?? null;
        }
        $model = $this->newModel()->clear()
            ->where(
                OrderAssetAllocationSnapshot::schema_fields_ALLOCATION_CODE,
                $allocationCode,
            )
            ->find()
            ->fetch();

        return $model->getId() ? $this->normalize($model->getData()) : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        foreach ([
            'website_id',
            'asset_amount_minor',
            'amount_minor',
            'precision',
        ] as $field) {
            $row[$field] = (int) ($row[$field] ?? 0);
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $allocations
     * @return array<string, array{
     *   payment_refund_amount_minor:int,
     *   asset_return_amount_minor:int
     * }>
     */
    private function aggregatePreviousAllocations(array $allocations): array
    {
        $result = [];
        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) {
                throw new \InvalidArgumentException(
                    'order_asset_refund_previous_allocation_invalid',
                );
            }
            $code = trim((string) ($allocation['allocation_code'] ?? ''));
            $payment = (int) ($allocation['payment_refund_amount_minor'] ?? 0);
            $asset = (int) ($allocation['asset_return_amount_minor'] ?? 0);
            if ($code === '' || $payment < 0 || $asset < 0) {
                throw new \InvalidArgumentException(
                    'order_asset_refund_previous_allocation_invalid',
                );
            }
            $result[$code] ??= [
                'payment_refund_amount_minor' => 0,
                'asset_return_amount_minor' => 0,
            ];
            $result[$code]['payment_refund_amount_minor'] += $payment;
            $result[$code]['asset_return_amount_minor'] += $asset;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     * @return array<string, int>
     */
    private function distributePaymentTarget(
        array $snapshots,
        int $targetMinor,
        int $assetTenderTotal,
    ): array {
        $targets = [];
        $assigned = 0;
        $lastIndex = count($snapshots) - 1;
        foreach ($snapshots as $index => $snapshot) {
            $code = (string) $snapshot['allocation_code'];
            $amount = $index === $lastIndex
                ? $targetMinor - $assigned
                : $this->proportionalFloor(
                    $targetMinor,
                    (int) $snapshot['amount_minor'],
                    $assetTenderTotal,
                );
            $targets[$code] = $amount;
            $assigned += $amount;
        }
        if ($assigned !== $targetMinor) {
            throw new \LogicException('order_asset_refund_distribution_failed');
        }

        return $targets;
    }

    private function proportionalFloor(int $value, int $part, int $whole): int
    {
        if ($value < 0 || $part < 0 || $whole <= 0) {
            throw new \InvalidArgumentException(
                'order_asset_refund_proportion_invalid',
            );
        }
        if ($value === 0 || $part === 0) {
            return 0;
        }
        if ($value > intdiv(PHP_INT_MAX, $part)) {
            throw new \OverflowException('order_asset_refund_amount_overflow');
        }

        return intdiv($value * $part, $whole);
    }

    private function newModel(): OrderAssetAllocationSnapshot
    {
        /** @var OrderAssetAllocationSnapshot $model */
        $model = ObjectManager::create(
            OrderAssetAllocationSnapshot::class,
            [],
            false,
        );
        return $model;
    }
}
