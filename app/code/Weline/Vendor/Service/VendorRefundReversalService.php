<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Throwable;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Vendor\Model\VendorRefundReversalRecord;

/**
 * Append-only refund → payout reversal journal.
 *
 * Production updates payout CAS state and writes the reversal on one default
 * database transaction. Split snapshots are never mutated.
 */
final class VendorRefundReversalService
{
    public const ERROR_EXISTS = 'vendor_reversal_exists';
    public const ERROR_IDEMPOTENCY = 'vendor_reversal_idempotency_conflict';
    public const ERROR_NOT_FOUND = 'vendor_reversal_not_found';
    public const ERROR_ENV = 'vendor_reversal_environment_mismatch';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $reversals = null;
    /** @var array<string, string> */
    private array $byRefund = [];
    /** @var (\Closure(): VendorRefundReversalRecord)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): VendorRefundReversalRecord)|null $recordFactory */
    public function __construct(
        private readonly VendorPayoutLedger $payouts,
        private readonly VendorSplitSnapshotStore $snapshots,
        private readonly ?DatabaseTransactionRunnerInterface $transactions = null,
        ?callable $recordFactory = null,
        bool $useMemory = false,
    ) {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->reversals = [];
        }
    }

    public static function forTesting(
        ?VendorPayoutLedger $payouts = null,
        ?VendorSplitSnapshotStore $snapshots = null,
    ): self {
        $snapshots ??= VendorSplitSnapshotStore::forTesting();
        $payouts ??= VendorPayoutLedger::forTesting($snapshots);

        return new self($payouts, $snapshots, useMemory: true);
    }

    /**
     * @param array{
     *   payout_id:string,
     *   refund_ref:string,
     *   amount_minor?:int,
     *   reason?:string
     * } $input
     * @return array<string, mixed>
     */
    public function reverse(array $input): array
    {
        $payoutId = trim((string) ($input['payout_id'] ?? ''));
        $refundRef = trim((string) ($input['refund_ref'] ?? ''));
        if ($payoutId === '' || $refundRef === '') {
            throw new \InvalidArgumentException(__('payout_id 与 refund_ref 必填'));
        }
        if (strlen($refundRef) > 128) {
            throw new \InvalidArgumentException(__('refund_ref 过长'));
        }
        $reason = trim((string) ($input['reason'] ?? 'refund'));
        if ($reason === '' || strlen($reason) > 255) {
            throw new \InvalidArgumentException(__('reversal reason 无效'));
        }
        $amountDescriptor = array_key_exists('amount_minor', $input)
            ? (string) (int) $input['amount_minor']
            : 'remaining';
        $requestHash = hash(
            'sha256',
            implode('|', [$payoutId, $refundRef, $amountDescriptor, $reason]),
        );

        if ($this->reversals !== null) {
            return $this->reverseOnce($payoutId, $refundRef, $input, $reason, $requestHash);
        }

        $prototype = $this->newRecord();
        try {
            return $this->transactionRunner()->run(
                $prototype->getConnection(),
                fn (): array => $this->reverseOnce(
                    $payoutId,
                    $refundRef,
                    $input,
                    $reason,
                    $requestHash,
                ),
            );
        } catch (VendorConflictException|\InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Reload only after rollback; PostgreSQL may have aborted the failed transaction.
            $winner = $this->findByRefund($payoutId, $refundRef);
            if ($winner !== null) {
                return $this->replayResult($winner, $requestHash, $e);
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->reversals !== null) {
            return array_values($this->reversals);
        }
        return array_values(array_map(
            static fn (array $row): array => $row,
            $this->newRecord()->clear()->select()->fetchArray(),
        ));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function reverseOnce(
        string $payoutId,
        string $refundRef,
        array $input,
        string $reason,
        string $requestHash,
    ): array {
        $existing = $this->findByRefund($payoutId, $refundRef);
        if ($existing !== null) {
            return $this->replayResult($existing, $requestHash);
        }

        $payout = $this->payouts->get($payoutId);
        $snap = $this->snapshots->get((string) $payout['snapshot_id']);
        if ((string) $payout['environment'] !== (string) $snap['environment']
            || (int) $payout['store_id'] !== (int) $snap['store_id']
        ) {
            throw new VendorConflictException(
                self::ERROR_ENV,
                __('reversal environment/Store 不匹配'),
                ['payout_id' => $payoutId],
            );
        }
        $amount = array_key_exists('amount_minor', $input)
            ? (int) $input['amount_minor']
            : (int) $payout['net_minor'];
        $updatedPayout = $this->payouts->applyReversalAmount($payoutId, $amount);
        $reversalId = 'rv_' . substr(hash('sha256', $payoutId . '|' . $refundRef), 0, 24);
        $row = [
            'reversal_id' => $reversalId,
            'payout_id' => $payoutId,
            'snapshot_id' => (string) $payout['snapshot_id'],
            'vendor_id' => (string) $payout['vendor_id'],
            'website_id' => (int) $payout['website_id'],
            'store_id' => (int) $payout['store_id'],
            'store_mode_snapshot' => (string) $payout['store_mode_snapshot'],
            'environment' => (string) $payout['environment'],
            'refund_ref' => $refundRef,
            'amount_minor' => $amount,
            'currency' => (string) $payout['currency'],
            'reason' => $reason,
            'payout_net_after_minor' => (int) $updatedPayout['net_minor'],
            'request_hash' => $requestHash,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->reversals !== null) {
            $this->reversals[$reversalId] = $row;
            $this->byRefund[$this->refundKey($payoutId, $refundRef)] = $reversalId;
        } else {
            $this->newRecord()->clear()->setData($this->recordData($row))->save();
            $saved = $this->findByRefund($payoutId, $refundRef);
            if ($saved === null) {
                throw new \RuntimeException(__('Vendor reversal 写入后无法回读'));
            }
            $row = $saved;
        }

        return [
            'ok' => true,
            'replayed' => false,
            'reversal' => $row,
            'payout' => $updatedPayout,
            'snapshot_unchanged' => true,
            'snapshot_hash' => (string) $snap['payload_hash'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function findByRefund(string $payoutId, string $refundRef): ?array
    {
        if ($this->reversals !== null) {
            $id = $this->byRefund[$this->refundKey($payoutId, $refundRef)] ?? null;
            return $id !== null ? ($this->reversals[$id] ?? null) : null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorRefundReversalRecord::schema_fields_PAYOUT_ID, $payoutId)
            ->where(VendorRefundReversalRecord::schema_fields_REFUND_REF, $refundRef)
            ->find()
            ->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function replayResult(
        array $existing,
        string $requestHash,
        ?Throwable $previous = null,
    ): array {
        if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
            throw new VendorConflictException(
                self::ERROR_IDEMPOTENCY,
                __('同一 refund_ref 的 reversal 请求参数冲突'),
                ['payout_id' => $existing['payout_id'], 'refund_ref' => $existing['refund_ref']],
                0,
                $previous,
            );
        }
        $payout = $this->payouts->get((string) $existing['payout_id']);
        $snap = $this->snapshots->get((string) $existing['snapshot_id']);
        return [
            'ok' => true,
            'replayed' => true,
            'reversal' => $existing,
            'payout' => $payout,
            'snapshot_unchanged' => true,
            'snapshot_hash' => (string) $snap['payload_hash'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function recordData(array $row): array
    {
        return [
            VendorRefundReversalRecord::schema_fields_REVERSAL_ID => $row['reversal_id'],
            VendorRefundReversalRecord::schema_fields_PAYOUT_ID => $row['payout_id'],
            VendorRefundReversalRecord::schema_fields_SNAPSHOT_ID => $row['snapshot_id'],
            VendorRefundReversalRecord::schema_fields_VENDOR_ID => $row['vendor_id'],
            VendorRefundReversalRecord::schema_fields_WEBSITE_ID => $row['website_id'],
            VendorRefundReversalRecord::schema_fields_STORE_ID => $row['store_id'],
            VendorRefundReversalRecord::schema_fields_STORE_MODE => $row['store_mode_snapshot'],
            VendorRefundReversalRecord::schema_fields_ENVIRONMENT => $row['environment'],
            VendorRefundReversalRecord::schema_fields_REFUND_REF => $row['refund_ref'],
            VendorRefundReversalRecord::schema_fields_AMOUNT_MINOR => $row['amount_minor'],
            VendorRefundReversalRecord::schema_fields_CURRENCY => $row['currency'],
            VendorRefundReversalRecord::schema_fields_REASON => $row['reason'],
            VendorRefundReversalRecord::schema_fields_PAYOUT_NET_AFTER_MINOR
                => $row['payout_net_after_minor'],
            VendorRefundReversalRecord::schema_fields_REQUEST_HASH => $row['request_hash'],
            VendorRefundReversalRecord::schema_fields_CREATED_AT => $row['created_at'],
        ];
    }

    private function transactionRunner(): DatabaseTransactionRunnerInterface
    {
        $runner = $this->transactions
            ?? ObjectManager::getInstance(DatabaseTransactionRunnerInterface::class);
        if (!$runner instanceof DatabaseTransactionRunnerInterface) {
            throw new \LogicException('DatabaseTransactionRunnerInterface is unavailable');
        }
        return $runner;
    }

    private function refundKey(string $payoutId, string $refundRef): string
    {
        return trim($payoutId) . '|' . trim($refundRef);
    }

    private function newRecord(): VendorRefundReversalRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(VendorRefundReversalRecord::class, [], false);
    }
}
