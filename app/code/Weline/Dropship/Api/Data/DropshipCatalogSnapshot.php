<?php

declare(strict_types=1);

namespace Weline\Dropship\Api\Data;

/**
 * Provider → shell normalized catalog snapshot (shell owns persistence).
 */
final class DropshipCatalogSnapshot
{
    /**
     * @param array<string, mixed> $variants
     * @param array<string, mixed> $media
     * @param array<string, string|int|float|bool|null> $suggestedEav
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $providerCode,
        public readonly string $externalSpu,
        public readonly string $title,
        public readonly string $originCurrency,
        public readonly int $originPriceMinor,
        public readonly int $qty,
        public readonly string $shelfStatus,
        public readonly array $variants = [],
        public readonly array $media = [],
        public readonly array $suggestedEav = [],
        public readonly string $externalSku = '',
        public readonly string $countryCode = '',
        public readonly string $storageId = '',
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            providerCode: (string)($data['provider_code'] ?? ''),
            externalSpu: (string)($data['external_spu'] ?? ''),
            title: (string)($data['title'] ?? ''),
            originCurrency: (string)($data['origin_currency'] ?? 'USD'),
            originPriceMinor: (int)($data['origin_price_minor'] ?? 0),
            qty: (int)($data['qty'] ?? 0),
            shelfStatus: (string)($data['shelf_status'] ?? 'active'),
            variants: (array)($data['variants'] ?? []),
            media: (array)($data['media'] ?? []),
            suggestedEav: (array)($data['suggested_eav'] ?? []),
            externalSku: (string)($data['external_sku'] ?? ''),
            countryCode: (string)($data['country_code'] ?? ''),
            storageId: (string)($data['storage_id'] ?? ''),
            raw: (array)($data['raw'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_code' => $this->providerCode,
            'external_spu' => $this->externalSpu,
            'external_sku' => $this->externalSku,
            'title' => $this->title,
            'origin_currency' => $this->originCurrency,
            'origin_price_minor' => $this->originPriceMinor,
            'qty' => $this->qty,
            'shelf_status' => $this->shelfStatus,
            'variants' => $this->variants,
            'media' => $this->media,
            'suggested_eav' => $this->suggestedEav,
            'country_code' => $this->countryCode,
            'storage_id' => $this->storageId,
            'raw' => $this->raw,
        ];
    }
}
