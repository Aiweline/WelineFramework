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
}
