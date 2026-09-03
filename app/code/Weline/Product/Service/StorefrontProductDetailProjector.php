<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\ResolvedScopeValue;
use Weline\Product\Model\Shard\Media;
use Weline\Framework\Manager\ObjectManager;

/**
 * Pure storefront projection for public Product details.
 *
 * Attribute rows have already been bounded to one Product and its active
 * Website/Store layers. This projector applies the catalog overlay contract,
 * removes import-only metadata and returns a template-safe data shape.
 */
final class StorefrontProductDetailProjector
{
    private const CONTENT_CODES = [
        'name',
        'short_description',
        'description',
        'meta_name',
        'meta_description',
        'meta_keywords',
    ];
    private const INTERNAL_CODES = [
        'quote_only',
        'slug',
        'attribute_set',
        'attribute_set_label',
        'type_configuration',
        'product_type',
        'reference_source',
        'cost',
        'spu',
        'brand',
        'brand_code',
        'barcode',
        'visibility',
        'length_cm',
        'width_cm',
        'height_cm',
        'weight_kg',
    ];

    private ?StorefrontEavLabelResolver $eavLabels;
    private ?StorefrontVariantAxisResolver $variantAxisResolverInstance = null;

    public function __construct(
        private readonly CatalogOverlayResolver $resolver = new CatalogOverlayResolver(),
        ?StorefrontEavLabelResolver $eavLabels = null,
        private readonly ?StorefrontVariantAxisResolver $variantAxes = null,
    ) {
        $this->eavLabels = $eavLabels;
    }

    /**
     * @param array<string, mixed> $offer
     * @param list<array<string, mixed>> $attributeRows
     * @param list<array<string, mixed>> $mediaRows
     * @param list<string> $localeFallbacks
     * @return array<string, mixed>
     */
    public function project(
        array $offer,
        array $attributeRows,
        array $mediaRows,
        int $storeId,
        string $locale,
        array $localeFallbacks = [''],
    ): array {
        $byCode = [];
        $productByCode = [];
        foreach ($attributeRows as $row) {
            $code = strtolower(trim((string)($row['attribute_code'] ?? '')));
            if ($code !== '') {
                $byCode[$code][] = $row;
                if ((string)($row['entity_type'] ?? 'product') === 'product') {
                    $productByCode[$code][] = $row;
                }
            }
        }

        $resolved = [];
        foreach ($byCode as $code => $rows) {
            $value = $this->resolvePresentableAttribute(
                $rows,
                $storeId,
                $locale,
                $localeFallbacks,
            );
            if (!$value->isExplicit()) {
                continue;
            }
            $normalized = $this->normalizeResolvedValue($value->value);
            if ($normalized !== '') {
                $resolved[$code] = $normalized;
            }
        }
        $availableValues = [];
        foreach ($productByCode as $code => $rows) {
            $value = $this->resolvePresentableAttribute(
                $rows,
                $storeId,
                $locale,
                $localeFallbacks,
            );
            if ($value->isExplicit()) {
                $availableValues[$code] = $value->value;
            }
        }

        unset($resolved['type_configuration']);
        $resolved = $this->mergeOfferCombination($offer, $resolved);
        $combination = $this->extractOfferCombination($offer);

        $specifications = [];
        foreach ($resolved as $code => $value) {
            if (in_array($code, self::CONTENT_CODES, true)
                || in_array($code, self::INTERNAL_CODES, true)
                || str_starts_with($code, 'source_')
                || str_starts_with($code, 'spec_')
            ) {
                continue;
            }
            $displayValue = $this->labels()->resolve($code, $value);
            if ($this->containsUnsupportedHanText($displayValue, $locale)) {
                continue;
            }
            $specifications[] = [
                'code' => $code,
                'value' => $displayValue,
            ];
        }
        usort(
            $specifications,
            static fn(array $left, array $right): int => $left['code'] <=> $right['code'],
        );

        usort(
            $mediaRows,
            static fn(array $left, array $right): int => [
                (int)($left[Media::schema_fields_POSITION] ?? 0),
                (int)($left[Media::schema_fields_ID] ?? 0),
            ] <=> [
                (int)($right[Media::schema_fields_POSITION] ?? 0),
                (int)($right[Media::schema_fields_ID] ?? 0),
            ],
        );
        $offerCombinationKey = trim((string)($offer['combination_key'] ?? ''));
        if ($offerCombinationKey === '' && $combination !== []) {
            $segments = [];
            foreach ($combination as $axis => $value) {
                $segments[] = rawurlencode($axis) . '=' . rawurlencode($value);
            }
            $offerCombinationKey = implode('|', $segments);
        }

        $baseImages = [];
        $variantImages = [];
        $variantSwatches = [];
        $offerImage = trim((string)($offer['image'] ?? ''));
        foreach ($mediaRows as $media) {
            $path = trim((string)($media[Media::schema_fields_PATH] ?? ''));
            if ($path === '') {
                continue;
            }
            $mediaCombinationKey = trim((string)($media[Media::schema_fields_COMBINATION_KEY] ?? ''));
            if ($mediaCombinationKey === '') {
                $baseImages[$path] = true;
                continue;
            }
            if (strtolower(trim((string)($media[Media::schema_fields_ROLE] ?? ''))) !== 'variant') {
                continue;
            }
            foreach ($this->extractOfferCombination(['combination_key' => $mediaCombinationKey]) as $axis => $value) {
                if ($axis !== 'size' && !isset($variantSwatches[$axis][$value])) {
                    $variantSwatches[$axis][$value] = $path;
                }
            }
            if ($mediaCombinationKey === $offerCombinationKey) {
                $variantImages[$path] = true;
            }
        }

        $images = [];
        foreach (array_keys($variantImages) as $path) {
            $images[$path] = true;
        }
        if ($offerImage !== '') {
            $images[$offerImage] = true;
        }
        foreach (array_keys($baseImages) as $path) {
            $images[$path] = true;
        }
        $imageList = array_keys($images);
        $primaryImage = array_key_first($variantImages)
            ?? ($offerImage !== '' ? $offerImage : ($imageList[0] ?? ''));

        $localizedName = trim((string)($resolved['name'] ?? ''));
        $displayName = $localizedName !== ''
            ? $localizedName
            : trim((string)($offer['name'] ?? ''));
        if (!$this->isHanTextLocale($locale)
            && ($displayName === '' || $this->containsUnsupportedHanText($displayName, $locale))
        ) {
            $displayName = $this->neutralProductName($offer);
        }
        $slug = $this->normalizeSlug(
            (string)($resolved['source_slug'] ?? $resolved['slug'] ?? $offer['slug'] ?? ''),
        );

        $variantAxes = $this->variantAxisResolver()->buildAxes(
            $resolved,
            $availableValues,
        );
        foreach ($variantAxes as &$axis) {
            $axisCode = strtolower(trim((string)($axis['code'] ?? '')));
            if ($axisCode === '' || $axisCode === 'size') {
                continue;
            }
            foreach ($axis['options'] as &$option) {
                $value = trim((string)($option['value'] ?? ''));
                $swatchImage = $variantSwatches[$axisCode][$value] ?? '';
                if ($swatchImage !== '') {
                    $option['swatch_image'] = $swatchImage;
                }
            }
            unset($option);
        }
        unset($axis);

        return array_merge($offer, [
            'name' => $displayName,
            'short_description' => $resolved['short_description'] ?? '',
            'description' => $resolved['description'] ?? '',
            'meta_name' => $resolved['meta_name'] ?? '',
            'meta_description' => $resolved['meta_description'] ?? '',
            'meta_keywords' => $resolved['meta_keywords'] ?? '',
            'slug' => $slug,
            'brand' => trim((string)($resolved['brand'] ?? '')),
            'brand_code' => trim((string)($resolved['brand_code'] ?? '')),
            'attribute_set' => $resolved['attribute_set'] ?? '',
            'attribute_set_label' => $resolved['attribute_set_label'] ?? '',
            'quote_only' => ($resolved['quote_only'] ?? '0') === '1',
            'variant_axes' => $variantAxes,
            'combination' => $combination,
            'combination_key' => trim((string)($offer['combination_key'] ?? '')),
            'specifications' => $specifications,
            'images' => $imageList,
            'image' => $primaryImage !== '' ? $primaryImage : ($imageList[0] ?? ''),
        ]);
    }

    private function normalizeResolvedValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return trim((string)$value);
        }
        if (is_array($value)) {
            $values = [];
            foreach ($value as $item) {
                if (is_scalar($item) && trim((string)$item) !== '') {
                    $values[] = trim((string)$item);
                }
            }
            return implode(', ', $values);
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $offer
     * @param array<string, string> $resolved
     * @return array<string, string>
     */
    private function mergeOfferCombination(array $offer, array $resolved): array
    {
        foreach ($this->extractOfferCombination($offer) as $code => $value) {
            $resolved[$code] = $value;
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, string>
     */
    private function extractOfferCombination(array $offer): array
    {
        $combination = $offer['combination'] ?? null;
        if (is_array($combination) && $combination !== []) {
            $normalized = [];
            foreach ($combination as $axis => $value) {
                $axis = strtolower(trim((string)$axis));
                $value = trim((string)$value);
                if ($axis !== '' && $value !== '') {
                    $normalized[$axis] = $value;
                }
            }
            ksort($normalized, SORT_STRING);

            return $normalized;
        }

        $key = trim((string)($offer['combination_key'] ?? ''));
        if ($key === '') {
            return [];
        }

        $normalized = [];
        foreach (explode('|', $key) as $segment) {
            if (!str_contains($segment, '=')) {
                continue;
            }
            [$axis, $value] = explode('=', $segment, 2);
            $axis = strtolower(trim(rawurldecode($axis)));
            $value = trim(rawurldecode($value));
            if ($axis !== '' && $value !== '') {
                $normalized[$axis] = $value;
            }
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function variantAxisResolver(): StorefrontVariantAxisResolver
    {
        return $this->variantAxisResolverInstance
            ??= $this->variantAxes ?? ObjectManager::getInstance(StorefrontVariantAxisResolver::class);
    }

    private function labels(): StorefrontEavLabelResolver
    {
        return $this->eavLabels ??= ObjectManager::getInstance(StorefrontEavLabelResolver::class);
    }

    /**
     * Skip an incompatible Han-script fallback and continue along the already
     * approved locale / scope chain. A cleared row still terminates inheritance.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $localeFallbacks
     */
    private function resolvePresentableAttribute(
        array $rows,
        int $storeId,
        string $locale,
        array $localeFallbacks,
    ): ResolvedScopeValue {
        $remainingRows = array_values($rows);
        $attemptLimit = count($remainingRows) + 1;
        for ($attempt = 0; $attempt < $attemptLimit; $attempt++) {
            $value = $this->resolver->resolveAttribute(
                $remainingRows,
                $storeId,
                $locale,
                $localeFallbacks,
            );
            if (!$value->isExplicit()) {
                return $value;
            }
            if (!$this->containsUnsupportedHanText(
                $this->normalizeResolvedValue($value->value),
                $locale,
            )) {
                return $value;
            }

            $before = count($remainingRows);
            $remainingRows = array_values(array_filter(
                $remainingRows,
                static fn(array $row): bool => (int)($row['store_id'] ?? 0) !== $value->resolvedStoreId
                    || strcasecmp(
                        trim((string)($row['locale'] ?? '')),
                        $value->resolvedLocale,
                    ) !== 0,
            ));
            if (count($remainingRows) === $before) {
                break;
            }
        }

        return ResolvedScopeValue::unresolved();
    }

    private function containsUnsupportedHanText(string $value, string $locale): bool
    {
        $locale = trim($locale);

        return $value !== ''
            && $locale !== ''
            && !$this->isHanTextLocale($locale)
            && preg_match('/\p{Han}/u', $value) === 1;
    }

    private function isHanTextLocale(string $locale): bool
    {
        $language = strtolower(explode('_', str_replace('-', '_', trim($locale)), 2)[0]);

        return in_array($language, ['zh', 'ja', 'ko'], true);
    }

    /** @param array<string, mixed> $offer */
    private function neutralProductName(array $offer): string
    {
        $sku = trim((string)($offer['sku'] ?? ''));
        if ($sku !== '' && preg_match('/[\p{Han}\x00-\x1F\x7F]/u', $sku) !== 1) {
            return 'Product ' . $sku;
        }

        $productId = max(0, (int)($offer['product_id'] ?? 0));

        return $productId > 0 ? 'Product #' . $productId : 'Product';
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) !== 1) {
            return '';
        }

        return $slug;
    }
}
