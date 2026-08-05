<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

final class ScopeSnapshot
{
    public function __construct(
        public readonly int $websiteId = 0,
        public readonly int $storeId = 0,
        public readonly string $currency = 'CNY',
        public readonly string $locale = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
            'currency' => $this->currency,
            'locale' => $this->locale,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            websiteId: (int)($data['website_id'] ?? 0),
            storeId: (int)($data['store_id'] ?? 0),
            currency: (string)($data['currency'] ?? 'CNY'),
            locale: (string)($data['locale'] ?? ''),
        );
    }
}
