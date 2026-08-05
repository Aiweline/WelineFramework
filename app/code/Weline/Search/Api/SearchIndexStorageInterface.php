<?php

declare(strict_types=1);

namespace Weline\Search\Api;

interface SearchIndexStorageInterface
{
    /**
     * @return array{website_id:int,generation:int,build_token:string,source_watermark:int}
     */
    public function beginBuild(
        int $websiteId,
        int $sourceWatermark,
        string $shardFingerprint,
    ): array;

    /** @param list<array<string,mixed>> $documents */
    public function replaceBuildDocuments(
        int $websiteId,
        int $generation,
        string $buildToken,
        array $documents,
    ): int;

    /**
     * @param callable():int $currentSourceWatermark
     * @return array<string,mixed>
     */
    public function commitBuild(
        int $websiteId,
        int $generation,
        string $buildToken,
        int $expectedSourceWatermark,
        callable $currentSourceWatermark,
    ): array;

    /**
     * @param list<array<string,mixed>> $documents
     * @param list<array<string,mixed>> $deleteKeys
     * @return array<string,mixed>
     */
    public function applyChange(
        int $websiteId,
        int $eventSeq,
        string $idempotencyKey,
        array $documents,
        array $deleteKeys,
    ): array;

    /** @return array<string,mixed> */
    public function watermark(int $websiteId): array;

    /** @return list<array<string,mixed>> */
    public function documentsForWebsite(int $websiteId): array;

    /** @return list<array<string,mixed>> */
    public function documentsForScope(
        int $websiteId,
        int $storeId,
        int $channelId,
        string $locale = '',
        string $currency = '',
    ): array;

    public function documentCount(int $websiteId): int;
}
