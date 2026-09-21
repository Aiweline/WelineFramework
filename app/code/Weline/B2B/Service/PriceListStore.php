<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Throwable;
use Weline\B2B\Model\PriceList;
use Weline\B2B\Model\PriceListItemRecord;
use Weline\B2B\Model\PriceListRecord;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

/** Durable immutable price-list revisions with an explicit memory test seam. */
final class PriceListStore
{
    /** @var array<string,array<int,PriceList>>|null */
    private ?array $rows = null;

    /** @var (\Closure(): PriceListRecord)|null */
    private readonly ?\Closure $listFactory;

    /** @var (\Closure(): PriceListItemRecord)|null */
    private readonly ?\Closure $itemFactory;

    /**
     * @param (callable(): PriceListRecord)|null $listFactory
     * @param (callable(): PriceListItemRecord)|null $itemFactory
     */
    public function __construct(
        ?callable $listFactory = null,
        ?callable $itemFactory = null,
        private ?DatabaseTransactionRunnerInterface $transactions = null,
        bool $useMemory = false,
    ) {
        $this->listFactory = $listFactory !== null ? \Closure::fromCallable($listFactory) : null;
        $this->itemFactory = $itemFactory !== null ? \Closure::fromCallable($itemFactory) : null;
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

    public function connection(): ConnectionFactory
    {
        return $this->newListRecord()->getConnection();
    }

    public function put(PriceList $list): void
    {
        if ($this->rows !== null) {
            $existing = $this->rows[$list->listId][$list->version] ?? null;
            if ($existing !== null && $this->fingerprint($existing) !== $this->fingerprint($list)) {
                throw $this->revisionConflict($list);
            }
            $this->rows[$list->listId][$list->version] = $list;
            return;
        }

        $this->transactionRunner()->run(
            $this->connection(),
            fn (): null => $this->putDurable($list),
        );
    }

    public function get(string $listId): ?PriceList
    {
        $listId = trim($listId);
        if ($listId === '') {
            return null;
        }
        if ($this->rows !== null) {
            $versions = $this->rows[$listId] ?? [];
            if ($versions === []) {
                return null;
            }
            krsort($versions, SORT_NUMERIC);
            return reset($versions) ?: null;
        }
        $headers = $this->newListRecord()->clear()
            ->where(PriceListRecord::schema_fields_LIST_ID, $listId)
            ->select()
            ->fetchArray();
        if ($headers === []) {
            return null;
        }
        usort(
            $headers,
            static fn (array $a, array $b): int =>
                ((int)$b[PriceListRecord::schema_fields_VERSION])
                <=> ((int)$a[PriceListRecord::schema_fields_VERSION]),
        );
        return $this->hydrate($headers[0]);
    }

    /** @return list<PriceList> */
    public function activeForGroup(
        string $groupId,
        int $websiteId,
        ?string $channelId = null,
    ): array {
        if ($this->rows !== null) {
            $out = [];
            foreach ($this->rows as $versions) {
                foreach ($versions as $list) {
                    if ($list->active
                        && $list->groupId === $groupId
                        && $list->websiteId === $websiteId
                        && ($list->channelId === null || $list->channelId === $channelId)
                    ) {
                        $out[] = $list;
                    }
                }
            }
        } else {
            $headers = $this->newListRecord()->clear()
                ->where(PriceListRecord::schema_fields_GROUP_ID, trim($groupId))
                ->where(PriceListRecord::schema_fields_WEBSITE_ID, $websiteId)
                ->where(PriceListRecord::schema_fields_ACTIVE, 1)
                ->select()
                ->fetchArray();
            $out = [];
            foreach ($headers as $header) {
                $candidateChannel = $header[PriceListRecord::schema_fields_CHANNEL_ID] ?? null;
                if ($candidateChannel !== null
                    && $candidateChannel !== ''
                    && (string)$candidateChannel !== (string)$channelId
                ) {
                    continue;
                }
                $out[] = $this->hydrate($header);
            }
        }

        usort($out, static function (PriceList $a, PriceList $b): int {
            $scope = ((int)($b->channelId !== null)) <=> ((int)($a->channelId !== null));
            return $scope !== 0 ? $scope : ($b->version <=> $a->version);
        });
        return $out;
    }

    public function countRevisions(): int
    {
        if ($this->rows !== null) {
            return array_sum(array_map('count', $this->rows));
        }
        return count($this->newListRecord()->clear()->select()->fetchArray());
    }

    /**
     * True when any active price-list revision for the website contains the SKU
     * (flat or qty-tier rows both count as configured wholesale pricing).
     */
    public function skuHasActiveTiers(string $sku, int $websiteId): bool
    {
        $sku = trim($sku);
        if ($sku === '' || $websiteId < 0) {
            return false;
        }

        if ($this->rows !== null) {
            foreach ($this->rows as $versions) {
                foreach ($versions as $list) {
                    if ($list->active
                        && $list->websiteId === $websiteId
                        && $list->hasSku($sku)
                    ) {
                        return true;
                    }
                }
            }

            return false;
        }

        $cacheKey = 'b2b.sku_active_tiers.' . $websiteId . '|' . strtolower($sku);
        if (RequestContext::isInitialized()) {
            $cached = RequestContext::get($cacheKey);
            if (is_bool($cached)) {
                return $cached;
            }
        }

        $active = false;
        try {
            $items = $this->newItemRecord()->clear()
                ->where(PriceListItemRecord::schema_fields_SKU, $sku)
                ->select()
                ->fetchArray();
            if ($items !== []) {
                $listIds = [];
                foreach ($items as $item) {
                    $listId = trim((string)($item[PriceListItemRecord::schema_fields_LIST_ID] ?? ''));
                    if ($listId !== '') {
                        $listIds[$listId] = $listId;
                    }
                }
                if ($listIds !== []) {
                    $headers = $this->newListRecord()->clear()
                        ->where(PriceListRecord::schema_fields_LIST_ID, array_values($listIds), 'in')
                        ->where(PriceListRecord::schema_fields_WEBSITE_ID, $websiteId)
                        ->where(PriceListRecord::schema_fields_ACTIVE, 1)
                        ->select()
                        ->fetchArray();
                    $activeKeys = [];
                    foreach ($headers as $header) {
                        $listId = trim((string)($header[PriceListRecord::schema_fields_LIST_ID] ?? ''));
                        $version = (int)($header[PriceListRecord::schema_fields_VERSION] ?? 0);
                        if ($listId !== '' && $version > 0) {
                            $activeKeys[$listId . '@' . $version] = true;
                        }
                    }
                    if ($activeKeys !== []) {
                        foreach ($items as $item) {
                            $listId = trim((string)($item[PriceListItemRecord::schema_fields_LIST_ID] ?? ''));
                            $version = (int)($item[PriceListItemRecord::schema_fields_LIST_VERSION] ?? 0);
                            if ($listId !== '' && isset($activeKeys[$listId . '@' . $version])) {
                                $active = true;
                                break;
                            }
                        }
                    }
                }
            }
        } catch (Throwable) {
            $active = false;
        }
        if (RequestContext::isInitialized()) {
            RequestContext::set($cacheKey, $active);
        }

        return $active;
    }

    private function putDurable(PriceList $list): null
    {
        $existing = $this->findHeader($list->listId, $list->version);
        if ($existing !== null) {
            if ($this->fingerprint($this->hydrate($existing->getData())) !== $this->fingerprint($list)) {
                throw $this->revisionConflict($list);
            }
            return null;
        }

        try {
            $this->newListRecord()->clear()->setData([
                PriceListRecord::schema_fields_LIST_ID => $list->listId,
                PriceListRecord::schema_fields_GROUP_ID => $list->groupId,
                PriceListRecord::schema_fields_WEBSITE_ID => $list->websiteId,
                PriceListRecord::schema_fields_VERSION => $list->version,
                PriceListRecord::schema_fields_CHANNEL_ID => $list->channelId,
                PriceListRecord::schema_fields_ACTIVE => $list->active ? 1 : 0,
                PriceListRecord::schema_fields_CREATED_AT => gmdate('Y-m-d H:i:s'),
            ])->save();
            foreach ($list->itemRows() as $row) {
                $this->newItemRecord()->clear()->setData([
                    PriceListItemRecord::schema_fields_LIST_ID => $list->listId,
                    PriceListItemRecord::schema_fields_LIST_VERSION => $list->version,
                    PriceListItemRecord::schema_fields_SKU => $row['sku'],
                    PriceListItemRecord::schema_fields_MIN_QTY => $row['min_qty'],
                    PriceListItemRecord::schema_fields_AMOUNT_MINOR => $row['amount_minor'],
                ])->save();
            }
        } catch (Throwable $exception) {
            $winner = $this->findHeader($list->listId, $list->version);
            if ($winner !== null
                && $this->fingerprint($this->hydrate($winner->getData())) === $this->fingerprint($list)
            ) {
                return null;
            }
            throw $exception;
        }
        return null;
    }

    private function findHeader(string $listId, int $version): ?PriceListRecord
    {
        $model = $this->newListRecord();
        $model->clear()
            ->where(PriceListRecord::schema_fields_LIST_ID, trim($listId))
            ->where(PriceListRecord::schema_fields_VERSION, $version)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /** @param array<string,mixed> $header */
    private function hydrate(array $header): PriceList
    {
        $listId = (string)$header[PriceListRecord::schema_fields_LIST_ID];
        $version = (int)$header[PriceListRecord::schema_fields_VERSION];
        $items = $this->newItemRecord()->clear()
            ->where(PriceListItemRecord::schema_fields_LIST_ID, $listId)
            ->where(PriceListItemRecord::schema_fields_LIST_VERSION, $version)
            ->select()
            ->fetchArray();
        $tiers = [];
        foreach ($items as $item) {
            $sku = (string)$item[PriceListItemRecord::schema_fields_SKU];
            $minQty = max(1, (int)($item[PriceListItemRecord::schema_fields_MIN_QTY] ?? 1));
            $tiers[$sku][$minQty] = (int)$item[PriceListItemRecord::schema_fields_AMOUNT_MINOR];
        }
        foreach ($tiers as $sku => $byMin) {
            ksort($tiers[$sku], SORT_NUMERIC);
        }
        ksort($tiers, SORT_STRING);
        $channel = $header[PriceListRecord::schema_fields_CHANNEL_ID] ?? null;
        return new PriceList(
            $listId,
            (string)$header[PriceListRecord::schema_fields_GROUP_ID],
            (int)$header[PriceListRecord::schema_fields_WEBSITE_ID],
            $version,
            $tiers,
            $channel !== null && $channel !== '' ? (string)$channel : null,
            (int)$header[PriceListRecord::schema_fields_ACTIVE] === 1,
        );
    }

    private function fingerprint(PriceList $list): string
    {
        $tiers = $list->skuQtyTiers;
        ksort($tiers, SORT_STRING);
        foreach ($tiers as $sku => $byMin) {
            ksort($tiers[$sku], SORT_NUMERIC);
        }
        return hash('sha256', (string)json_encode([
            'list_id' => $list->listId,
            'group_id' => $list->groupId,
            'website_id' => $list->websiteId,
            'version' => $list->version,
            'channel_id' => $list->channelId,
            'active' => $list->active,
            'sku_qty_tiers' => $tiers,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function revisionConflict(PriceList $list): B2BConflictException
    {
        return new B2BConflictException(
            'b2b_price_list_revision_immutable',
            __('B2B price list revision 不可覆盖：%{1}@%{2}', [$list->listId, $list->version]),
            ['list_id' => $list->listId, 'version' => $list->version],
        );
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

    private function newListRecord(): PriceListRecord
    {
        return $this->listFactory !== null
            ? ($this->listFactory)()
            : ObjectManager::create(PriceListRecord::class, [], false);
    }

    private function newItemRecord(): PriceListItemRecord
    {
        return $this->itemFactory !== null
            ? ($this->itemFactory)()
            : ObjectManager::create(PriceListItemRecord::class, [], false);
    }
}
