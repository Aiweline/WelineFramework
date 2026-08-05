<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\ProductDirectCatalogRead;
use Weline\Search\Api\ProductDirectCatalogReaderInterface;
use Weline\Search\Api\SearchIndexStorageInterface;
use Weline\Search\Api\SearchShardRegistryInterface;
use Weline\Search\Model\SearchShardKey;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * Trusted Scope Search query + Product current degraded read（P3C-002）.
 */
final class SearchQueryService
{
    public const CAPABILITY = 'search';
    public const SOURCE_INDEX = 'search_index';
    public const SOURCE_DIRECT = 'product_direct';
    public const SOURCE_DEGRADED = 'product_direct_degraded';

    public function __construct(
        private readonly SearchIndexStorageInterface $store,
        private readonly SearchShardRegistryInterface $registry,
        private readonly ProductDirectCatalogReaderInterface $directReader,
        private readonly SearchDegradeMarker $degrade,
        private readonly SearchRolloutGate $rollout,
        private readonly SearchAliasStore $alias,
        private bool $indexForcedDown = false,
    ) {
    }

    public static function forTesting(
        ?SearchIndexBuilder $builder = null,
        ?ProductDirectCatalogReaderInterface $direct = null,
        ?SearchRolloutGate $rollout = null,
        ?SearchDegradeMarker $degrade = null,
        ?SearchAliasStore $alias = null,
    ): self {
        $builder ??= SearchIndexBuilder::forTesting();
        $gate = $rollout ?? SearchRolloutGate::forTestingConfiguration();
        $alias ??= SearchAliasStore::forTesting();
        foreach ($builder->registry()->getRegisteredShardKeys() as $shardKey) {
            $websiteId = SearchShardKey::parse($shardKey);
            $generation = (int)($builder->store()->watermark($websiteId)['active_generation'] ?? 0);
            if ($generation < 1) {
                continue;
            }
            $state = $alias->state($websiteId);
            $alias->compareAndSwap(
                $websiteId,
                $state['alias'],
                $state['generation'],
                $state['version'],
                SearchAliasStore::ALIAS_INDEX,
                $generation,
            );
        }

        return new self(
            $builder->store(),
            $builder->registry(),
            $direct ?? ArrayProductDirectCatalogReader::forTesting(),
            $degrade ?? SearchDegradeMarker::forTesting(),
            $gate,
            $alias,
        );
    }

    public function rollout(): SearchRolloutGate
    {
        return $this->rollout;
    }

    public function degrade(): SearchDegradeMarker
    {
        return $this->degrade;
    }

    public function store(): SearchIndexStorageInterface
    {
        return $this->store;
    }

    public function alias(): SearchAliasStore
    {
        return $this->alias;
    }

    /**
     * Isolated tests only. Production outages are represented by registry/store
     * state and the durable marker, never by a storefront mutation operation.
     */
    public function forceIndexDown(bool $down = true): void
    {
        $this->indexForcedDown = $down;
    }

    /**
     * @param array{
     *   website_id:int,
     *   store_id:int,
     *   channel_id:int,
     *   locale:string,
     *   currency:string,
     *   q?:string
     * } $query
     * @return array<string,mixed>
     */
    public function search(array $query): array
    {
        $query = $this->normalizeQuery($query);
        $websiteId = $query['website_id'];
        $mode = $this->rollout->mode(self::CAPABILITY);
        if (!$this->shouldUseIndex($query)) {
            return $this->directResult(
                $query,
                $this->readDirect($query),
                self::SOURCE_DIRECT,
                false,
                null,
                null,
                true,
                $mode,
            );
        }

        $activeMarker = $this->degrade->get($websiteId);
        if (($activeMarker['active'] ?? false) === true) {
            return $this->directResult(
                $query,
                $this->readDirect($query),
                self::SOURCE_DEGRADED,
                true,
                (string)($activeMarker['reason'] ?? 'degrade_marker_active'),
                $activeMarker,
                true,
                $mode,
            );
        }

        if ($this->indexForcedDown) {
            return $this->degradedResult($query, 'index_forced_down', $mode);
        }

        try {
            if (!$this->registry->isReady($websiteId)) {
                return $this->degradedResult($query, 'index_not_ready', $mode);
            }
            $hits = $this->searchIndex($query);
            $watermark = $this->store->watermark($websiteId);

            return $this->result(
                $query,
                self::SOURCE_INDEX,
                false,
                null,
                $hits,
                $mode,
                [
                    'index_incremental_watermark' => (int)(
                        $watermark['incremental_watermark'] ?? 0
                    ),
                    'direct_source_watermark' => null,
                    'direct_snapshot_hash' => null,
                    'direct_document_count' => null,
                    'direct_match_count' => null,
                    'degrade_marker' => null,
                    'degrade_marker_persisted' => true,
                ],
            );
        } catch (SearchQueryException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return $this->degradedResult(
                $query,
                'index_read_failed',
                $mode,
                $exception,
            );
        }
    }

    /**
     * MIG-P3C shadow comparator entry. It never changes storefront serving mode.
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function previewIndexForShadow(array $query): array
    {
        $query = $this->normalizeQuery($query);
        $websiteId = $query['website_id'];
        $this->registry->assertReady($websiteId);
        $watermark = $this->store->watermark($websiteId);

        return $this->result(
            $query,
            self::SOURCE_INDEX,
            false,
            null,
            $this->searchIndex($query),
            CommerceRolloutGateInterface::MODE_SHADOW,
            [
                'index_incremental_watermark' => (int)(
                    $watermark['incremental_watermark'] ?? 0
                ),
                'direct_source_watermark' => null,
                'direct_snapshot_hash' => null,
                'direct_document_count' => null,
                'direct_match_count' => null,
                'degrade_marker' => null,
                'degrade_marker_persisted' => true,
            ],
        );
    }

    /**
     * @param array<string,mixed> $query
     */
    private function shouldUseIndex(array $query): bool
    {
        $websiteId = $query['website_id'];
        $alias = $this->alias->state($websiteId);
        $watermark = $this->store->watermark($websiteId);
        if ($alias['alias'] !== SearchAliasStore::ALIAS_INDEX
            || $alias['generation'] < 1
            || $alias['generation'] !== (int)($watermark['active_generation'] ?? 0)
        ) {
            return false;
        }

        $mode = $this->rollout->mode(self::CAPABILITY);
        if ($mode === CommerceRolloutGateInterface::MODE_ON) {
            return true;
        }
        if ($mode !== CommerceRolloutGateInterface::MODE_ALLOWLIST) {
            // off and shadow preserve Product current storefront serving.
            return false;
        }

        return $this->rollout->isEffectivelyOn(
            self::CAPABILITY,
            SearchRolloutGate::tupleKey(
                $query['website_id'],
                $query['store_id'],
                $query['channel_id'],
            ),
        );
    }

    /**
     * @param array<string,mixed> $query
     * @return list<array<string,mixed>>
     */
    private function searchIndex(array $query): array
    {
        $websiteId = $query['website_id'];
        $storeId = $query['store_id'];
        $channelId = $query['channel_id'];
        $locale = $query['locale'];
        $currency = $query['currency'];
        $needle = \mb_strtolower($query['q']);

        $byIdentity = [];
        $neutral = $this->store->documentsForScope(
            $websiteId,
            $storeId,
            $channelId,
            '',
            '',
        );
        foreach ($neutral as $document) {
            $document['dimension_source'] = 'neutral';
            $byIdentity[$this->entityKey($document)] = $document;
        }
        $exact = $this->store->documentsForScope(
            $websiteId,
            $storeId,
            $channelId,
            $locale,
            $currency,
        );
        foreach ($exact as $document) {
            $document['dimension_source'] = 'exact';
            $byIdentity[$this->entityKey($document)] = $document;
        }

        $hits = [];
        foreach ($byIdentity as $document) {
            if ((int)($document['website_id'] ?? -1) !== $websiteId
                || (int)($document['store_id'] ?? 0) !== $storeId
                || (int)($document['channel_id'] ?? 0) !== $channelId
                || (string)($document['status'] ?? '') !== 'published'
            ) {
                continue;
            }
            if ($needle !== '') {
                $haystack = \mb_strtolower(
                    (string)($document['title'] ?? '')
                    . ' '
                    . (string)($document['sku'] ?? ''),
                );
                if (!\str_contains($haystack, $needle)) {
                    continue;
                }
            }
            $document['source'] = self::SOURCE_INDEX;
            $document['requested_locale'] = $locale;
            $document['requested_currency'] = $currency;
            $hits[] = $document;
        }
        \usort(
            $hits,
            static fn(array $left, array $right): int => [
                (string)($left['entity_type'] ?? ''),
                (string)($left['entity_id'] ?? ''),
            ] <=> [
                (string)($right['entity_type'] ?? ''),
                (string)($right['entity_id'] ?? ''),
            ],
        );

        return $hits;
    }

    /**
     * @param array<string,mixed> $query
     * @return array{
     *   website_id:int,
     *   store_id:int,
     *   channel_id:int,
     *   locale:string,
     *   currency:string,
     *   q:string
     * }
     */
    private function normalizeQuery(array $query): array
    {
        foreach (['website_id', 'store_id', 'channel_id', 'locale', 'currency'] as $key) {
            if (!\array_key_exists($key, $query)) {
                throw new SearchQueryException(
                    SearchQueryException::ERROR_SCOPE,
                    (string)__('Search 查询缺少完整商城 Scope：%{1}', [$key]),
                    ['missing' => $key],
                );
            }
        }
        $websiteId = (int)$query['website_id'];
        SearchShardKey::fromWebsiteId($websiteId);
        $storeId = (int)$query['store_id'];
        $channelId = (int)$query['channel_id'];
        $locale = \trim((string)$query['locale']);
        $currency = \strtoupper(\trim((string)$query['currency']));
        $q = \trim((string)($query['q'] ?? ''));
        if ($storeId < 1 || $channelId < 1 || $locale === '' || $currency === '') {
            throw new SearchQueryException(
                SearchQueryException::ERROR_SCOPE,
                (string)__('Search 查询要求完整 Store/Channel/locale/currency Scope'),
                [
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                    'channel_id' => $channelId,
                ],
            );
        }
        if (\mb_strlen($locale) > 32
            || \strlen($currency) > 8
            || \mb_strlen($q) > 255
        ) {
            throw new SearchQueryException(
                SearchQueryException::ERROR_SCOPE,
                (string)__('Search 查询参数超过允许长度'),
                ['website_id' => $websiteId],
            );
        }

        return [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'channel_id' => $channelId,
            'locale' => $locale,
            'currency' => $currency,
            'q' => $q,
        ];
    }

    /**
     * @param array<string,mixed> $query
     */
    private function degradedResult(
        array $query,
        string $reason,
        string $mode,
        ?\Throwable $indexError = null,
    ): array {
        $direct = $this->readDirect($query);
        $indexWatermark = 0;
        try {
            $watermark = $this->store->watermark($query['website_id']);
            $indexWatermark = (int)($watermark['incremental_watermark'] ?? 0);
        } catch (\Throwable) {
            // The direct result remains usable; marker persistence below reports
            // whether the outage evidence was durably recorded.
        }

        $marker = null;
        $markerPersisted = false;
        try {
            $marker = $this->degrade->mark(
                $query['website_id'],
                $reason,
                $direct->sourceWatermark,
                $indexWatermark,
            );
            $markerPersisted = true;
        } catch (\Throwable) {
            $markerPersisted = false;
        }

        return $this->directResult(
            $query,
            $direct,
            self::SOURCE_DEGRADED,
            true,
            $reason,
            $marker,
            $markerPersisted,
            $mode,
            $indexWatermark,
            $indexError,
        );
    }

    /**
     * @param array<string,mixed> $query
     */
    private function readDirect(array $query): ProductDirectCatalogRead
    {
        try {
            return $this->directReader->searchPublished($query);
        } catch (SearchQueryException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new SearchQueryException(
                SearchQueryException::ERROR_DIRECT_READER_DOWN,
                (string)__('Product 目录直读不可用'),
                ['website_id' => $query['website_id']],
                $exception,
            );
        }
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $marker
     */
    private function directResult(
        array $query,
        ProductDirectCatalogRead $direct,
        string $source,
        bool $degraded,
        ?string $reason,
        ?array $marker,
        bool $markerPersisted,
        string $mode,
        int $indexWatermark = 0,
        ?\Throwable $indexError = null,
    ): array {
        return $this->result(
            $query,
            $source,
            $degraded,
            $reason,
            $direct->hits,
            $mode,
            [
                'index_incremental_watermark' => $indexWatermark,
                'direct_source_watermark' => $direct->sourceWatermark,
                'direct_snapshot_hash' => $direct->snapshotHash,
                'direct_document_count' => $direct->sourceDocumentCount,
                'direct_match_count' => \count($direct->hits),
                'degrade_marker' => $marker,
                'degrade_marker_persisted' => $markerPersisted,
                'index_error_class' => $indexError !== null
                    ? $indexError::class
                    : null,
            ],
        );
    }

    /**
     * @param array<string,mixed> $query
     * @param list<array<string,mixed>> $hits
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    private function result(
        array $query,
        string $source,
        bool $degraded,
        ?string $reason,
        array $hits,
        string $mode,
        array $evidence,
    ): array {
        if (!\in_array($source, [
            self::SOURCE_INDEX,
            self::SOURCE_DIRECT,
            self::SOURCE_DEGRADED,
        ], true)) {
            throw new SearchQueryException(
                SearchQueryException::ERROR_EMPTY_SUCCESS_FORBIDDEN,
                (string)__('Search 禁止无来源的成功响应'),
                ['website_id' => $query['website_id']],
            );
        }

        return [
            'ok' => true,
            'source' => $source,
            'degraded' => $degraded,
            'degrade_reason' => $reason,
            'rollout_mode' => $mode,
            'website_id' => $query['website_id'],
            'store_id' => $query['store_id'],
            'channel_id' => $query['channel_id'],
            'locale' => $query['locale'],
            'currency' => $query['currency'],
            'hits' => $hits,
            'hit_count' => \count($hits),
        ] + $evidence;
    }

    /** @param array<string,mixed> $document */
    private function entityKey(array $document): string
    {
        $type = \trim((string)($document['entity_type'] ?? ''));
        $id = \trim((string)($document['entity_id'] ?? ''));
        if ($type === '' || $id === '') {
            throw new \UnexpectedValueException('search_index_entity_identity_invalid');
        }

        return $type . ':' . $id;
    }
}
