<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Read-only publish candidate. Arrays are normalized by ProductAdminReadInterface.
 */
final readonly class ProductValidationContext
{
    /**
     * @param array<string, mixed> $product
     * @param list<array<string, mixed>> $offers
     * @param array<string, mixed>|list<array<string, mixed>> $attributes
     * @param list<array<string, mixed>> $prices
     * @param list<array<string, mixed>> $media
     * @param list<int> $storeIds
     * @param array<string, mixed> $typeConfiguration
     */
    public function __construct(
        public string $productType,
        public array $product,
        public array $offers,
        public array $attributes = [],
        public array $prices = [],
        public array $media = [],
        public array $storeIds = [],
        public array $typeConfiguration = [],
        public string $locale = '',
        public string $currency = 'CNY',
        public array $inventory = [],
        public array $stores = [],
    ) {
    }

    public function attribute(string $code, ?int $storeId = null): mixed
    {
        $storeId ??= count($this->storeIds) === 1 ? (int)$this->storeIds[0] : 0;
        return $this->attributeResolution($code, $storeId)['value'];
    }

    /**
     * @return array{found:bool,cleared:bool,value:mixed,store_id:?int,locale:string}
     */
    public function attributeResolution(string $code, int $storeId): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['found' => false, 'cleared' => false, 'value' => null, 'store_id' => null, 'locale' => ''];
        }
        if (array_key_exists($code, $this->attributes)) {
            return [
                'found' => true,
                'cleared' => false,
                'value' => $this->attributes[$code],
                'store_id' => 0,
                'locale' => $this->localeCode(),
            ];
        }

        $rows = [];
        foreach ($this->attributes as $row) {
            if (!is_array($row)
                || (string)($row['attribute_code'] ?? '') !== $code
                || (!empty($row['entity_type']) && (string)$row['entity_type'] !== 'product')
            ) {
                continue;
            }
            $rowStoreId = (int)($row['store_id'] ?? 0);
            $rowLocale = trim((string)($row['locale'] ?? ''));
            $rows[$rowStoreId . '|' . $rowLocale] = $row;
        }

        foreach ($this->attributeScopeChain($storeId) as [$scopeStoreId, $scopeLocale]) {
            $key = $scopeStoreId . '|' . $scopeLocale;
            if (!isset($rows[$key])) {
                continue;
            }
            $row = $rows[$key];
            $state = strtolower(trim((string)($row['scope_state'] ?? 'explicit')));
            if ($state === 'inherit') {
                continue;
            }
            $cleared = $state === 'cleared' || (int)($row['cleared'] ?? 0) === 1;
            return [
                'found' => true,
                'cleared' => $cleared,
                'value' => $cleared ? null : $this->attributeRowValue($row),
                'store_id' => $scopeStoreId,
                'locale' => $scopeLocale,
            ];
        }

        return ['found' => false, 'cleared' => false, 'value' => null, 'store_id' => null, 'locale' => ''];
    }

    public function localeCode(): string
    {
        return trim($this->locale);
    }

    public function currencyCode(): string
    {
        return strtoupper(trim($this->currency)) ?: 'CNY';
    }

    /** @return list<array<string, mixed>> */
    public function pricesForOffer(string $offerUuid): array
    {
        return array_values(array_filter(
            $this->prices,
            static fn (array $row): bool => (string)($row['global_offer_uuid'] ?? '') === $offerUuid,
        ));
    }

    /** @return array<string, mixed>|null */
    public function inventoryRow(int $storeId, string $offerUuid, int $offerId = 0): ?array
    {
        foreach ((array)($this->inventory['rows'] ?? []) as $row) {
            if (!is_array($row) || (int)($row['store_id'] ?? 0) !== $storeId) {
                continue;
            }
            if (($offerUuid !== '' && (string)($row['global_offer_uuid'] ?? '') === $offerUuid)
                || ($offerId > 0 && (int)($row['offer_id'] ?? 0) === $offerId)
            ) {
                return $row;
            }
        }
        return null;
    }

    public function storeLabel(int $storeId): string
    {
        if ($storeId === 0) {
            return 'Website';
        }
        foreach ($this->stores as $store) {
            if (!is_array($store) || (int)($store['store_id'] ?? 0) !== $storeId) {
                continue;
            }
            $label = trim((string)($store['name'] ?? $store['code'] ?? ''));
            return $label !== '' ? $label : 'Store #' . $storeId;
        }
        return 'Store #' . $storeId;
    }

    public function offerLabel(string $offerUuid): string
    {
        foreach ($this->offers as $offer) {
            if ((string)($offer['global_offer_uuid'] ?? '') !== $offerUuid) {
                continue;
            }
            $sku = trim((string)($offer['sku'] ?? ''));
            return $sku !== '' ? $sku : $offerUuid;
        }
        return $offerUuid;
    }

    public function offerUuidForId(int $offerId): string
    {
        foreach ($this->offers as $offer) {
            if ((int)($offer['offer_id'] ?? 0) === $offerId) {
                return (string)($offer['global_offer_uuid'] ?? '');
            }
        }
        return '';
    }

    /** @return list<array{int,string}> */
    private function attributeScopeChain(int $storeId): array
    {
        $locale = $this->localeCode();
        $candidates = [];
        if ($storeId > 0) {
            if ($locale !== '') {
                $candidates[] = [$storeId, $locale];
            }
            $candidates[] = [$storeId, ''];
        }
        if ($locale !== '') {
            $candidates[] = [0, $locale];
        }
        $candidates[] = [0, ''];

        $seen = [];
        $result = [];
        foreach ($candidates as $candidate) {
            $key = $candidate[0] . '|' . $candidate[1];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $candidate;
        }
        return $result;
    }

    /** @param array<string, mixed> $row */
    private function attributeRowValue(array $row): mixed
    {
        foreach ([
            'value',
            'value_string',
            'value_text',
            'value_number',
            'value_boolean',
            'value_date',
            'value_json',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                return $row[$key];
            }
        }
        return null;
    }
}
