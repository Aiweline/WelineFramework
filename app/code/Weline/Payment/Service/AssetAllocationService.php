<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

final class AssetAllocationService
{
    public const ROLE_PAYMENT = 'payment';
    public const ROLE_DISCOUNT = 'discount';

    public const ASSET_CREDIT = 'credit';
    public const ASSET_POINTS = 'points';
    public const ASSET_WCOIN = 'wcoin';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_PARTIALLY_COMMITTED = 'partially_committed';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_PARTIALLY_RELEASED = 'partially_released';
    public const STATUS_RELEASED = 'released';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * @param array<string, mixed> $allocation
     * @param array<string, mixed> $payable
     * @return array<string, mixed>
     */
    public function reserve(array $allocation, array $payable): array
    {
        $allocation = $this->normalizeAllocation($allocation, $payable);
        if ($allocation['status'] === self::STATUS_RESERVED) {
            return $allocation;
        }

        if ($allocation['status'] !== self::STATUS_DRAFT) {
            throw new \LogicException('payment_asset_allocation_reserve_invalid_transition');
        }

        $allocation['status'] = self::STATUS_RESERVED;
        $allocation['reserved_amount_minor'] = $allocation['amount_minor'];

        return $allocation;
    }

    /**
     * @param array<string, mixed> $allocation
     * @return array<string, mixed>
     */
    public function commit(array $allocation, ?int $amountMinor = null): array
    {
        $allocation = $this->normalizeAllocation($allocation, $allocation);
        $availableAmount = $this->getReservableRemainder($allocation);
        $amountMinor ??= $availableAmount;
        $this->assertPositiveAmount($amountMinor, 'commit_amount_minor');

        if ($amountMinor > $availableAmount) {
            throw new \LogicException('payment_asset_commit_amount_exceeds_reserved_amount');
        }

        $allocation['committed_amount_minor'] += $amountMinor;
        $allocation['status'] = $this->getReservableRemainder($allocation) === 0
            ? self::STATUS_COMMITTED
            : self::STATUS_PARTIALLY_COMMITTED;

        return $allocation;
    }

    /**
     * @param array<string, mixed> $allocation
     * @return array<string, mixed>
     */
    public function release(array $allocation, ?int $amountMinor = null): array
    {
        $allocation = $this->normalizeAllocation($allocation, $allocation);
        $availableAmount = $this->getReservableRemainder($allocation);
        $amountMinor ??= $availableAmount;
        $this->assertPositiveAmount($amountMinor, 'release_amount_minor');

        if ($amountMinor > $availableAmount) {
            throw new \LogicException('payment_asset_release_amount_exceeds_reserved_amount');
        }

        $allocation['released_amount_minor'] += $amountMinor;
        if ($allocation['released_amount_minor'] === $allocation['reserved_amount_minor'] && $allocation['committed_amount_minor'] === 0) {
            $allocation['status'] = self::STATUS_RELEASED;
        } else {
            $allocation['status'] = self::STATUS_PARTIALLY_RELEASED;
        }

        return $allocation;
    }

    /**
     * @param array<string, mixed> $allocation
     * @return array<string, mixed>
     */
    public function refund(array $allocation, ?int $amountMinor = null): array
    {
        $allocation = $this->normalizeAllocation($allocation, $allocation);
        $availableAmount = $allocation['committed_amount_minor'] - $allocation['refunded_amount_minor'];
        $amountMinor ??= $availableAmount;
        $this->assertPositiveAmount($amountMinor, 'refund_amount_minor');

        if ($amountMinor > $availableAmount) {
            throw new \LogicException('payment_asset_refund_amount_exceeds_committed_amount');
        }

        $allocation['refunded_amount_minor'] += $amountMinor;
        $allocation['status'] = $allocation['refunded_amount_minor'] === $allocation['committed_amount_minor']
            ? self::STATUS_REFUNDED
            : self::STATUS_PARTIALLY_REFUNDED;

        return $allocation;
    }

    /**
     * @param array<int, array<string, mixed>> $allocations
     */
    public function assertNoDualRole(array $allocations): void
    {
        $rolesByPayableAsset = [];
        foreach ($allocations as $allocation) {
            $allocation = $this->normalizeAllocation($allocation, $allocation);
            $key = implode(':', [
                $allocation['payable_type'],
                $allocation['payable_id'],
                $allocation['asset_code'],
            ]);
            $rolesByPayableAsset[$key][$allocation['role']] = true;
            if (\count($rolesByPayableAsset[$key]) > 1) {
                throw new \LogicException('payment_asset_dual_role_for_same_payable_not_allowed:' . $key);
            }
        }
    }

    /**
     * @param array<string, mixed> $allocation
     * @param array<string, mixed> $payable
     * @return array<string, mixed>
     */
    public function normalizeAllocation(array $allocation, array $payable): array
    {
        $payableType = (string) ($allocation['payable_type'] ?? $payable['payable_type'] ?? '');
        $payableId = (string) ($allocation['payable_id'] ?? $payable['payable_id'] ?? '');
        $payableType = $this->normalizePayableType($payableType);
        $payableId = trim($payableId);
        if ($payableId === '') {
            throw new \InvalidArgumentException('payment_asset_payable_id_required');
        }

        $assetCode = $this->normalizeAssetCode((string) ($allocation['asset_code'] ?? ''));
        $role = $this->normalizeRole((string) ($allocation['role'] ?? ''));
        $amountMinor = $this->normalizeAmountMinor($allocation['amount_minor'] ?? $allocation['reserved_amount_minor'] ?? 0, 'asset_amount_minor');
        $this->assertPositiveAmount($amountMinor, 'asset_amount_minor');

        $reservedAmount = $this->normalizeAmountMinor($allocation['reserved_amount_minor'] ?? 0, 'reserved_amount_minor');
        $committedAmount = $this->normalizeAmountMinor($allocation['committed_amount_minor'] ?? 0, 'committed_amount_minor');
        $releasedAmount = $this->normalizeAmountMinor($allocation['released_amount_minor'] ?? 0, 'released_amount_minor');
        $refundedAmount = $this->normalizeAmountMinor($allocation['refunded_amount_minor'] ?? 0, 'refunded_amount_minor');

        if ($committedAmount + $releasedAmount > max($reservedAmount, $amountMinor)) {
            throw new \LogicException('payment_asset_allocation_amounts_unbalanced');
        }

        return [
            'allocation_code' => (string) ($allocation['allocation_code'] ?? $this->buildAllocationCode($allocation, $payableType, $payableId, $assetCode, $role, $amountMinor)),
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'source_code' => (string) ($allocation['source_code'] ?? $assetCode),
            'asset_code' => $assetCode,
            'role' => $role,
            'amount_minor' => $amountMinor,
            'currency_code' => strtoupper((string) ($allocation['currency_code'] ?? $payable['currency_code'] ?? 'CNY')),
            'precision' => (int) ($allocation['precision'] ?? $payable['precision'] ?? 2),
            'status' => $this->normalizeStatus((string) ($allocation['status'] ?? self::STATUS_DRAFT)),
            'reserved_amount_minor' => $reservedAmount,
            'committed_amount_minor' => $committedAmount,
            'released_amount_minor' => $releasedAmount,
            'refunded_amount_minor' => $refundedAmount,
            'metadata' => \is_array($allocation['metadata'] ?? null) ? $allocation['metadata'] : [],
        ];
    }

    public function normalizeAssetCode(string $assetCode): string
    {
        $assetCode = strtolower(trim($assetCode));
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $assetCode)) {
            throw new \InvalidArgumentException('payment_asset_code_invalid:' . $assetCode);
        }

        return $assetCode;
    }

    public function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        if (!\in_array($role, [self::ROLE_PAYMENT, self::ROLE_DISCOUNT], true)) {
            throw new \InvalidArgumentException('payment_asset_role_invalid:' . $role);
        }

        return $role;
    }

    private function normalizePayableType(string $payableType): string
    {
        $payableType = strtolower(trim($payableType));
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $payableType)) {
            throw new \InvalidArgumentException('payment_asset_payable_type_invalid:' . $payableType);
        }

        return $payableType;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $allowed = [
            self::STATUS_DRAFT,
            self::STATUS_RESERVED,
            self::STATUS_PARTIALLY_COMMITTED,
            self::STATUS_COMMITTED,
            self::STATUS_PARTIALLY_RELEASED,
            self::STATUS_RELEASED,
            self::STATUS_PARTIALLY_REFUNDED,
            self::STATUS_REFUNDED,
        ];
        if (!\in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('payment_asset_allocation_status_invalid:' . $status);
        }

        return $status;
    }

    private function normalizeAmountMinor(mixed $amountMinor, string $field): int
    {
        if (\is_float($amountMinor)) {
            throw new \InvalidArgumentException($field . '_must_be_integer_minor_unit');
        }

        if (\is_int($amountMinor)) {
            return $amountMinor;
        }

        if (\is_string($amountMinor) && preg_match('/^-?\d+$/', $amountMinor)) {
            return (int) $amountMinor;
        }

        throw new \InvalidArgumentException($field . '_must_be_integer_minor_unit');
    }

    private function assertPositiveAmount(int $amountMinor, string $field): void
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException($field . '_must_be_positive');
        }
    }

    /**
     * @param array<string, mixed> $allocation
     */
    private function buildAllocationCode(array $allocation, string $payableType, string $payableId, string $assetCode, string $role, int $amountMinor): string
    {
        $sourceCode = (string) ($allocation['source_code'] ?? $assetCode);

        return 'asset_' . substr(sha1($payableType . '|' . $payableId . '|' . $assetCode . '|' . $sourceCode . '|' . $role . '|' . $amountMinor), 0, 24);
    }

    /**
     * @param array<string, mixed> $allocation
     */
    private function getReservableRemainder(array $allocation): int
    {
        return $allocation['reserved_amount_minor']
            - $allocation['committed_amount_minor']
            - $allocation['released_amount_minor'];
    }
}
