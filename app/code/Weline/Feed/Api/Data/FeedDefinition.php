<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

final readonly class FeedDefinition
{
    public function __construct(
        public int $definitionId,
        public int $websiteId,
        public string $sourceCode,
        public string $channelCode,
        public string $locale,
        public string $currency,
        public string $title,
        public bool $enabled,
        public string $connectionMode,
        public int $accountId,
        public string $publicToken,
        public string $filterJson = '',
        public ?string $lastGeneratedAt = null,
        public bool $archived = false,
    ) {
    }

    public function country(): string
    {
        return strtoupper(trim((string)($this->filter()['country'] ?? '')));
    }

    public function storeId(): int
    {
        $filter = $this->filter();

        return max(0, (int)($filter['store_id'] ?? 0));
    }

    public function sellingMode(): string
    {
        $filter = $this->filter();

        return trim((string)($filter['selling_mode'] ?? ''));
    }

    /**
     * One account-level shipping amount. Null means the operator has not set it.
     */
    public function accountShipping(): ?string
    {
        $raw = trim((string)($this->filter()['shipping'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return $raw;
    }

    public function withShippingAmount(string $amount): self
    {
        $amount = trim($amount);
        if ($amount === '' || !is_numeric($amount) || $this->accountShipping() !== null) {
            return $this;
        }
        $filter = $this->filter();
        $filter['shipping'] = $amount;
        $encoded = json_encode($filter, JSON_UNESCAPED_UNICODE);

        return new self(
            definitionId: $this->definitionId,
            websiteId: $this->websiteId,
            sourceCode: $this->sourceCode,
            channelCode: $this->channelCode,
            locale: $this->locale,
            currency: $this->currency,
            title: $this->title,
            enabled: $this->enabled,
            connectionMode: $this->connectionMode,
            accountId: $this->accountId,
            publicToken: $this->publicToken,
            filterJson: is_string($encoded) ? $encoded : $this->filterJson,
            lastGeneratedAt: $this->lastGeneratedAt,
            archived: $this->archived,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function filter(): array
    {
        if (trim($this->filterJson) === '') {
            return [];
        }
        $decoded = json_decode($this->filterJson, true);

        return is_array($decoded) ? $decoded : [];
    }
}
