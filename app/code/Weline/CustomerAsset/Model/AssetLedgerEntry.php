<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Model;

/**
 * 不可变 ledger 行；唯一 event_id。
 */
final class AssetLedgerEntry
{
    public const TYPE_CREDIT = 'credit';
    public const TYPE_RESERVE = 'reserve';
    public const TYPE_RELEASE = 'release';
    public const TYPE_COMMIT = 'commit';
    public const TYPE_RETURN = 'return';

    public function __construct(
        public readonly string $entryId,
        public readonly string $accountId,
        public readonly string $eventId,
        public readonly string $type,
        public readonly int $amountMinor,
        public readonly int $balanceAfterAvailable,
        public readonly int $balanceAfterReserved,
        public readonly int $accountVersion,
        public readonly array $meta = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entry_id' => $this->entryId,
            'account_id' => $this->accountId,
            'event_id' => $this->eventId,
            'type' => $this->type,
            'amount_minor' => $this->amountMinor,
            'balance_after_available' => $this->balanceAfterAvailable,
            'balance_after_reserved' => $this->balanceAfterReserved,
            'account_version' => $this->accountVersion,
            'meta' => $this->meta,
        ];
    }
}
