<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Api\ProductSearchProjectionSourceInterface;
use Weline\Search\Api\SearchIndexStorageInterface;
use Weline\Search\Api\SearchShardRegistryInterface;
use Weline\Search\Model\SearchShardKey;

/**
 * Staged full Search build with source-watermark fencing.
 */
final class SearchIndexBuilder
{
    public const CAPABILITY = 'search';
    private const MAX_STABLE_SNAPSHOT_ATTEMPTS = 6;
    private readonly ?SearchShardProvisioner $provisioner;

    public function __construct(
        private readonly SearchShardRegistryInterface $registry,
        private readonly SearchIndexStorageInterface $store,
        private readonly ProductSearchProjectionSourceInterface $source,
        private readonly SearchShardSchemaCatalog $catalog,
        ?SearchShardProvisioner $provisioner = null,
    ) {
        $this->provisioner = $provisioner
            ?? ($registry instanceof SearchShardRegistryStore
                ? null
                : ObjectManager::getInstance(SearchShardProvisioner::class));
    }

    public static function forTesting(
        ?SearchShardRegistryStore $registry = null,
        ?SearchIndexStore $store = null,
        ?ArrayProductSearchProjectionSource $source = null,
    ): self {
        $registry ??= SearchShardRegistryStore::forTesting([0]);
        foreach ($registry->getRegisteredShardKeys() as $shardKey) {
            $websiteId = SearchShardKey::parse($shardKey);
            $registry->compareAndSet(
                $websiteId,
                [SearchShardRegistryStore::STATUS_UNPROVISIONED],
                SearchShardRegistryStore::STATUS_PROVISIONING,
            );
            $registry->markReady(
                $websiteId,
                \hash('sha256', 'search-test-shard:' . $websiteId),
                SearchShardSchemaCatalog::SCHEMA_VERSION,
            );
        }

        return new self(
            $registry,
            $store ?? SearchIndexStore::forTesting(),
            $source ?? ArrayProductSearchProjectionSource::forTesting(),
            new SearchShardSchemaCatalog(),
        );
    }

    public function registry(): SearchShardRegistryInterface
    {
        return $this->registry;
    }

    public function store(): SearchIndexStorageInterface
    {
        return $this->store;
    }

    public function source(): ProductSearchProjectionSourceInterface
    {
        return $this->source;
    }

    /**
     * The optional documents argument exists only for the legacy isolated
     * migration harness. Production callers must use Product Query source.
     *
     * @param list<array<string,mixed>>|null $publishedDocuments
     * @return array<string,mixed>
     */
    public function rebuildWebsite(int $websiteId, ?array $publishedDocuments = null): array
    {
        SearchShardKey::fromWebsiteId($websiteId);
        if ($publishedDocuments !== null) {
            if ($this->registry instanceof SearchShardRegistryStore
                && !$this->registry->isReady($websiteId)
            ) {
                $this->registry->ensureWebsite($websiteId);
                $this->registry->compareAndSet(
                    $websiteId,
                    [SearchShardRegistryStore::STATUS_UNPROVISIONED],
                    SearchShardRegistryStore::STATUS_PROVISIONING,
                );
                $this->registry->markReady(
                    $websiteId,
                    \hash('sha256', 'search-test-shard:' . $websiteId),
                    SearchShardSchemaCatalog::SCHEMA_VERSION,
                );
            }
            $this->seedLegacyHarness($websiteId, $publishedDocuments);
        }
        if (!$this->registry->isReady($websiteId) && $this->provisioner !== null) {
            $provisioned = $this->provisioner->provisionWebsite($websiteId);
            if (!$provisioned->isReady()) {
                throw new \RuntimeException((string)__(
                    'Search full build 无法 provision Website shard：website_id=%{1} status=%{2}',
                    [$websiteId, $provisioned->status],
                ));
            }
        }
        $this->registry->assertReady($websiteId);
        $shardKey = SearchShardKey::fromWebsiteId($websiteId);
        $fingerprint = \trim($this->registry->getFingerprint($websiteId));
        if (\preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \RuntimeException((string)__(
                'Search shard ready 指纹无效：website_id=%{1}',
                [$websiteId],
            ));
        }
        $lastReason = 'snapshot_not_stable';

        for ($attempt = 1; $attempt <= self::MAX_STABLE_SNAPSHOT_ATTEMPTS; $attempt++) {
            $before = $this->source->currentWatermark($websiteId);
            $snapshot = $this->source->snapshotWebsite($websiteId);
            $after = $this->source->currentWatermark($websiteId);
            $this->assertSnapshot($websiteId, $snapshot);
            $snapshotWatermark = (int)$snapshot['source_watermark'];
            if ($before !== $snapshotWatermark || $snapshotWatermark !== $after) {
                $lastReason = 'source_changed_during_snapshot';
                continue;
            }

            $build = $this->store->beginBuild($websiteId, $after, $fingerprint);
            $written = $this->store->replaceBuildDocuments(
                $websiteId,
                (int)$build['generation'],
                (string)$build['build_token'],
                $snapshot['documents'],
            );
            $commit = $this->store->commitBuild(
                $websiteId,
                (int)$build['generation'],
                (string)$build['build_token'],
                $after,
                fn(): int => $this->source->currentWatermark($websiteId),
            );
            if (empty($commit['ok'])) {
                $lastReason = (string)($commit['reason'] ?? 'build_commit_rejected');
                continue;
            }

            return [
                'ok' => true,
                'website_id' => $websiteId,
                'shard_key' => $shardKey,
                'generation' => (int)$build['generation'],
                'written' => $written,
                'document_count' => $this->store->documentCount($websiteId),
                'snapshot_hash' => (string)$snapshot['snapshot_hash'],
                'fingerprint' => $fingerprint,
                'schema_version' => SearchShardSchemaCatalog::SCHEMA_VERSION,
                'source_watermark' => $after,
                'watermark' => $commit['watermark'],
                'attempts' => $attempt,
                'source_of_truth' => 'product_current_projection',
            ];
        }

        throw new \RuntimeException((string)__(
            'Search full build 在 %{1} 次尝试内未获得稳定 Product 快照：%{2}',
            [self::MAX_STABLE_SNAPSHOT_ATTEMPTS, $lastReason],
        ));
    }

    public function composeShardFingerprint(string $shardKey): string
    {
        $tableFingerprints = [];
        foreach ($this->catalog->schemasForShard($shardKey) as $schema) {
            $payload = [
                'table' => $schema->tableName,
                'columns' => \array_map(
                    static fn($column): array => [
                        $column->name,
                        $column->type,
                        $column->length,
                        $column->nullable,
                        $column->primaryKey,
                    ],
                    $schema->columns,
                ),
                'indexes' => \array_map(
                    static fn($index): array => [
                        $index->name,
                        $index->columns,
                        $index->type,
                    ],
                    $schema->indexes,
                ),
            ];
            $tableFingerprints[$schema->tableName] = \hash(
                'sha256',
                (string)\json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            );
        }
        \ksort($tableFingerprints);

        return \hash('sha256', (string)\json_encode(
            $tableFingerprints,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string,mixed> $snapshot */
    private function assertSnapshot(int $websiteId, array $snapshot): void
    {
        if (($snapshot['contract'] ?? null) !== 'product.search_projection_snapshot.v1'
            || (int)($snapshot['website_id'] ?? -1) !== $websiteId
            || (int)($snapshot['source_watermark'] ?? -1) < 0
            || (int)($snapshot['scope_count'] ?? 0) < 1
            || !\is_array($snapshot['documents'] ?? null)
            || (int)($snapshot['document_count'] ?? -1) !== \count($snapshot['documents'])
            || \preg_match('/^[a-f0-9]{64}$/D', (string)($snapshot['snapshot_hash'] ?? '')) !== 1
        ) {
            throw new \UnexpectedValueException('product_search_snapshot_invalid');
        }
        foreach ($snapshot['documents'] as $document) {
            if (!\is_array($document)
                || (int)($document['website_id'] ?? -1) !== $websiteId
            ) {
                throw new \UnexpectedValueException('product_search_snapshot_scope_mismatch');
            }
        }
    }

    /** @param list<array<string,mixed>> $documents */
    private function seedLegacyHarness(int $websiteId, array $documents): void
    {
        if (!$this->source instanceof ArrayProductSearchProjectionSource) {
            throw new \LogicException('search_production_builder_rejects_caller_documents');
        }
        $maxVersion = 0;
        foreach ($documents as &$document) {
            if ((int)($document['website_id'] ?? -1) !== $websiteId) {
                throw new \InvalidArgumentException('search_legacy_document_website_mismatch');
            }
            $document['entity_type'] = (string)($document['entity_type'] ?? 'product');
            $document['website_code'] = \trim((string)($document['website_code'] ?? ''))
                ?: ($websiteId === 0 ? 'default' : 'website-' . $websiteId);
            if ((int)($document['store_id'] ?? 0) <= 0) {
                $document['store_id'] = 1;
            }
            $document['store_code'] = \trim((string)($document['store_code'] ?? ''))
                ?: 'default';
            if ((int)($document['channel_id'] ?? 0) <= 0) {
                $document['channel_id'] = 1;
            }
            $document['channel_code'] = \trim((string)($document['channel_code'] ?? ''))
                ?: 'default';
            $document['locale'] = (string)($document['locale'] ?? '');
            $document['currency'] = (string)($document['currency'] ?? '');
            $document['document_version'] = (int)(
                $document['document_version'] ?? $document['publish_version'] ?? 0
            );
            $maxVersion = \max(
                $maxVersion,
                (int)$document['document_version'],
            );
        }
        unset($document);
        $this->source->seedSnapshot($websiteId, $documents, $maxVersion);
    }
}
