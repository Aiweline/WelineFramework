<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Vendor\Model\VendorPayoutRecord;

/**
 * Durable payout ledger against immutable split snapshots.
 *
 * Existing snapshot obligations remain callable while Vendor rollout is off.
 */
final class VendorPayoutLedger
{
    private const MAX_CAS_ATTEMPTS = 8;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIALLY_REVERSED = 'partially_reversed';
    public const STATUS_REVERSED = 'reversed';

    public const ERROR_EXISTS = 'vendor_payout_exists';
    public const ERROR_IDEMPOTENCY = 'vendor_payout_idempotency_conflict';
    public const ERROR_NOT_FOUND = 'vendor_payout_not_found';
    public const ERROR_AMOUNT = 'vendor_payout_amount_invalid';
    public const ERROR_ENV = 'vendor_payout_environment_mismatch';
    public const ERROR_CONCURRENT = 'vendor_payout_concurrent_update';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $payouts = null;
    /** @var array<string, string> */
    private array $bySnapshot = [];
    /** @var (\Closure(): VendorPayoutRecord)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): VendorPayoutRecord)|null $recordFactory */
    public function __construct(
        private readonly VendorSplitSnapshotStore $snapshots,
        ?callable $recordFactory = null,
        bool $useMemory = false,
    ) {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->payouts = [];
        }
    }

    public static function forTesting(?VendorSplitSnapshotStore $snapshots = null): self
    {
        return new self(
            $snapshots ?? VendorSplitSnapshotStore::forTesting(),
            useMemory: true,
        );
    }

    /** @return array<string, mixed> */
    public function scheduleFromSnapshot(string $snapshotId, ?string $idempotencyKey = null): array
    {
        $snapshotId = trim($snapshotId);
        $idempotencyKey = trim((string) ($idempotencyKey ?? ''));
        if (strlen($idempotencyKey) > 128) {
            throw new \InvalidArgumentException(__('payout idempotency key 过长'));
        }
        $snap = $this->snapshots->get($snapshotId);
        $requestHash = hash('sha256', $snapshotId . '|' . $idempotencyKey);
        $existing = $this->findBySnapshot($snapshotId);
        if ($existing !== null) {
            return $this->assertReplay($existing, $requestHash);
        }

        $amount = (int) $snap['vendor_share_minor'];
        if ($amount < 0) {
            throw new VendorConflictException(self::ERROR_AMOUNT, __('payout 金额非法'), ['amount' => $amount]);
        }
        $payoutId = 'po_' . substr(hash('sha256', $snapshotId), 0, 24);
        $now = date('Y-m-d H:i:s');
        $row = [
            'payout_id' => $payoutId,
            'snapshot_id' => $snapshotId,
            'vendor_id' => (string) $snap['vendor_id'],
            'website_id' => (int) $snap['website_id'],
            'store_id' => (int) $snap['store_id'],
            'store_mode_snapshot' => (string) $snap['store_mode_snapshot'],
            'environment' => (string) $snap['environment'],
            'currency' => (string) $snap['currency'],
            'amount_minor' => $amount,
            'reversed_minor' => 0,
            'net_minor' => $amount,
            'status' => self::STATUS_SCHEDULED,
            'account_ref' => (string) ($snap['account']['account_ref'] ?? ''),
            'legal_entity' => (string) ($snap['legal']['legal_entity'] ?? ''),
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'ledger_version' => 1,
            'cas_token' => bin2hex(random_bytes(32)),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($this->payouts !== null) {
            $this->payouts[$payoutId] = $row;
            $this->bySnapshot[$snapshotId] = $payoutId;
            return $row;
        }
        try {
            $this->newRecord()->clear()->setData($this->recordData($row))->save();
        } catch (Throwable $e) {
            $winner = $this->findBySnapshot($snapshotId);
            if ($winner !== null) {
                return $this->assertReplay($winner, $requestHash, $e);
            }
            throw $e;
        }
        return $this->get($payoutId);
    }

    /** @return array<string, mixed> */
    public function markPaid(string $payoutId): array
    {
        return $this->casUpdate($payoutId, static function (array $row): array {
            if ((string) $row['status'] === self::STATUS_SCHEDULED) {
                $row['status'] = self::STATUS_PAID;
            }
            return $row;
        });
    }

    /** @return array<string, mixed> */
    public function get(string $payoutId): array
    {
        $payoutId = trim($payoutId);
        if ($this->payouts !== null) {
            $row = $this->payouts[$payoutId] ?? null;
        } else {
            $row = $this->findModel($payoutId)?->getData();
        }
        if ($row === null) {
            throw new VendorConflictException(
                self::ERROR_NOT_FOUND,
                __('payout 不存在：%{1}', [$payoutId]),
                ['payout_id' => $payoutId],
            );
        }
        return $row;
    }

    /** @return array<string, mixed> */
    public function applyReversalAmount(string $payoutId, int $reverseMinor): array
    {
        return $this->casUpdate($payoutId, static function (array $row) use ($reverseMinor): array {
            $nextReversed = (int) $row['reversed_minor'] + $reverseMinor;
            if ($reverseMinor <= 0 || $nextReversed > (int) $row['amount_minor']) {
                throw new VendorConflictException(
                    self::ERROR_AMOUNT,
                    __('reversal 超额或非法：%{1}', [$reverseMinor]),
                    [
                        'payout_id' => $row['payout_id'],
                        'amount_minor' => $row['amount_minor'],
                        'reversed_minor' => $row['reversed_minor'],
                        'requested' => $reverseMinor,
                    ],
                );
            }
            $row['reversed_minor'] = $nextReversed;
            $row['net_minor'] = (int) $row['amount_minor'] - $nextReversed;
            $row['status'] = $row['net_minor'] === 0
                ? self::STATUS_REVERSED
                : self::STATUS_PARTIALLY_REVERSED;
            return $row;
        });
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->payouts !== null) {
            return array_values($this->payouts);
        }
        return array_values(array_map(
            static fn (array $row): array => $row,
            $this->newRecord()->clear()->select()->fetchArray(),
        ));
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     * @return array<string, mixed>
     */
    private function casUpdate(string $payoutId, callable $mutator): array
    {
        if ($this->payouts !== null) {
            $row = $this->get($payoutId);
            $next = $mutator($row);
            if ($next === $row) {
                return $row;
            }
            $next['ledger_version'] = (int) $row['ledger_version'] + 1;
            $next['cas_token'] = bin2hex(random_bytes(32));
            $next['updated_at'] = date('Y-m-d H:i:s');
            $this->payouts[$payoutId] = $next;
            return $next;
        }

        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $current = $this->get($payoutId);
            $next = $mutator($current);
            if ($next === $current) {
                return $current;
            }
            $expectedVersion = (int) $current['ledger_version'];
            $expectedToken = (string) $current['cas_token'];
            $writerToken = bin2hex(random_bytes(32));
            $next['ledger_version'] = $expectedVersion + 1;
            $next['cas_token'] = $writerToken;
            $next['updated_at'] = date('Y-m-d H:i:s');

            $candidate = $this->newRecord();
            $candidate->getQuery(false)
                ->where(VendorPayoutRecord::schema_fields_PAYOUT_ID, $payoutId)
                ->where(VendorPayoutRecord::schema_fields_LEDGER_VERSION, $expectedVersion)
                ->where(VendorPayoutRecord::schema_fields_CAS_TOKEN, $expectedToken)
                ->update([
                    VendorPayoutRecord::schema_fields_REVERSED_MINOR => $next['reversed_minor'],
                    VendorPayoutRecord::schema_fields_NET_MINOR => $next['net_minor'],
                    VendorPayoutRecord::schema_fields_STATUS => $next['status'],
                    VendorPayoutRecord::schema_fields_LEDGER_VERSION => $next['ledger_version'],
                    VendorPayoutRecord::schema_fields_CAS_TOKEN => $writerToken,
                    VendorPayoutRecord::schema_fields_UPDATED_AT => $next['updated_at'],
                ])
                ->fetch();
            $saved = $this->get($payoutId);
            if (hash_equals($writerToken, (string) $saved['cas_token'])) {
                return $saved;
            }
        }

        throw new VendorConflictException(
            self::ERROR_CONCURRENT,
            __('payout ledger 并发更新冲突'),
            ['payout_id' => $payoutId],
        );
    }

    /** @return array<string, mixed>|null */
    private function findBySnapshot(string $snapshotId): ?array
    {
        if ($this->payouts !== null) {
            $payoutId = $this->bySnapshot[$snapshotId] ?? null;
            return $payoutId !== null ? ($this->payouts[$payoutId] ?? null) : null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorPayoutRecord::schema_fields_SNAPSHOT_ID, $snapshotId)
            ->find()
            ->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    private function findModel(string $payoutId): ?VendorPayoutRecord
    {
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorPayoutRecord::schema_fields_PAYOUT_ID, $payoutId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function assertReplay(
        array $existing,
        string $requestHash,
        ?Throwable $previous = null,
    ): array {
        if (hash_equals((string) $existing['request_hash'], $requestHash)) {
            return $existing;
        }
        throw new VendorConflictException(
            self::ERROR_IDEMPOTENCY,
            __('同一 snapshot 的 payout 请求参数冲突'),
            ['snapshot_id' => $existing['snapshot_id'], 'payout_id' => $existing['payout_id']],
            0,
            $previous,
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function recordData(array $row): array
    {
        return [
            VendorPayoutRecord::schema_fields_PAYOUT_ID => $row['payout_id'],
            VendorPayoutRecord::schema_fields_SNAPSHOT_ID => $row['snapshot_id'],
            VendorPayoutRecord::schema_fields_VENDOR_ID => $row['vendor_id'],
            VendorPayoutRecord::schema_fields_WEBSITE_ID => $row['website_id'],
            VendorPayoutRecord::schema_fields_STORE_ID => $row['store_id'],
            VendorPayoutRecord::schema_fields_STORE_MODE => $row['store_mode_snapshot'],
            VendorPayoutRecord::schema_fields_ENVIRONMENT => $row['environment'],
            VendorPayoutRecord::schema_fields_CURRENCY => $row['currency'],
            VendorPayoutRecord::schema_fields_AMOUNT_MINOR => $row['amount_minor'],
            VendorPayoutRecord::schema_fields_REVERSED_MINOR => $row['reversed_minor'],
            VendorPayoutRecord::schema_fields_NET_MINOR => $row['net_minor'],
            VendorPayoutRecord::schema_fields_STATUS => $row['status'],
            VendorPayoutRecord::schema_fields_ACCOUNT_REF => $row['account_ref'],
            VendorPayoutRecord::schema_fields_LEGAL_ENTITY => $row['legal_entity'],
            VendorPayoutRecord::schema_fields_IDEMPOTENCY_KEY => $row['idempotency_key'],
            VendorPayoutRecord::schema_fields_REQUEST_HASH => $row['request_hash'],
            VendorPayoutRecord::schema_fields_LEDGER_VERSION => $row['ledger_version'],
            VendorPayoutRecord::schema_fields_CAS_TOKEN => $row['cas_token'],
            VendorPayoutRecord::schema_fields_CREATED_AT => $row['created_at'],
            VendorPayoutRecord::schema_fields_UPDATED_AT => $row['updated_at'],
        ];
    }

    private function newRecord(): VendorPayoutRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(VendorPayoutRecord::class, [], false);
    }
}
