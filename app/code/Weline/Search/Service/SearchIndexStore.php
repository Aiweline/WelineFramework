<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\SearchIndexStorageInterface;
use Weline\Search\Model\SearchShardKey;

/**
 * Explicit in-memory Search storage used only by focused tests/harnesses.
 */
final class SearchIndexStore implements SearchIndexStorageInterface
{
    /** @var array<int,array<int,array<string,array<string,mixed>>>> */
    private array $documents = [];

    /** @var array<int,array<string,mixed>> */
    private array $watermarks = [];

    /** @var array<int,array<int,array<string,array{event_seq:int,payload_hash:string}>>> */
    private array $events = [];

    public static function forTesting(): self
    {
        return new self();
    }

    public function beginBuild(
        int $websiteId,
        int $sourceWatermark,
        string $shardFingerprint,
    ): array {
        SearchShardKey::fromWebsiteId($websiteId);
        if ($sourceWatermark < 0 || \trim($shardFingerprint) === '') {
            throw new \InvalidArgumentException((string)__(
                'Search full build 水位或 shard 指纹无效',
            ));
        }
        $current = $this->watermark($websiteId);
        $generation = \max(
            (int)$current['active_generation'],
            (int)$current['build_generation'],
        ) + 1;
        $token = \bin2hex(\random_bytes(32));
        $this->documents[$websiteId][$generation] = [];
        $this->watermarks[$websiteId] = $current + [
            'website_id' => $websiteId,
        ];
        $this->watermarks[$websiteId]['build_generation'] = $generation;
        $this->watermarks[$websiteId]['build_source_watermark'] = $sourceWatermark;
        $this->watermarks[$websiteId]['build_token'] = $token;
        $this->watermarks[$websiteId]['build_status'] = 'building';
        $this->watermarks[$websiteId]['shard_fingerprint'] = $shardFingerprint;
        $this->watermarks[$websiteId]['row_version'] = (int)$current['row_version'] + 1;

        return [
            'website_id' => $websiteId,
            'generation' => $generation,
            'build_token' => $token,
            'source_watermark' => $sourceWatermark,
        ];
    }

    public function replaceBuildDocuments(
        int $websiteId,
        int $generation,
        string $buildToken,
        array $documents,
    ): int {
        $this->assertBuild($websiteId, $generation, $buildToken);
        $this->documents[$websiteId][$generation] = [];
        foreach ($documents as $document) {
            $normalized = $this->normalizeDocument($websiteId, $generation, $document);
            $key = $this->documentKey($normalized);
            if (isset($this->documents[$websiteId][$generation][$key])) {
                throw new \RuntimeException((string)__(
                    'Search full build 包含重复 Scope 文档：%{1}',
                    [$key],
                ));
            }
            $this->documents[$websiteId][$generation][$key] = $normalized;
        }

        return \count($this->documents[$websiteId][$generation]);
    }

    public function commitBuild(
        int $websiteId,
        int $generation,
        string $buildToken,
        int $expectedSourceWatermark,
        callable $currentSourceWatermark,
    ): array {
        $this->assertBuild($websiteId, $generation, $buildToken);
        $currentSource = (int)$currentSourceWatermark();
        if ($currentSource !== $expectedSourceWatermark) {
            $this->watermarks[$websiteId]['build_status'] = 'source_advanced';

            return [
                'ok' => false,
                'reason' => 'source_advanced',
                'expected_source_watermark' => $expectedSourceWatermark,
                'current_source_watermark' => $currentSource,
            ];
        }
        $current = $this->watermark($websiteId);
        $current['active_generation'] = $generation;
        $current['full_watermark'] = $expectedSourceWatermark;
        $current['incremental_watermark'] = $expectedSourceWatermark;
        $current['build_generation'] = 0;
        $current['build_source_watermark'] = 0;
        $current['build_token'] = '';
        $current['build_status'] = 'idle';
        $current['row_version'] = (int)$current['row_version'] + 1;
        $this->watermarks[$websiteId] = $current;

        return ['ok' => true, 'reason' => 'committed', 'watermark' => $current];
    }

    public function applyChange(
        int $websiteId,
        int $eventSeq,
        string $idempotencyKey,
        array $documents,
        array $deleteKeys,
    ): array {
        SearchShardKey::fromWebsiteId($websiteId);
        $idempotencyKey = \trim($idempotencyKey);
        if ($eventSeq < 1 || $idempotencyKey === '') {
            throw new \InvalidArgumentException((string)__(
                'Search 增量事件身份无效',
            ));
        }
        $watermark = $this->watermark($websiteId);
        $generation = (int)$watermark['active_generation'];
        if ($generation < 1) {
            throw new \RuntimeException((string)__(
                'Search 尚无 active generation：website_id=%{1}',
                [$websiteId],
            ));
        }
        $payloadHash = $this->changeHash($documents, $deleteKeys);
        $existingEvent = $this->events[$websiteId][$generation][$idempotencyKey] ?? null;
        if ($existingEvent !== null) {
            if (!\hash_equals($existingEvent['payload_hash'], $payloadHash)) {
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

        $coveredByFull = $eventSeq <= (int)$watermark['incremental_watermark'];
        if (!$coveredByFull) {
            $incomingKeys = [];
            foreach ($documents as $document) {
                $incomingKeys[$this->documentKey(
                    $this->normalizeIdentity($websiteId, $document),
                )] = true;
            }
            foreach ($deleteKeys as $deleteKey) {
                $normalizedKey = $this->normalizeIdentity($websiteId, $deleteKey);
                $key = $this->documentKey($normalizedKey);
                if (!isset($incomingKeys[$key])) {
                    unset($this->documents[$websiteId][$generation][$key]);
                }
            }
            foreach ($documents as $document) {
                $incoming = $this->normalizeDocument($websiteId, $generation, $document);
                $key = $this->documentKey($incoming);
                $existing = $this->documents[$websiteId][$generation][$key] ?? null;
                if ($existing !== null) {
                    $existingVersion = (int)$existing['document_version'];
                    $incomingVersion = (int)$incoming['document_version'];
                    if ($existingVersion > $incomingVersion) {
                        continue;
                    }
                    if ($existingVersion === $incomingVersion) {
                        if (!\hash_equals(
                            (string)$existing['payload_hash'],
                            (string)$incoming['payload_hash'],
                        )) {
                            throw new \RuntimeException('search_document_same_version_payload_conflict');
                        }
                        continue;
                    }
                }
                $this->documents[$websiteId][$generation][$key] = $incoming;
            }
        }
        $this->events[$websiteId][$generation][$idempotencyKey] = [
            'event_seq' => $eventSeq,
            'payload_hash' => $payloadHash,
        ];
        $this->advanceContiguousWatermark($websiteId, $generation);

        return [
            'ok' => true,
            'replayed' => false,
            'applied' => !$coveredByFull,
            'reason' => $coveredByFull ? 'covered_by_full_build' : 'applied',
            'watermark' => $this->watermark($websiteId),
        ];
    }

    public function watermark(int $websiteId): array
    {
        SearchShardKey::fromWebsiteId($websiteId);

        return $this->watermarks[$websiteId] ?? [
            'website_id' => $websiteId,
            'active_generation' => 0,
            'build_generation' => 0,
            'build_source_watermark' => 0,
            'full_watermark' => 0,
            'incremental_watermark' => 0,
            'build_token' => '',
            'build_status' => 'idle',
            'shard_fingerprint' => '',
            'row_version' => 0,
        ];
    }

    public function documentsForWebsite(int $websiteId): array
    {
        $generation = (int)$this->watermark($websiteId)['active_generation'];
        $rows = \array_values($this->documents[$websiteId][$generation] ?? []);
        \usort(
            $rows,
            static fn(array $left, array $right): int => [
                (int)$left['store_id'],
                (int)$left['channel_id'],
                (string)$left['entity_type'],
                (string)$left['entity_id'],
            ] <=> [
                (int)$right['store_id'],
                (int)$right['channel_id'],
                (string)$right['entity_type'],
                (string)$right['entity_id'],
            ],
        );

        return $rows;
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

        return \array_values(\array_filter(
            $this->documentsForWebsite($websiteId),
            static fn(array $document): bool =>
                (int)$document['store_id'] === $storeId
                && (int)$document['channel_id'] === $channelId
                && (string)$document['locale'] === $locale
                && (string)$document['currency'] === $currency,
        ));
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function normalizeDocument(int $websiteId, int $generation, array $document): array
    {
        $identity = $this->normalizeIdentity($websiteId, $document);
        $version = (int)($document['document_version'] ?? -1);
        if ($version < 0) {
            throw new \InvalidArgumentException((string)__(
                'Search document_version 不能为负数',
            ));
        }
        $payload = $identity + [
            'generation' => $generation,
            'document_version' => $version,
            'title' => (string)($document['title'] ?? ''),
            'sku' => (string)($document['sku'] ?? ''),
            'status' => (string)($document['status'] ?? 'published'),
        ];
        $payload['payload_hash'] = $this->documentHash($payload);
        $payload['updated_at'] = \gmdate('Y-m-d H:i:s');

        return $payload;
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private function normalizeIdentity(int $websiteId, array $identity): array
    {
        if ((int)($identity['website_id'] ?? -1) !== $websiteId) {
            throw new \InvalidArgumentException('search_document_website_scope_mismatch');
        }
        $normalized = [
            'entity_type' => \trim((string)($identity['entity_type'] ?? '')),
            'entity_id' => \trim((string)($identity['entity_id'] ?? '')),
            'website_id' => $websiteId,
            'website_code' => \trim((string)($identity['website_code'] ?? '')),
            'store_id' => (int)($identity['store_id'] ?? 0),
            'store_code' => \trim((string)($identity['store_code'] ?? '')),
            'channel_id' => (int)($identity['channel_id'] ?? 0),
            'channel_code' => \trim((string)($identity['channel_code'] ?? '')),
            'locale' => \trim((string)($identity['locale'] ?? '')),
            'currency' => \trim((string)($identity['currency'] ?? '')),
        ];
        if ($normalized['entity_type'] === ''
            || $normalized['entity_id'] === ''
            || $normalized['website_code'] === ''
            || $normalized['store_id'] <= 0
            || $normalized['store_code'] === ''
            || $normalized['channel_id'] <= 0
            || $normalized['channel_code'] === ''
        ) {
            throw new \InvalidArgumentException('search_document_scope_identity_invalid');
        }

        return $normalized;
    }

    /** @param array<string,mixed> $identity */
    private function documentKey(array $identity): string
    {
        return \implode('|', [
            (string)$identity['entity_type'],
            (string)$identity['entity_id'],
            (string)$identity['store_id'],
            (string)$identity['channel_id'],
            (string)$identity['locale'],
            (string)$identity['currency'],
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function documentHash(array $payload): string
    {
        unset($payload['payload_hash'], $payload['updated_at'], $payload['generation']);
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

    private function assertBuild(int $websiteId, int $generation, string $buildToken): void
    {
        $watermark = $this->watermark($websiteId);
        if ((int)$watermark['build_generation'] !== $generation
            || (string)$watermark['build_status'] !== 'building'
            || !\hash_equals((string)$watermark['build_token'], $buildToken)
        ) {
            throw new \RuntimeException('search_build_fence_rejected');
        }
    }

    private function advanceContiguousWatermark(int $websiteId, int $generation): void
    {
        $current = (int)$this->watermarks[$websiteId]['incremental_watermark'];
        $sequences = [];
        foreach ($this->events[$websiteId][$generation] ?? [] as $event) {
            $sequences[(int)$event['event_seq']] = true;
        }
        while (isset($sequences[$current + 1])) {
            $current++;
        }
        $this->watermarks[$websiteId]['incremental_watermark'] = $current;
        $this->watermarks[$websiteId]['row_version']++;
    }
}
