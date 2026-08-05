<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Framework\Manager\ObjectManager;

/**
 * One-time data migration for usage rows created before request-key auditing.
 *
 * SchemaDiff first adds a nullable request_key and its UNIQUE index. Historical
 * rows therefore cannot block SQLite/MySQL schema synchronization: every old
 * value starts as NULL. This service then assigns one deterministic key per
 * request group while preserving every original row and request_id.
 */
final class UsageAuditLegacyBackfill
{
    private const PAGE_SIZE = 500;

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,array<string,mixed>> updates keyed by usage record ID
     */
    public function plan(array $rows): array
    {
        /** @var array<string,list<array<string,mixed>>> $groups */
        $groups = [];
        /** @var list<array<string,mixed>> $missing */
        $missing = [];
        foreach ($rows as $row) {
            $id = (int)($row[UsageRecord::schema_fields_ID] ?? 0);
            if ($id < 1) {
                throw new \InvalidArgumentException('AI provider usage legacy row ID is invalid.');
            }
            $requestId = trim((string)($row[UsageRecord::schema_fields_REQUEST_ID] ?? ''));
            if ($requestId === '') {
                $missing[] = $row;
                continue;
            }
            $groups[$requestId][] = $row;
        }
        ksort($groups, SORT_STRING);

        $updates = [];
        foreach ($groups as $requestId => $group) {
            usort(
                $group,
                static fn(array $left, array $right): int => (int)$left[UsageRecord::schema_fields_ID]
                    <=> (int)$right[UsageRecord::schema_fields_ID],
            );
            $signatures = [];
            foreach ($group as $row) {
                $signatures[$this->identitySignature($row)] = true;
            }
            $conflict = count($signatures) > 1;
            foreach ($group as $offset => $row) {
                $id = (int)$row[UsageRecord::schema_fields_ID];
                if ($offset === 0) {
                    $identityStatus = $conflict
                        ? UsageRecord::REQUEST_IDENTITY_LEGACY_CONFLICT
                        : UsageRecord::REQUEST_IDENTITY_CANONICAL;
                    $requestKey = hash('sha256', $requestId);
                } else {
                    $identityStatus = $conflict
                        ? UsageRecord::REQUEST_IDENTITY_LEGACY_CONFLICT_DUPLICATE
                        : UsageRecord::REQUEST_IDENTITY_LEGACY_DUPLICATE;
                    $requestKey = null;
                }
                $updates[$id] = [
                    UsageRecord::schema_fields_REQUEST_KEY => $requestKey,
                    UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS => $identityStatus,
                    // Every historical row predates the new marker and was
                    // already debited by the previous synchronous recordUsage.
                    UsageRecord::schema_fields_BALANCE_APPLIED => 1,
                ];
            }
        }

        foreach ($missing as $row) {
            $id = (int)$row[UsageRecord::schema_fields_ID];
            $updates[$id] = [
                UsageRecord::schema_fields_REQUEST_KEY => null,
                UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS => UsageRecord::REQUEST_IDENTITY_LEGACY_MISSING,
                UsageRecord::schema_fields_BALANCE_APPLIED => 1,
            ];
        }
        ksort($updates, SORT_NUMERIC);

        return $updates;
    }

    /**
     * Return only rows that still need the deterministic group update.
     *
     * A setup process can stop after updating the canonical row but before its
     * duplicate siblings. Replanning the complete request group preserves the
     * original canonical owner; already-applied rows are verified and skipped.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public function planUnapplied(array $rows): array
    {
        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int)($row[UsageRecord::schema_fields_ID] ?? 0)] = $row;
        }

        $pending = [];
        foreach ($this->plan($rows) as $id => $expected) {
            $row = $rowsById[$id];
            $currentStatus = $row[UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS] ?? null;
            if ($currentStatus === null) {
                if (($row[UsageRecord::schema_fields_REQUEST_KEY] ?? null) !== null) {
                    throw new \RuntimeException(
                        'AI provider usage legacy backfill found an unclassified owned request key.',
                    );
                }
                $pending[$id] = $expected;
                continue;
            }

            if (
                $currentStatus !== $expected[UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS]
                || ($row[UsageRecord::schema_fields_REQUEST_KEY] ?? null)
                    !== $expected[UsageRecord::schema_fields_REQUEST_KEY]
                || (int)($row[UsageRecord::schema_fields_BALANCE_APPLIED] ?? 0)
                    !== (int)$expected[UsageRecord::schema_fields_BALANCE_APPLIED]
            ) {
                throw new \RuntimeException(
                    'AI provider usage legacy backfill found inconsistent prior classification.',
                );
            }
        }

        return $pending;
    }

    /** @return array{updated:int,canonical:int,duplicates:int,conflicts:int,missing:int} */
    public function backfill(): array
    {
        $result = [
            'updated' => 0,
            'canonical' => 0,
            'duplicates' => 0,
            'conflicts' => 0,
            'missing' => 0,
        ];
        $page = 1;
        $pendingGroup = [];
        $pendingRequestId = null;
        do {
            /** @var UsageRecord $model */
            $model = ObjectManager::getInstance(UsageRecord::class, [], false);
            $collection = $model->clear()
                ->limit(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE)
                ->order(UsageRecord::schema_fields_REQUEST_ID, 'ASC')
                ->order(UsageRecord::schema_fields_ID, 'ASC')
                ->select()
                ->fetch();
            $items = $collection->getItems();
            foreach ($items as $record) {
                if (!$record instanceof UsageRecord) {
                    continue;
                }
                $row = $record->getData();
                $requestId = trim((string)($row[UsageRecord::schema_fields_REQUEST_ID] ?? ''));
                if ($requestId === '') {
                    if ($pendingGroup !== []) {
                        $this->applyUpdates($this->planUnapplied($pendingGroup), $result);
                        $pendingGroup = [];
                        $pendingRequestId = null;
                    }
                    $this->applyUpdates($this->planUnapplied([$row]), $result);
                    continue;
                }
                if (
                    $pendingGroup !== []
                    && $pendingRequestId !== $requestId
                ) {
                    $this->applyUpdates($this->planUnapplied($pendingGroup), $result);
                    $pendingGroup = [];
                }
                $pendingRequestId = $requestId;
                $pendingGroup[] = $row;
            }
            ++$page;
        } while (count($items) === self::PAGE_SIZE);

        if ($pendingGroup !== []) {
            $this->applyUpdates($this->planUnapplied($pendingGroup), $result);
        }

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $updates
     * @param array{updated:int,canonical:int,duplicates:int,conflicts:int,missing:int} $result
     */
    private function applyUpdates(array $updates, array &$result): void
    {
        foreach ($updates as $id => $update) {
            /** @var UsageRecord $updateModel */
            $updateModel = ObjectManager::getInstance(UsageRecord::class, [], false);
            $updateQuery = $updateModel->getQuery(false);
            $updateQuery
                ->where(UsageRecord::schema_fields_ID, $id)
                ->where(UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS, null, 'IS NULL')
                ->update($update);
            $statement = $updateQuery->PDOStatement ?? null;
            $bindings = $updateQuery->bound_values ?? null;
            if (!$statement instanceof \PDOStatement || !is_array($bindings)) {
                $updateQuery->clearQuery();
                throw new \RuntimeException(
                    'AI provider usage legacy backfill statement is unavailable.',
                );
            }
            try {
                if (!$statement->execute($bindings)) {
                    throw new \RuntimeException(
                        'AI provider usage legacy backfill execution failed.',
                    );
                }
                $changed = $statement->rowCount() === 1;
            } finally {
                $updateQuery->clearQuery();
            }
            if (!$changed) {
                throw new \RuntimeException('AI provider usage legacy backfill lost its row claim.');
            }
            ++$result['updated'];
            $status = (string)$update[UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS];
            if ($status === UsageRecord::REQUEST_IDENTITY_CANONICAL) {
                ++$result['canonical'];
            } elseif ($status === UsageRecord::REQUEST_IDENTITY_LEGACY_DUPLICATE) {
                ++$result['duplicates'];
            } elseif (
                $status === UsageRecord::REQUEST_IDENTITY_LEGACY_CONFLICT
                || $status === UsageRecord::REQUEST_IDENTITY_LEGACY_CONFLICT_DUPLICATE
            ) {
                ++$result['conflicts'];
            } elseif ($status === UsageRecord::REQUEST_IDENTITY_LEGACY_MISSING) {
                ++$result['missing'];
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function identitySignature(array $row): string
    {
        $identity = [];
        foreach ([
            UsageRecord::schema_fields_ACCOUNT_ID,
            UsageRecord::schema_fields_PROVIDER_CODE,
            UsageRecord::schema_fields_MODEL_CODE,
            UsageRecord::schema_fields_REQUEST_TYPE,
            UsageRecord::schema_fields_PROMPT_TOKENS,
            UsageRecord::schema_fields_COMPLETION_TOKENS,
            UsageRecord::schema_fields_TOTAL_TOKENS,
            UsageRecord::schema_fields_INPUT_COST,
            UsageRecord::schema_fields_OUTPUT_COST,
            UsageRecord::schema_fields_TOTAL_COST,
            UsageRecord::schema_fields_CURRENCY,
            UsageRecord::schema_fields_STATUS,
        ] as $field) {
            $value = $row[$field] ?? null;
            $identity[$field] = is_float($value)
                ? sprintf('%.12F', $value)
                : (string)$value;
        }

        return hash(
            'sha256',
            json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
