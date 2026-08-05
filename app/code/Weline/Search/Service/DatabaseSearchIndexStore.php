<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Search\Api\SearchIndexStorageInterface;
use Weline\Search\Model\SearchShardKey;
use Weline\Search\Model\Shard\SearchAppliedEvent;
use Weline\Search\Model\Shard\SearchDocument;
use Weline\Search\Model\Shard\SearchWatermark;

/**
 * Durable generation-aware Search index storage.
 */
final class DatabaseSearchIndexStore implements SearchIndexStorageInterface
{
    private const MAX_CONTIGUOUS_ADVANCE = 10000;

    public function __construct(
        private readonly SearchDocument $document,
        private readonly SearchWatermark $watermarkModel,
        private readonly SearchAppliedEvent $appliedEvent,
        private readonly TransactionCoordinatorInterface $transactions,
    ) {
    }

    public function beginBuild(
        int $websiteId,
        int $sourceWatermark,
        string $shardFingerprint,
    ): array {
        SearchShardKey::fromWebsiteId($websiteId);
        $shardFingerprint = \trim($shardFingerprint);
        if ($sourceWatermark < 0
            || \preg_match('/^[a-f0-9]{64}$/D', $shardFingerprint) !== 1
        ) {
            throw new \InvalidArgumentException('search_build_identity_invalid');
        }

        return $this->transaction($websiteId, function () use (
            $websiteId,
            $sourceWatermark,
            $shardFingerprint,
        ): array {
            $this->ensureWatermark($websiteId);
            $current = $this->requireWatermark($websiteId, true);
            $generation = \max(
                (int)$current[SearchWatermark::schema_fields_ACTIVE_GENERATION],
                (int)$current[SearchWatermark::schema_fields_BUILD_GENERATION],
            ) + 1;
            $token = \bin2hex(\random_bytes(32));
            $this->updateWatermark($websiteId, $current, [
                SearchWatermark::schema_fields_BUILD_GENERATION => $generation,
                SearchWatermark::schema_fields_BUILD_SOURCE_WATERMARK => $sourceWatermark,
                SearchWatermark::schema_fields_BUILD_TOKEN => $token,
                SearchWatermark::schema_fields_BUILD_STATUS => SearchWatermark::BUILD_BUILDING,
                SearchWatermark::schema_fields_SHARD_FINGERPRINT => $shardFingerprint,
            ]);
            $this->newDocument($websiteId)
                ->where(SearchDocument::schema_fields_GENERATION, $generation)
                ->delete()
                ->fetch();

            return [
                'website_id' => $websiteId,
                'generation' => $generation,
                'build_token' => $token,
                'source_watermark' => $sourceWatermark,
            ];
        });
    }

    public function replaceBuildDocuments(
        int $websiteId,
        int $generation,
        string $buildToken,
        array $documents,
    ): int {
        return $this->transaction($websiteId, function () use (
            $websiteId,
            $generation,
            $buildToken,
            $documents,
        ): int {
            $watermark = $this->requireWatermark($websiteId, true);
            $this->assertBuild($watermark, $generation, $buildToken);
            $this->newDocument($websiteId)
                ->where(SearchDocument::schema_fields_GENERATION, $generation)
                ->delete()
                ->fetch();

            $seen = [];
            foreach ($documents as $document) {
                $normalized = $this->normalizeDocument($websiteId, $generation, $document);
                $key = $this->documentKey($normalized);
                if (isset($seen[$key])) {
                    throw new \RuntimeException('search_full_build_duplicate_scope_document');
                }
                $seen[$key] = true;
                $this->newDocument($websiteId)->setData($normalized)->save();
            }

            return \count($seen);
        });
    }

    public function commitBuild(
        int $websiteId,
        int $generation,
        string $buildToken,
        int $expectedSourceWatermark,
        callable $currentSourceWatermark,
    ): array {
        return $this->transaction($websiteId, function () use (
            $websiteId,
            $generation,
            $buildToken,
            $expectedSourceWatermark,
            $currentSourceWatermark,
        ): array {
            $watermark = $this->requireWatermark($websiteId, true);
            $this->assertBuild($watermark, $generation, $buildToken);
            $currentSource = (int)$currentSourceWatermark();
            if ($currentSource !== $expectedSourceWatermark) {
                $after = $this->updateWatermark($websiteId, $watermark, [
                    SearchWatermark::schema_fields_BUILD_STATUS
                        => SearchWatermark::BUILD_SOURCE_ADVANCED,
                ]);

                return [
                    'ok' => false,
                    'reason' => 'source_advanced',
                    'expected_source_watermark' => $expectedSourceWatermark,
                    'current_source_watermark' => $currentSource,
                    'watermark' => $after,
                ];
            }

            $after = $this->updateWatermark($websiteId, $watermark, [
                SearchWatermark::schema_fields_ACTIVE_GENERATION => $generation,
                SearchWatermark::schema_fields_BUILD_GENERATION => 0,
                SearchWatermark::schema_fields_BUILD_SOURCE_WATERMARK => 0,
                SearchWatermark::schema_fields_FULL_WATERMARK => $expectedSourceWatermark,
                SearchWatermark::schema_fields_INCREMENTAL_WATERMARK => $expectedSourceWatermark,
                SearchWatermark::schema_fields_BUILD_TOKEN => '',
                SearchWatermark::schema_fields_BUILD_STATUS => SearchWatermark::BUILD_IDLE,
            ]);

            return ['ok' => true, 'reason' => 'committed', 'watermark' => $after];
        });
    }

    public function applyChange(
        int $websiteId,
        int $eventSeq,
        string $idempotencyKey,
        array $documents,
        array $deleteKeys,
    ): array {
        $idempotencyKey = \trim($idempotencyKey);
        if ($eventSeq < 1 || $idempotencyKey === '' || \strlen($idempotencyKey) > 191) {
            throw new \InvalidArgumentException('search_incremental_identity_invalid');
        }
        $payloadHash = $this->changeHash($documents, $deleteKeys);

        return $this->transaction($websiteId, function () use (
            $websiteId,
            $eventSeq,
            $idempotencyKey,
            $documents,
            $deleteKeys,
            $payloadHash,
        ): array {
            $watermark = $this->requireWatermark($websiteId, true);
            $generation = (int)$watermark[SearchWatermark::schema_fields_ACTIVE_GENERATION];
            if ($generation < 1) {
                throw new \RuntimeException('search_active_generation_missing');
            }

            $existing = $this->findAppliedEvent(
                $websiteId,
                $generation,
                $idempotencyKey,
            );
            if ($existing !== null) {
                if (!\hash_equals(
                    (string)$existing[SearchAppliedEvent::schema_fields_PAYLOAD_HASH],
                    $payloadHash,
                )) {
                    throw new \RuntimeException('search_incremental_idempotency_payload_conflict');
                }

                return [
                    'ok' => true,
                    'replayed' => true,
                    'applied' => false,
                    'reason' => 'duplicate_idempotency_key',
                    'watermark' => $watermark,
                ];
            }
            $sameSequence = $this->findAppliedSequence($websiteId, $generation, $eventSeq);
            if ($sameSequence !== null) {
                throw new \RuntimeException('search_incremental_sequence_identity_conflict');
            }

            $coveredByFull = $eventSeq
                <= (int)$watermark[SearchWatermark::schema_fields_INCREMENTAL_WATERMARK];
            if (!$coveredByFull) {
                $incomingKeys = [];
                foreach ($documents as $document) {
                    $incomingKeys[$this->documentKey(
                        $this->normalizeIdentity($websiteId, $document),
                    )] = true;
                }
                foreach ($deleteKeys as $deleteKey) {
                    $identity = $this->normalizeIdentity($websiteId, $deleteKey);
                    if (!isset($incomingKeys[$this->documentKey($identity)])) {
                        $this->deleteDocument($websiteId, $generation, $identity);
                    }
                }
                foreach ($documents as $document) {
                    $this->upsertDocument(
                        $websiteId,
                        $generation,
                        $this->normalizeDocument($websiteId, $generation, $document),
                    );
                }
            }

            $this->newAppliedEvent($websiteId)->setData([
                SearchAppliedEvent::schema_fields_GENERATION => $generation,
                SearchAppliedEvent::schema_fields_EVENT_SEQ => $eventSeq,
                SearchAppliedEvent::schema_fields_IDEMPOTENCY_KEY => $idempotencyKey,
                SearchAppliedEvent::schema_fields_PAYLOAD_HASH => $payloadHash,
                SearchAppliedEvent::schema_fields_APPLIED_AT => \gmdate('Y-m-d H:i:s'),
            ])->save();

            $nextWatermark = $this->advanceContiguousWatermark(
                $websiteId,
                $generation,
                $watermark,
            );

            return [
                'ok' => true,
                'replayed' => false,
                'applied' => !$coveredByFull,
                'reason' => $coveredByFull ? 'covered_by_full_build' : 'applied',
                'watermark' => $nextWatermark,
            ];
        });
    }

    public function watermark(int $websiteId): array
    {
        SearchShardKey::fromWebsiteId($websiteId);
        $row = $this->findWatermark($websiteId);

        return $row ?? $this->emptyWatermark($websiteId);
    }

    public function documentsForWebsite(int $websiteId): array
    {
        $generation = (int)$this->watermark($websiteId)[
            SearchWatermark::schema_fields_ACTIVE_GENERATION
        ];
        if ($generation < 1) {
            return [];
        }
        $rows = $this->newDocument($websiteId)
            ->where(SearchDocument::schema_fields_GENERATION, $generation)
            ->order(SearchDocument::schema_fields_STORE_ID, 'ASC')
            ->order(SearchDocument::schema_fields_CHANNEL_ID, 'ASC')
            ->order(SearchDocument::schema_fields_ENTITY_TYPE, 'ASC')
            ->order(SearchDocument::schema_fields_ENTITY_ID, 'ASC')
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \array_values($rows) : [];
    }

    public function documentCount(int $websiteId): int
    {
        return \count($this->documentsForWebsite($websiteId));
    }

    public function documentsForScope(
        int $websiteId,
        int $storeId,
        int $channelId,
        string $locale = '',
        string $currency = '',
    ): array {
        if ($storeId <= 0 || $channelId <= 0) {
            throw new \InvalidArgumentException('search_scope_identity_invalid');
        }
        $generation = (int)$this->watermark($websiteId)[
            SearchWatermark::schema_fields_ACTIVE_GENERATION
        ];
        if ($generation < 1) {
            return [];
        }
        $rows = $this->newDocument($websiteId)
            ->where(SearchDocument::schema_fields_GENERATION, $generation)
            ->where(SearchDocument::schema_fields_STORE_ID, $storeId)
            ->where(SearchDocument::schema_fields_CHANNEL_ID, $channelId)
            ->where(SearchDocument::schema_fields_LOCALE, $locale)
            ->where(SearchDocument::schema_fields_CURRENCY, $currency)
            ->order(SearchDocument::schema_fields_ENTITY_TYPE, 'ASC')
            ->order(SearchDocument::schema_fields_ENTITY_ID, 'ASC')
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \array_values($rows) : [];
    }

    private function transaction(int $websiteId, callable $callback): mixed
    {
        $connection = $this->connection($websiteId);
        if ($this->transactions->isActive($connection)) {
            return $callback();
        }

        return $this->transactions->run($connection, $callback);
    }

    private function connection(int $websiteId): ConnectionFactory
    {
        return $this->newWatermark($websiteId)->getConnection();
    }

    private function ensureWatermark(int $websiteId): void
    {
        if ($this->findWatermark($websiteId) !== null) {
            return;
        }
        $connection = $this->connection($websiteId);
        $insert = function () use ($websiteId): void {
            $model = $this->newWatermark($websiteId)->setData([
                SearchWatermark::schema_fields_WEBSITE_ID => $websiteId,
                SearchWatermark::schema_fields_ACTIVE_GENERATION => 0,
                SearchWatermark::schema_fields_BUILD_GENERATION => 0,
                SearchWatermark::schema_fields_BUILD_SOURCE_WATERMARK => 0,
                SearchWatermark::schema_fields_FULL_WATERMARK => 0,
                SearchWatermark::schema_fields_INCREMENTAL_WATERMARK => 0,
                SearchWatermark::schema_fields_BUILD_TOKEN => '',
                SearchWatermark::schema_fields_BUILD_STATUS => SearchWatermark::BUILD_IDLE,
                SearchWatermark::schema_fields_SHARD_FINGERPRINT => '',
                SearchWatermark::schema_fields_ROW_VERSION => 0,
                SearchWatermark::schema_fields_UPDATED_AT => \gmdate('Y-m-d H:i:s'),
            ]);
            $model->save();
        };
        try {
            if ($this->transactions->isActive($connection)) {
                $this->transactions->withSavepoint(
                    $connection,
                    'search_watermark_ensure',
                    $insert,
                );
            } else {
                $insert();
            }
        } catch (\Throwable $insertError) {
            if ($this->findWatermark($websiteId) === null) {
                throw $insertError;
            }
        }
        if ($this->findWatermark($websiteId) === null) {
            throw new \RuntimeException('search_watermark_ensure_failed');
        }
    }

    /** @return array<string,mixed> */
    private function requireWatermark(int $websiteId, bool $lockingRead = false): array
    {
        $row = $this->findWatermark($websiteId, $lockingRead);
        if ($row === null) {
            throw new \RuntimeException('search_watermark_missing');
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function findWatermark(int $websiteId, bool $lockingRead = false): ?array
    {
        $model = $this->newWatermark($websiteId)
            ->where(SearchWatermark::schema_fields_WEBSITE_ID, $websiteId);
        if ($lockingRead && $this->supportsForUpdate($model->getConnection())) {
            $model->additional('FOR UPDATE');
        }
        $model->find()->fetch();

        return $model->getId() ? $model->getData() : null;
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    private function updateWatermark(int $websiteId, array $current, array $patch): array
    {
        $oldVersion = (int)($current[SearchWatermark::schema_fields_ROW_VERSION] ?? -1);
        if ($oldVersion < 0) {
            throw new \RuntimeException('search_watermark_row_version_invalid');
        }
        $nextVersion = $oldVersion + 1;
        $this->newWatermark($websiteId)
            ->where(SearchWatermark::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SearchWatermark::schema_fields_ROW_VERSION, $oldVersion)
            ->update($patch + [
                SearchWatermark::schema_fields_ROW_VERSION => $nextVersion,
                SearchWatermark::schema_fields_UPDATED_AT => \gmdate('Y-m-d H:i:s'),
            ])
            ->fetch();
        $after = $this->requireWatermark($websiteId);
        if ((int)$after[SearchWatermark::schema_fields_ROW_VERSION] !== $nextVersion) {
            throw new \RuntimeException('search_watermark_cas_conflict');
        }

        return $after;
    }

    /** @param array<string,mixed> $watermark */
    private function assertBuild(array $watermark, int $generation, string $buildToken): void
    {
        if ((int)$watermark[SearchWatermark::schema_fields_BUILD_GENERATION] !== $generation
            || (string)$watermark[SearchWatermark::schema_fields_BUILD_STATUS]
                !== SearchWatermark::BUILD_BUILDING
            || !\hash_equals(
                (string)$watermark[SearchWatermark::schema_fields_BUILD_TOKEN],
                $buildToken,
            )
        ) {
            throw new \RuntimeException('search_build_fence_rejected');
        }
    }

    /** @param array<string,mixed> $document */
    private function upsertDocument(int $websiteId, int $generation, array $document): void
    {
        $existing = $this->findDocument($websiteId, $generation, $document);
        if ($existing === null) {
            try {
                $this->newDocument($websiteId)->setData($document)->save();

                return;
            } catch (\Throwable $insertError) {
                $existing = $this->findDocument($websiteId, $generation, $document);
                if ($existing === null) {
                    throw $insertError;
                }
            }
        }

        $existingVersion = (int)$existing[SearchDocument::schema_fields_DOCUMENT_VERSION];
        $incomingVersion = (int)$document[SearchDocument::schema_fields_DOCUMENT_VERSION];
        if ($existingVersion > $incomingVersion) {
            return;
        }
        if ($existingVersion === $incomingVersion) {
            if (!\hash_equals(
                (string)$existing[SearchDocument::schema_fields_PAYLOAD_HASH],
                (string)$document[SearchDocument::schema_fields_PAYLOAD_HASH],
            )) {
                throw new \RuntimeException('search_document_same_version_payload_conflict');
            }

            return;
        }

        $this->documentIdentityQuery(
            $this->newDocument($websiteId),
            $generation,
            $document,
        )
            ->where(SearchDocument::schema_fields_DOCUMENT_VERSION, $existingVersion)
            ->update([
                SearchDocument::schema_fields_WEBSITE_CODE
                    => $document[SearchDocument::schema_fields_WEBSITE_CODE],
                SearchDocument::schema_fields_STORE_CODE
                    => $document[SearchDocument::schema_fields_STORE_CODE],
                SearchDocument::schema_fields_CHANNEL_CODE
                    => $document[SearchDocument::schema_fields_CHANNEL_CODE],
                SearchDocument::schema_fields_DOCUMENT_VERSION => $incomingVersion,
                SearchDocument::schema_fields_PAYLOAD_HASH
                    => $document[SearchDocument::schema_fields_PAYLOAD_HASH],
                SearchDocument::schema_fields_TITLE => $document[SearchDocument::schema_fields_TITLE],
                SearchDocument::schema_fields_SKU => $document[SearchDocument::schema_fields_SKU],
                SearchDocument::schema_fields_STATUS => $document[SearchDocument::schema_fields_STATUS],
                SearchDocument::schema_fields_UPDATED_AT => \gmdate('Y-m-d H:i:s'),
            ])
            ->fetch();
        $winner = $this->findDocument($websiteId, $generation, $document);
        if ($winner === null) {
            throw new \RuntimeException('search_document_cas_missing');
        }
        $winnerVersion = (int)$winner[SearchDocument::schema_fields_DOCUMENT_VERSION];
        if ($winnerVersion < $incomingVersion) {
            throw new \RuntimeException('search_document_cas_conflict');
        }
        if ($winnerVersion === $incomingVersion
            && !\hash_equals(
                (string)$winner[SearchDocument::schema_fields_PAYLOAD_HASH],
                (string)$document[SearchDocument::schema_fields_PAYLOAD_HASH],
            )
        ) {
            throw new \RuntimeException('search_document_same_version_payload_conflict');
        }
    }

    /** @param array<string,mixed> $identity */
    private function deleteDocument(int $websiteId, int $generation, array $identity): void
    {
        $this->documentIdentityQuery(
            $this->newDocument($websiteId),
            $generation,
            $identity,
        )->delete()->fetch();
    }

    /** @param array<string,mixed> $identity @return array<string,mixed>|null */
    private function findDocument(int $websiteId, int $generation, array $identity): ?array
    {
        $model = $this->documentIdentityQuery(
            $this->newDocument($websiteId),
            $generation,
            $identity,
        );
        $model->find()->fetch();

        return $model->getId() ? $model->getData() : null;
    }

    /** @param array<string,mixed> $identity */
    private function documentIdentityQuery(
        SearchDocument $model,
        int $generation,
        array $identity,
    ): SearchDocument {
        return $model
            ->where(SearchDocument::schema_fields_GENERATION, $generation)
            ->where(SearchDocument::schema_fields_ENTITY_TYPE, (string)$identity['entity_type'])
            ->where(SearchDocument::schema_fields_ENTITY_ID, (string)$identity['entity_id'])
            ->where(SearchDocument::schema_fields_STORE_ID, (int)$identity['store_id'])
            ->where(SearchDocument::schema_fields_CHANNEL_ID, (int)$identity['channel_id'])
            ->where(SearchDocument::schema_fields_LOCALE, (string)$identity['locale'])
            ->where(SearchDocument::schema_fields_CURRENCY, (string)$identity['currency']);
    }

    /** @return array<string,mixed>|null */
    private function findAppliedEvent(
        int $websiteId,
        int $generation,
        string $idempotencyKey,
    ): ?array {
        $model = $this->newAppliedEvent($websiteId)
            ->where(SearchAppliedEvent::schema_fields_GENERATION, $generation)
            ->where(SearchAppliedEvent::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
            ->find()
            ->fetch();

        return $model->getId() ? $model->getData() : null;
    }

    /** @return array<string,mixed>|null */
    private function findAppliedSequence(
        int $websiteId,
        int $generation,
        int $eventSeq,
    ): ?array {
        $model = $this->newAppliedEvent($websiteId)
            ->where(SearchAppliedEvent::schema_fields_GENERATION, $generation)
            ->where(SearchAppliedEvent::schema_fields_EVENT_SEQ, $eventSeq)
            ->find()
            ->fetch();

        return $model->getId() ? $model->getData() : null;
    }

    /** @param array<string,mixed> $watermark @return array<string,mixed> */
    private function advanceContiguousWatermark(
        int $websiteId,
        int $generation,
        array $watermark,
    ): array {
        $current = (int)$watermark[SearchWatermark::schema_fields_INCREMENTAL_WATERMARK];
        for ($step = 0; $step < self::MAX_CONTIGUOUS_ADVANCE; $step++) {
            if ($this->findAppliedSequence($websiteId, $generation, $current + 1) === null) {
                break;
            }
            $current++;
        }
        if ($current === (int)$watermark[SearchWatermark::schema_fields_INCREMENTAL_WATERMARK]) {
            return $watermark;
        }

        return $this->updateWatermark($websiteId, $watermark, [
            SearchWatermark::schema_fields_INCREMENTAL_WATERMARK => $current,
        ]);
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function normalizeDocument(int $websiteId, int $generation, array $document): array
    {
        $identity = $this->normalizeIdentity($websiteId, $document);
        $version = (int)($document['document_version'] ?? -1);
        if ($version < 0) {
            throw new \InvalidArgumentException('search_document_version_invalid');
        }
        $payload = $identity + [
            SearchDocument::schema_fields_GENERATION => $generation,
            SearchDocument::schema_fields_DOCUMENT_VERSION => $version,
            SearchDocument::schema_fields_TITLE => (string)($document['title'] ?? ''),
            SearchDocument::schema_fields_SKU => (string)($document['sku'] ?? ''),
            SearchDocument::schema_fields_STATUS => (string)($document['status'] ?? 'published'),
        ];
        $payload[SearchDocument::schema_fields_PAYLOAD_HASH] = $this->documentHash($payload);
        $payload[SearchDocument::schema_fields_UPDATED_AT] = \gmdate('Y-m-d H:i:s');

        return $payload;
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private function normalizeIdentity(int $websiteId, array $identity): array
    {
        if ((int)($identity['website_id'] ?? -1) !== $websiteId) {
            throw new \InvalidArgumentException('search_document_website_scope_mismatch');
        }
        $normalized = [
            SearchDocument::schema_fields_ENTITY_TYPE
                => \trim((string)($identity['entity_type'] ?? '')),
            SearchDocument::schema_fields_ENTITY_ID
                => \trim((string)($identity['entity_id'] ?? '')),
            SearchDocument::schema_fields_WEBSITE_ID => $websiteId,
            SearchDocument::schema_fields_WEBSITE_CODE
                => \trim((string)($identity['website_code'] ?? '')),
            SearchDocument::schema_fields_STORE_ID => (int)($identity['store_id'] ?? 0),
            SearchDocument::schema_fields_STORE_CODE
                => \trim((string)($identity['store_code'] ?? '')),
            SearchDocument::schema_fields_CHANNEL_ID => (int)($identity['channel_id'] ?? 0),
            SearchDocument::schema_fields_CHANNEL_CODE
                => \trim((string)($identity['channel_code'] ?? '')),
            SearchDocument::schema_fields_LOCALE => \trim((string)($identity['locale'] ?? '')),
            SearchDocument::schema_fields_CURRENCY => \trim((string)($identity['currency'] ?? '')),
        ];
        if ($normalized[SearchDocument::schema_fields_ENTITY_TYPE] === ''
            || $normalized[SearchDocument::schema_fields_ENTITY_ID] === ''
            || $normalized[SearchDocument::schema_fields_WEBSITE_CODE] === ''
            || $normalized[SearchDocument::schema_fields_STORE_ID] <= 0
            || $normalized[SearchDocument::schema_fields_STORE_CODE] === ''
            || $normalized[SearchDocument::schema_fields_CHANNEL_ID] <= 0
            || $normalized[SearchDocument::schema_fields_CHANNEL_CODE] === ''
        ) {
            throw new \InvalidArgumentException('search_document_scope_identity_invalid');
        }

        return $normalized;
    }

    /** @param array<string,mixed> $identity */
    private function documentKey(array $identity): string
    {
        return \implode('|', [
            (string)$identity[SearchDocument::schema_fields_ENTITY_TYPE],
            (string)$identity[SearchDocument::schema_fields_ENTITY_ID],
            (string)$identity[SearchDocument::schema_fields_STORE_ID],
            (string)$identity[SearchDocument::schema_fields_CHANNEL_ID],
            (string)$identity[SearchDocument::schema_fields_LOCALE],
            (string)$identity[SearchDocument::schema_fields_CURRENCY],
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function documentHash(array $payload): string
    {
        unset(
            $payload[SearchDocument::schema_fields_ID],
            $payload[SearchDocument::schema_fields_GENERATION],
            $payload[SearchDocument::schema_fields_PAYLOAD_HASH],
            $payload[SearchDocument::schema_fields_UPDATED_AT],
        );
        \ksort($payload);

        return \hash('sha256', (string)\json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param list<array<string,mixed>> $documents @param list<array<string,mixed>> $deleteKeys */
    private function changeHash(array $documents, array $deleteKeys): string
    {
        return \hash('sha256', (string)\json_encode(
            ['documents' => $documents, 'delete_keys' => $deleteKeys],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array<string,mixed> */
    private function emptyWatermark(int $websiteId): array
    {
        return [
            SearchWatermark::schema_fields_WEBSITE_ID => $websiteId,
            SearchWatermark::schema_fields_ACTIVE_GENERATION => 0,
            SearchWatermark::schema_fields_BUILD_GENERATION => 0,
            SearchWatermark::schema_fields_BUILD_SOURCE_WATERMARK => 0,
            SearchWatermark::schema_fields_FULL_WATERMARK => 0,
            SearchWatermark::schema_fields_INCREMENTAL_WATERMARK => 0,
            SearchWatermark::schema_fields_BUILD_TOKEN => '',
            SearchWatermark::schema_fields_BUILD_STATUS => SearchWatermark::BUILD_IDLE,
            SearchWatermark::schema_fields_SHARD_FINGERPRINT => '',
            SearchWatermark::schema_fields_ROW_VERSION => 0,
        ];
    }

    private function supportsForUpdate(ConnectionFactory $connection): bool
    {
        $type = \strtolower((string)$connection
            ->getConnector()->getConfigProvider()->getDbType());

        return \in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function newDocument(int $websiteId): SearchDocument
    {
        return (clone $this->document)->clearData()->clearQuery()->forWebsite($websiteId);
    }

    private function newWatermark(int $websiteId): SearchWatermark
    {
        return (clone $this->watermarkModel)->clearData()->clearQuery()->forWebsite($websiteId);
    }

    private function newAppliedEvent(int $websiteId): SearchAppliedEvent
    {
        return (clone $this->appliedEvent)->clearData()->clearQuery()->forWebsite($websiteId);
    }
}
