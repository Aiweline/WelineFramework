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

    public function withVersion(int $documentVersion): self
    {
        return new self(
            indexer: $this->indexer,
            entityType: $this->entityType,
            entityId: $this->entityId,
            websiteId: $this->websiteId,
            storeId: $this->storeId,
            channelId: $this->channelId,
            locale: $this->locale,
            currency: $this->currency,
            title: $this->title,
            keywords: $this->keywords,
            url: $this->url,
            payload: $this->payload,
            status: $this->status,
            updatedAt: $this->updatedAt,
            documentVersion: $documentVersion,
            payloadHash: $this->payloadHash,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keywords = $data['keywords'] ?? [];
        $payload = $data['payload'] ?? [];

        return new self(
            indexer: (string)($data['indexer'] ?? ''),
            entityType: (string)($data['entity_type'] ?? ''),
            entityId: (string)($data['entity_id'] ?? ''),
            websiteId: (int)($data['website_id'] ?? 0),
            storeId: (int)($data['store_id'] ?? 0),
            channelId: (int)($data['channel_id'] ?? 0),
            locale: (string)($data['locale'] ?? ''),
            currency: (string)($data['currency'] ?? ''),
            title: (string)($data['title'] ?? ''),
            keywords: is_array($keywords) ? array_values(array_map('strval', $keywords)) : [],
            url: (string)($data['url'] ?? ''),
            payload: is_array($payload) ? $payload : [],
            status: (string)($data['status'] ?? ''),
            updatedAt: (string)($data['updated_at'] ?? ''),
            documentVersion: (int)($data['document_version'] ?? 1),
            payloadHash: (string)($data['payload_hash'] ?? ''),
        );
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
