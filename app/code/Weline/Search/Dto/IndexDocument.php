<?php

declare(strict_types=1);

namespace Weline\Search\Dto;

/**
 * Frozen document shape shared by mysql / wls_memory / redis / elasticsearch.
 */
final class IndexDocument
{
    /**
     * @param list<string> $keywords
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $indexer,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly int $websiteId,
        public readonly int $storeId,
        public readonly int $channelId,
        public readonly string $locale,
        public readonly string $currency,
        public readonly string $title,
        public readonly array $keywords,
        public readonly string $url,
        public readonly array $payload,
        public readonly string $status,
        public readonly string $updatedAt,
        public readonly int $documentVersion,
        public readonly string $payloadHash,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'indexer' => $this->indexer,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
            'channel_id' => $this->channelId,
            'locale' => $this->locale,
            'currency' => $this->currency,
            'title' => $this->title,
            'keywords' => $this->keywords,
            'url' => $this->url,
            'payload' => $this->payload,
            'status' => $this->status,
            'updated_at' => $this->updatedAt,
            'document_version' => $this->documentVersion,
            'payload_hash' => $this->payloadHash,
        ];
    }
}
