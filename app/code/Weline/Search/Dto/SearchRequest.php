<?php

declare(strict_types=1);

namespace Weline\Search\Dto;

/**
 * Server-owned Scope + allowlisted client params.
 */
final class SearchRequest
{
    /**
     * @param array<string, mixed> $extras Provider-declared client params (e.g. category_id)
     */
    public function __construct(
        public readonly string $q,
        public readonly string $type,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly int $websiteId,
        public readonly int $storeId,
        public readonly int $channelId,
        public readonly string $locale,
        public readonly string $currency,
        public readonly array $extras = [],
    ) {
    }

    public function isAllTypes(): bool
    {
        return $this->type === '' || $this->type === 'all';
    }

    /** @return array<string, mixed> */
    public function toLogContext(): array
    {
        return [
            'q' => $this->q,
            'type' => $this->isAllTypes() ? 'all' : $this->type,
            'page' => $this->page,
            'page_size' => $this->pageSize,
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
            'channel_id' => $this->channelId,
            'extras' => $this->extras,
        ];
    }
}
