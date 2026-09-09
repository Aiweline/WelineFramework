<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Throwable;
use Weline\B2B\Model\B2BOrderPriceSnapshot;
use Weline\B2B\Model\B2BOrderPriceSnapshotRecord;
use Weline\Framework\Manager\ObjectManager;

/** Durable write-once Order snapshot store. */
final class B2BOrderSnapshotStore
{
    public const ERROR_IMMUTABLE = 'b2b_order_snapshot_immutable';

    /** @var array<string, B2BOrderPriceSnapshot>|null */
    private ?array $rows = null;

    /** @var array<string,string> */
    private array $byToken = [];

    /** @var (\Closure(): B2BOrderPriceSnapshotRecord)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): B2BOrderPriceSnapshotRecord)|null $recordFactory */
    public function __construct(
        ?callable $recordFactory = null,
        bool $useMemory = false,
    ) {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->rows = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    public function isMemory(): bool
    {
        return $this->rows !== null;
    }

    public function put(B2BOrderPriceSnapshot $snapshot): void
    {
        if ($this->rows !== null) {
            if (isset($this->rows[$snapshot->orderRef]) || isset($this->byToken[$snapshot->tokenId])) {
                throw $this->immutable($snapshot->orderRef, $snapshot->tokenId);
            }
            $this->rows[$snapshot->orderRef] = $snapshot;
            $this->byToken[$snapshot->tokenId] = $snapshot->orderRef;
            return;
        }

        if ($this->findModelByOrder($snapshot->orderRef) !== null
            || $this->findModelByToken($snapshot->tokenId) !== null
        ) {
            throw $this->immutable($snapshot->orderRef, $snapshot->tokenId);
        }
        try {
            $this->newRecord()->clear()->setData($this->recordData($snapshot))->save();
        } catch (Throwable $exception) {
            if ($this->findModelByOrder($snapshot->orderRef) !== null
                || $this->findModelByToken($snapshot->tokenId) !== null
            ) {
                throw $this->immutable($snapshot->orderRef, $snapshot->tokenId, $exception);
            }
            throw $exception;
        }
    }

    public function get(string $orderRef): ?B2BOrderPriceSnapshot
    {
        $orderRef = trim($orderRef);
        if ($orderRef === '') {
            return null;
        }
        if ($this->rows !== null) {
            return $this->rows[$orderRef] ?? null;
        }
        $model = $this->findModelByOrder($orderRef);
        return $model !== null ? $this->hydrate($model->getData()) : null;
    }

    public function getByToken(string $tokenId): ?B2BOrderPriceSnapshot
    {
        $tokenId = trim($tokenId);
        if ($tokenId === '') {
            return null;
        }
        if ($this->rows !== null) {
            $orderRef = $this->byToken[$tokenId] ?? null;
            return $orderRef !== null ? ($this->rows[$orderRef] ?? null) : null;
        }
        $model = $this->findModelByToken($tokenId);
        return $model !== null ? $this->hydrate($model->getData()) : null;
    }

    /** @param array<string,mixed> $_ignored */
    public function update(string $orderRef, array $_ignored): never
    {
        throw $this->immutable($orderRef, '');
    }

    public function count(): int
    {
        if ($this->rows !== null) {
            return count($this->rows);
        }
        return count($this->newRecord()->clear()->select()->fetchArray());
    }

    /** @return array<string,mixed> */
    private function recordData(B2BOrderPriceSnapshot $snapshot): array
    {
        return [
            B2BOrderPriceSnapshotRecord::schema_fields_ORDER_REF => $snapshot->orderRef,
            B2BOrderPriceSnapshotRecord::schema_fields_TOKEN_ID => $snapshot->tokenId,
            B2BOrderPriceSnapshotRecord::schema_fields_CUSTOMER_ID => $snapshot->customerId,
            B2BOrderPriceSnapshotRecord::schema_fields_WEBSITE_ID => $snapshot->websiteId,
            B2BOrderPriceSnapshotRecord::schema_fields_SKU => $snapshot->sku,
            B2BOrderPriceSnapshotRecord::schema_fields_RETAIL_AMOUNT_MINOR => $snapshot->retailAmountMinor,
            B2BOrderPriceSnapshotRecord::schema_fields_AMOUNT_MINOR => $snapshot->amountMinor,
            B2BOrderPriceSnapshotRecord::schema_fields_SOURCE => $snapshot->source,
            B2BOrderPriceSnapshotRecord::schema_fields_GROUP_ID => $snapshot->groupId,
            B2BOrderPriceSnapshotRecord::schema_fields_PRICE_LIST_ID => $snapshot->priceListId,
            B2BOrderPriceSnapshotRecord::schema_fields_VERSION => $snapshot->version,
            B2BOrderPriceSnapshotRecord::schema_fields_CHANNEL_ID => $snapshot->channelId,
            B2BOrderPriceSnapshotRecord::schema_fields_RULE_STACK_JSON => json_encode(
                $snapshot->ruleStack,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            B2BOrderPriceSnapshotRecord::schema_fields_PAYLOAD_HASH => $snapshot->hash,
            B2BOrderPriceSnapshotRecord::schema_fields_CREATED_AT_EPOCH => $snapshot->createdAtEpoch,
            B2BOrderPriceSnapshotRecord::schema_fields_CREATED_AT => gmdate(
                'Y-m-d H:i:s',
                $snapshot->createdAtEpoch,
            ),
        ];
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): B2BOrderPriceSnapshot
    {
        $rules = json_decode(
            (string)($row[B2BOrderPriceSnapshotRecord::schema_fields_RULE_STACK_JSON] ?? '[]'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        return new B2BOrderPriceSnapshot(
            orderRef: (string)$row[B2BOrderPriceSnapshotRecord::schema_fields_ORDER_REF],
            tokenId: (string)$row[B2BOrderPriceSnapshotRecord::schema_fields_TOKEN_ID],
            customerId: (string)$row[B2BOrderPriceSnapshotRecord::schema_fields_CUSTOMER_ID],
            websiteId: (int)$row[B2BOrderPriceSnapshotRecord::schema_fields_WEBSITE_ID],
            sku: (string)$row[B2BOrderPriceSnapshotRecord::schema_fields_SKU],
            retailAmountMinor: (int)$row[B2BOrderPriceSnapshotRecord::schema_fields_RETAIL_AMOUNT_MINOR],
            amountMinor: (int)$row[B2BOrderPriceSnapshotRecord::schema_fields_AMOUNT_MINOR],
            source: (string)$row[B2BOrderPriceSnapshotRecord::schema_fields_SOURCE],
            groupId: $this->optionalString($row, B2BOrderPriceSnapshotRecord::schema_fields_GROUP_ID),
            priceListId: $this->optionalString(
                $row,
                B2BOrderPriceSnapshotRecord::schema_fields_PRICE_LIST_ID,
            ),
            version: $this->optionalInt($row, B2BOrderPriceSnapshotRecord::schema_fields_VERSION),
            channelId: $this->optionalString($row, B2BOrderPriceSnapshotRecord::schema_fields_CHANNEL_ID),
            ruleStack: is_array($rules) ? array_values($rules) : [],
            hash: (string)$row[B2BOrderPriceSnapshotRecord::schema_fields_PAYLOAD_HASH],
            createdAtEpoch: (int)$row[B2BOrderPriceSnapshotRecord::schema_fields_CREATED_AT_EPOCH],
        );
    }

    private function findModelByOrder(string $orderRef): ?B2BOrderPriceSnapshotRecord
    {
        $model = $this->newRecord();
        $model->clear()
            ->where(B2BOrderPriceSnapshotRecord::schema_fields_ORDER_REF, trim($orderRef))
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    private function findModelByToken(string $tokenId): ?B2BOrderPriceSnapshotRecord
    {
        $model = $this->newRecord();
        $model->clear()
            ->where(B2BOrderPriceSnapshotRecord::schema_fields_TOKEN_ID, trim($tokenId))
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    private function immutable(
        string $orderRef,
        string $tokenId,
        ?Throwable $previous = null,
    ): B2BConflictException {
        return new B2BConflictException(
            self::ERROR_IMMUTABLE,
            __('B2B Order snapshot 不可覆盖：%{1}', [$orderRef]),
            ['order_ref' => $orderRef, 'token_id' => $tokenId],
            0,
            $previous,
        );
    }

    private function newRecord(): B2BOrderPriceSnapshotRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(B2BOrderPriceSnapshotRecord::class, [], false);
    }

    /** @param array<string,mixed> $row */
    private function optionalString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        return $value !== null && $value !== '' ? (string)$value : null;
    }

    /** @param array<string,mixed> $row */
    private function optionalInt(array $row, string $field): ?int
    {
        $value = $row[$field] ?? null;
        return $value !== null && $value !== '' ? (int)$value : null;
    }
}
