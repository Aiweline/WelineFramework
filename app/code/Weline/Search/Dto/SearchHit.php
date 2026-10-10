<?php

declare(strict_types=1);

namespace Weline\Search\Dto;

final class SearchHit
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $indexer,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly string $title,
        public readonly string $url,
        public readonly array $payload = [],
        public readonly float $score = 0.0,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'indexer' => $this->indexer,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'title' => $this->title,
            'url' => $this->url,
            'payload' => $this->payload,
            'score' => $this->score,
            'type' => $this->indexer,
        ] + $this->payload;
    }

    /** @return array<string, mixed> */
    public function toCacheArray(): array
    {
        return [
            'indexer' => $this->indexer,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'title' => $this->title,
            'url' => $this->url,
            'payload' => $this->payload,
            'score' => $this->score,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromCacheArray(array $data): self
    {
        return new self(
            indexer: (string)($data['indexer'] ?? ''),
            entityType: (string)($data['entity_type'] ?? ''),
            entityId: (string)($data['entity_id'] ?? ''),
            title: (string)($data['title'] ?? ''),
            url: (string)($data['url'] ?? ''),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            score: (float)($data['score'] ?? 0.0),
        );
    }
}
