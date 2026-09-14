<?php

declare(strict_types=1);

namespace Weline\Dropship\Api\Data;

/**
 * Provider → shell normalized catalog snapshot (shell owns persistence).
 *
 * Provider assembles catalog facts (media / variants / attributes / category path /
 * description). Shell publishes via ProductAdmin — never reads provider raw keys.
 */
final class DropshipCatalogSnapshot
{
    /**
     * @param array<string, mixed> $variants
     * @param array<string, mixed> $media
     * @param array<string, string|int|float|bool|null> $suggestedEav
     * @param list<array<string, mixed>> $attributes ProductAdmin attribute rows (attribute_code/value)
     * @param array{weight_kg?:float|int|string,length_cm?:float|int|string,width_cm?:float|int|string,height_cm?:float|int|string} $shipping
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
        public readonly string $categoryId = '',
        public readonly string $categoryPath = '',
        public readonly string $description = '',
        public readonly array $attributes = [],
        public readonly array $shipping = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $attributes = [];
        foreach ((array)($data['attributes'] ?? []) as $row) {
            if (is_array($row)) {
                $attributes[] = $row;
            }
        }

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
            categoryId: (string)($data['category_id'] ?? ''),
            categoryPath: (string)($data['category_path'] ?? ''),
            description: (string)($data['description'] ?? ''),
            attributes: $attributes,
            shipping: self::normalizeShipping((array)($data['shipping'] ?? [])),
            raw: (array)($data['raw'] ?? []),
        );
    }

    /**
     * @param array<string, mixed> $shipping
     * @return array{weight_kg:float,length_cm:float,width_cm:float,height_cm:float}
     */
    public static function normalizeShipping(array $shipping): array
    {
        $out = [
            'weight_kg' => 0.0,
            'length_cm' => 0.0,
            'width_cm' => 0.0,
            'height_cm' => 0.0,
        ];
        foreach (array_keys($out) as $key) {
            if (!array_key_exists($key, $shipping) || !is_numeric($shipping[$key])) {
                continue;
            }
            $n = (float)$shipping[$key];
            if ($n > 0) {
                $out[$key] = $n;
            }
        }

        return $out;
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
            'category_id' => $this->categoryId,
            'category_path' => $this->categoryPath,
            'description' => $this->description,
            'attributes' => $this->attributes,
            'shipping' => self::normalizeShipping($this->shipping),
            'raw' => $this->raw,
        ];
    }

    public function hasShippingDims(): bool
    {
        $s = self::normalizeShipping($this->shipping);

        return $s['weight_kg'] > 0
            && $s['length_cm'] > 0
            && $s['width_cm'] > 0
            && $s['height_cm'] > 0;
    }
}
