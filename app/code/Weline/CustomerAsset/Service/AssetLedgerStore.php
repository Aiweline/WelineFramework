<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Weline\CustomerAsset\Model\AssetLedgerEntry;

final class AssetLedgerStore
{
    /** @var array<string, AssetLedgerEntry> */
    private array $byEntry = [];

    /** @var array<string, string> event_id => entry_id */
    private array $byEvent = [];

    public static function forTesting(): self
    {
        return new self();
    }

    public function append(AssetLedgerEntry $entry): void
    {
        if (isset($this->byEvent[$entry->eventId])) {
            throw new CustomerAssetConflictException(
                CustomerAssetService::ERROR_DUPLICATE_EVENT,
                'duplicate ledger event',
                ['event_id' => $entry->eventId],
            );
        }
        $this->byEntry[$entry->entryId] = $entry;
        $this->byEvent[$entry->eventId] = $entry->entryId;
    }

    public function getByEvent(string $eventId): ?AssetLedgerEntry
    {
        $id = $this->byEvent[$eventId] ?? null;

        return $id !== null ? ($this->byEntry[$id] ?? null) : null;
    }

    /**
     * @return list<AssetLedgerEntry>
     */
    public function listForAccount(string $accountId): array
    {
        $out = [];
        foreach ($this->byEntry as $entry) {
            if ($entry->accountId === $accountId) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    public function count(): int
    {
        return count($this->byEntry);
    }
}
