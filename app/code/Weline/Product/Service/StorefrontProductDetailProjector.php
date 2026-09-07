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

    /** @var array<int, StorefrontEavLabelResolver> */
    private array $labelsByProductId = [];

    /** @var array<int, StorefrontVariantAxisResolver> */
    private array $axesByProductId = [];

    /** @var array<string, mixed>|null Temporary request-local context for one-product PDP batches. */
    private ?array $bulkProjectionContext = null;

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
        return $this->projectFields($offer, $attributeRows, $mediaRows, $storeId, $locale, $localeFallbacks, true);
    }

    /** @param list<int> $productIds */
    public function prefetchForProducts(array $productIds): void
    {
        try {
            \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
                'product.catalog.metadata_prefetch',
                fn() => $this->labels()->prefetchForProducts($productIds),
                ['products' => count($productIds)],
            );
        } catch (\Throwable) {
            // Keep the existing per-field metadata fallback; a failed prefetch is not cached.
        }
    }

    /**
     * Catalog cards retain localized specification values for attribute filters,
     * but do not need the PDP's full set of selectable variant options.
     *
     * @param array<string, mixed> $offer
     * @param list<array<string, mixed>> $attributeRows
     * @param list<string> $localeFallbacks
     * @return array<string, mixed>
     */
    public function projectListing(
        array $offer,
        array $attributeRows,
        int $storeId,
        string $locale,
        array $localeFallbacks = [''],
    ): array {
        return $this->projectFields($offer, $attributeRows, [], $storeId, $locale, $localeFallbacks, false);
    }

    /**
     * Project several offers belonging to one product while reusing the
     * product-level EAV and media work. A configurable PDP often has dozens of
     * offers, but their metadata, specifications and base gallery are shared;
     * only the combination/selected image changes per offer.
     *
     * @param list<array<string, mixed>> $offers
     * @param list<array<string, mixed>> $attributeRows
     * @param list<array<string, mixed>> $mediaRows
     * @param list<string> $localeFallbacks
     * @return list<array<string, mixed>>
     */
    public function projectMany(
        array $offers,
        array $attributeRows,
        array $mediaRows,
        int $storeId,
        string $locale,
        array $localeFallbacks = [''],
    ): array {
        if ($offers === []) {
            return [];
        }
        if (count($offers) === 1) {
            return [$this->project($offers[0], $attributeRows, $mediaRows, $storeId, $locale, $localeFallbacks)];
        }

        $productIds = [];
        foreach ($offers as $offer) {
            $productIds[(int)($offer['product_id'] ?? 0)] = true;
        }
        if (count($productIds) !== 1) {
            return array_values(array_map(
                fn(array $offer): array => $this->project(
                    $offer,
                    $attributeRows,
                    $mediaRows,
                    $storeId,
                    $locale,
                    $localeFallbacks,
                ),
                $offers,
            ));
        }

        $this->bulkProjectionContext = $this->prepareBulkProjectionContext(
            $offers[0],
            $attributeRows,
            $mediaRows,
            $storeId,
            $locale,
            $localeFallbacks,
        );
        try {
            $projected = [];
            foreach ($offers as $offer) {
                $projected[] = $this->project(
                    $offer,
                    $attributeRows,
                    $mediaRows,
                    $storeId,
                    $locale,
                    $localeFallbacks,
                );
            }

            return $projected;
        } finally {
            $this->bulkProjectionContext = null;
        }
    }

    /**
     * Project only the facts required by a catalog card.
     *
     * The normal listing projection also materializes localized specifications
     * and option metadata for attribute facets. The default catalog page does
     * not need those fields, so this bounded path resolves only name/slug rows
     * and keeps the card contract without touching EAV metadata.
     *
     * @param array<string, mixed> $offer
     * @param list<array<string, mixed>> $attributeRows
     * @param list<string> $localeFallbacks
     * @return array<string, mixed>
     */
    public function projectListingSummary(
        array $offer,
        array $attributeRows,
        int $storeId,
        string $locale,
        array $localeFallbacks = [''],
    ): array {
        // The default catalog path intentionally skips product EAV detail rows.
        // In that mode the snapshot already contains the resolved card name and
        // image, so rebuilding an empty overlay index for every product only
        // adds CPU cost without changing the result.
        if ($attributeRows === []) {
            $displayName = trim((string)($offer['name'] ?? ''));
            if (!$this->isHanTextLocale($locale)
                && ($displayName === '' || $this->containsUnsupportedHanText($displayName, $locale))
            ) {
                $displayName = $this->neutralProductName($offer);
            }

            $combination = $this->extractOfferCombination($offer);
            $combinationKey = trim((string)($offer['combination_key'] ?? ''));
            if ($combinationKey === '' && $combination !== []) {
                $segments = [];
                foreach ($combination as $axis => $value) {
                    $segments[] = rawurlencode($axis) . '=' . rawurlencode($value);
                }
                $combinationKey = implode('|', $segments);
            }
            $image = trim((string)($offer['image'] ?? ''));

            return array_merge($offer, [
                'name' => $displayName,
                'short_description' => (string)($offer['short_description'] ?? ''),
                'description' => (string)($offer['description'] ?? ''),
                'meta_name' => (string)($offer['meta_name'] ?? ''),
                'meta_description' => (string)($offer['meta_description'] ?? ''),
                'meta_keywords' => (string)($offer['meta_keywords'] ?? ''),
                'slug' => $this->normalizeSlug((string)($offer['slug'] ?? '')),
                'brand' => trim((string)($offer['brand'] ?? '')),
                'brand_code' => trim((string)($offer['brand_code'] ?? '')),
                'attribute_set' => $offer['attribute_set'] ?? '',
                'attribute_set_label' => $offer['attribute_set_label'] ?? '',
                'quote_only' => !empty($offer['quote_only']),
                'variant_axes' => [],
                'combination' => $combination,
                'combination_key' => $combinationKey,
                'specifications' => [],
                'images' => $image !== '' ? [$image] : [],
                'image' => $image,
                'videos' => [],
            ]);
        }

        $rowsByCode = [
            'name' => [],
            'slug' => [],
            'source_slug' => [],
        ];
        foreach ($attributeRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtolower(trim((string)($row['attribute_code'] ?? '')));
            if (isset($rowsByCode[$code])) {
                $rowsByCode[$code][] = $row;
            }
        }

        $resolvedName = $this->resolvePresentableAttribute(
            $rowsByCode['name'],
            $storeId,
            $locale,
            $localeFallbacks,
        );
        $displayName = $resolvedName->isExplicit()
            ? $this->normalizeResolvedValue($resolvedName->value)
            : trim((string)($offer['name'] ?? ''));
        if (!$this->isHanTextLocale($locale)
            && ($displayName === '' || $this->containsUnsupportedHanText($displayName, $locale))
        ) {
            $displayName = $this->neutralProductName($offer);
        }

        $resolvedSlug = $this->resolvePresentableAttribute(
            $rowsByCode['source_slug'] !== [] ? $rowsByCode['source_slug'] : $rowsByCode['slug'],
            $storeId,
            $locale,
            $localeFallbacks,
        );
        $slug = $this->normalizeSlug(
            $resolvedSlug->isExplicit()
                ? $this->normalizeResolvedValue($resolvedSlug->value)
                : (string)($offer['slug'] ?? ''),
        );
        $combination = $this->extractOfferCombination($offer);
        $combinationKey = trim((string)($offer['combination_key'] ?? ''));
        if ($combinationKey === '' && $combination !== []) {
            $segments = [];
            foreach ($combination as $axis => $value) {
                $segments[] = rawurlencode($axis) . '=' . rawurlencode($value);
            }
            $combinationKey = implode('|', $segments);
        }
        $image = trim((string)($offer['image'] ?? ''));

        return array_merge($offer, [
            'name' => $displayName,
            'short_description' => (string)($offer['short_description'] ?? ''),
            'description' => (string)($offer['description'] ?? ''),
            'meta_name' => (string)($offer['meta_name'] ?? ''),
            'meta_description' => (string)($offer['meta_description'] ?? ''),
            'meta_keywords' => (string)($offer['meta_keywords'] ?? ''),
            'slug' => $slug,
            'brand' => trim((string)($offer['brand'] ?? '')),
            'brand_code' => trim((string)($offer['brand_code'] ?? '')),
            'attribute_set' => $offer['attribute_set'] ?? '',
            'attribute_set_label' => $offer['attribute_set_label'] ?? '',
            'quote_only' => !empty($offer['quote_only']),
            'variant_axes' => [],
            'combination' => $combination,
            'combination_key' => $combinationKey,
            'specifications' => [],
            'images' => $image !== '' ? [$image] : [],
            'image' => $image,
            'videos' => [],
        ]);
    }

    private function projectFields(
        array $offer,
        array $attributeRows,
        array $mediaRows,
        int $storeId,
        string $locale,
        array $localeFallbacks,
        bool $includeVariantAxes,
    ): array {
        $bulk = $this->bulkProjectionContext;
        if (is_array($bulk)) {
            $resolved = $this->mergeOfferCombination(
                $offer,
                is_array($bulk['resolved_base'] ?? null) ? $bulk['resolved_base'] : [],
            );
            $availableValues = is_array($bulk['available_values'] ?? null)
                ? $bulk['available_values']
                : [];
        } else {
            $byCode = [];
            $productByCode = [];
            foreach ($attributeRows as $row) {
                $code = strtolower(trim((string)($row['attribute_code'] ?? '')));
                if ($code !== '') {
                    $byCode[$code][] = $row;
                    if ($includeVariantAxes && (string)($row['entity_type'] ?? 'product') === 'product') {
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
                // Variant axis option lists are identity values (often Han source codes),
                // not translated copy. Skipping Han here drops the axis on en_US and
                // leaves character chips without media-backed swatch_image.
                $value = $this->resolvePresentableAttribute(
                    $rows,
                    $storeId,
                    $locale,
                    $localeFallbacks,
                    true,
                );
                if ($value->isExplicit()) {
                    $availableValues[$code] = $value->value;
                }
            }

            unset($resolved['type_configuration']);
            $resolved = $this->mergeOfferCombination($offer, $resolved);
        }
        $combination = $this->extractOfferCombination($offer);
        $productId = max(0, (int)($offer['product_id'] ?? 0));
        // Reuse scoped resolvers across many offers of the same product. Calling
        // forProduct() on the unscoped singleton always clones and clears private
        // option caches — catastrophic for 100+ SKU PDPs with value_json axes.
        $labels = $bulk['labels'] ?? null;
        if (!$labels instanceof StorefrontEavLabelResolver) {
            $labels = null;
        }

        $specifications = [];
        if (is_array($bulk)) {
            // Product-level attributes are identical for every offer in a
            // configurable PDP. Resolve their labels once in the shared bulk
            // context and only recompute values overridden by this offer's
            // combination (usually color/size). This removes dozens of repeated
            // option-label lookups while preserving the per-offer shape.
            $specificationsByCode = is_array($bulk['base_specifications'] ?? null)
                ? $bulk['base_specifications']
                : [];
            foreach ($combination as $code => $value) {
                if (!$this->isPublicSpecificationCode($code)) {
                    continue;
                }
                $labels ??= $this->labelsForProduct($productId);
                $specificationsByCode[$code] = [
                    'code' => $code,
                    'label' => $labels->attributeLabel($code),
                    'value' => $labels->resolve($code, $value),
                ];
            }
            $specifications = array_values($specificationsByCode);
        } else {
            foreach ($resolved as $code => $value) {
                if (!$this->isPublicSpecificationCode($code)) {
                    continue;
                }
                // Prefer EAV option/attribute LocalDescription via labels(); when the
                // current locale has no local name, keep the default/source text — do
                // not drop the whole specification row just because it still contains Han.
                $labels ??= $this->labelsForProduct($productId);
                $displayValue = $labels->resolve($code, $value);
                $specifications[] = [
                    'code' => $code,
                    'label' => $labels->attributeLabel($code),
                    'value' => $displayValue,
                ];
            }
        }
        usort(
            $specifications,
            static fn(array $left, array $right): int => $left['code'] <=> $right['code'],
        );

        $offerCombinationKey = trim((string)($offer['combination_key'] ?? ''));
        if ($offerCombinationKey === '' && $combination !== []) {
            $segments = [];
            foreach ($combination as $axis => $value) {
                $segments[] = rawurlencode($axis) . '=' . rawurlencode($value);
            }
            $offerCombinationKey = implode('|', $segments);
        }
        $offerImage = trim((string)($offer['image'] ?? ''));

        if (is_array($bulk)) {
            $baseImages = is_array($bulk['base_images'] ?? null) ? $bulk['base_images'] : [];
            $baseVideos = is_array($bulk['base_videos'] ?? null) ? $bulk['base_videos'] : [];
            $variantSwatches = is_array($bulk['variant_swatches'] ?? null) ? $bulk['variant_swatches'] : [];
            $variantImagesByCombination = is_array($bulk['variant_images'] ?? null)
                ? $bulk['variant_images']
                : [];
            $variantImages = [];
            foreach (array_keys((array)($variantImagesByCombination[$offerCombinationKey] ?? [])) as $path) {
                $path = trim((string)$path);
                if ($path !== '') {
                    $variantImages[$path] = true;
                }
            }
        } else {
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
            $baseImages = [];
            $baseVideos = [];
            $variantImages = [];
            $variantSwatches = [];
            foreach ($mediaRows as $media) {
                $path = trim((string)($media[Media::schema_fields_PATH] ?? ''));
                if ($path === '') {
                    continue;
                }
                $role = strtolower(trim((string)($media[Media::schema_fields_ROLE] ?? '')));
                $mediaCombinationKey = trim((string)($media[Media::schema_fields_COMBINATION_KEY] ?? ''));
                if ($role === 'video' && $mediaCombinationKey === '') {
                    $video = $this->projectVideoItem($media);
                    if ($video !== null) {
                        $baseVideos[$path] = $video;
                    }
                    continue;
                }
                if ($mediaCombinationKey === '') {
                    if (in_array($role, ['main', 'gallery', ''], true) || !str_starts_with($path, 'video://')) {
                        $baseImages[$path] = true;
                    }
                    continue;
                }
                if ($role !== 'variant') {
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
        $videos = array_values($baseVideos);

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

        $variantAxes = [];
        if ($includeVariantAxes) {
            if (is_array($bulk) && is_array($bulk['variant_axes'] ?? null)) {
                $variantAxes = $bulk['variant_axes'];
                foreach ($variantAxes as &$axis) {
                    if (!is_array($axis)) {
                        continue;
                    }
                    $axisCode = strtolower(trim((string)($axis['code'] ?? '')));
                    $selected = $this->firstAxisValue($resolved[$axisCode] ?? '');
                    $axis['value'] = $selected;
                    $axis['value_label'] = $selected !== '' && $labels instanceof StorefrontEavLabelResolver
                        ? $labels->resolve($axisCode, $selected)
                        : '';
                }
                unset($axis);
            } elseif ($availableValues !== []) {
                $variantAxes = $this->axesForProduct($productId)->buildAxes($resolved, $availableValues);
            }
        }
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
            'videos' => $videos,
        ]);
    }

    /**
     * @param array<string, mixed> $offer
     * @param list<array<string, mixed>> $attributeRows
     * @param list<array<string, mixed>> $mediaRows
     * @param list<string> $localeFallbacks
     * @return array<string, mixed>
     */
    private function prepareBulkProjectionContext(
        array $offer,
        array $attributeRows,
        array $mediaRows,
        int $storeId,
        string $locale,
        array $localeFallbacks,
    ): array {
        $byCode = [];
        $productByCode = [];
        foreach ($attributeRows as $row) {
            $code = strtolower(trim((string)($row['attribute_code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $byCode[$code][] = $row;
            if ((string)($row['entity_type'] ?? 'product') === 'product') {
                $productByCode[$code][] = $row;
            }
        }

        $resolvedBase = [];
        foreach ($byCode as $code => $rows) {
            $value = $this->resolvePresentableAttribute($rows, $storeId, $locale, $localeFallbacks);
            if (!$value->isExplicit()) {
                continue;
            }
            $normalized = $this->normalizeResolvedValue($value->value);
            if ($normalized !== '') {
                $resolvedBase[$code] = $normalized;
            }
        }

        $availableValues = [];
        foreach ($productByCode as $code => $rows) {
            // Variant axis option lists are identity values (often Han source codes),
            // not translated copy. Skipping Han here drops the axis on en_US and
            // leaves character chips without media-backed swatch_image.
            $value = $this->resolvePresentableAttribute(
                $rows,
                $storeId,
                $locale,
                $localeFallbacks,
                true,
            );
            if ($value->isExplicit()) {
                $availableValues[$code] = $value->value;
            }
        }
        unset($resolvedBase['type_configuration']);

        $productId = max(0, (int)($offer['product_id'] ?? 0));
        $labels = $this->labelsForProduct($productId);

        $baseSpecifications = [];
        foreach ($resolvedBase as $code => $value) {
            if (!$this->isPublicSpecificationCode($code)) {
                continue;
            }
            $baseSpecifications[$code] = [
                'code' => $code,
                'label' => $labels->attributeLabel($code),
                'value' => $labels->resolve($code, $value),
            ];
        }

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
        $baseImages = [];
        $baseVideos = [];
        $variantImages = [];
        $variantSwatches = [];
        foreach ($mediaRows as $media) {
            $path = trim((string)($media[Media::schema_fields_PATH] ?? ''));
            if ($path === '') {
                continue;
            }
            $role = strtolower(trim((string)($media[Media::schema_fields_ROLE] ?? '')));
            $mediaCombinationKey = trim((string)($media[Media::schema_fields_COMBINATION_KEY] ?? ''));
            if ($role === 'video' && $mediaCombinationKey === '') {
                $video = $this->projectVideoItem($media);
                if ($video !== null) {
                    $baseVideos[$path] = $video;
                }
                continue;
            }
            if ($mediaCombinationKey === '') {
                if (in_array($role, ['main', 'gallery', ''], true) || !str_starts_with($path, 'video://')) {
                    $baseImages[$path] = true;
                }
                continue;
            }
            if ($role !== 'variant') {
                continue;
            }
            foreach ($this->extractOfferCombination(['combination_key' => $mediaCombinationKey]) as $axis => $value) {
                if ($axis !== 'size' && !isset($variantSwatches[$axis][$value])) {
                    $variantSwatches[$axis][$value] = $path;
                }
            }
            $variantImages[$mediaCombinationKey][$path] = true;
        }

        $variantAxes = $availableValues !== []
            ? $this->axesForProduct($productId)->buildAxes($resolvedBase, $availableValues)
            : [];
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

        return [
            'resolved_base' => $resolvedBase,
            'base_specifications' => $baseSpecifications,
            'available_values' => $availableValues,
            'labels' => $labels,
            'base_images' => $baseImages,
            'base_videos' => $baseVideos,
            'variant_images' => $variantImages,
            'variant_swatches' => $variantSwatches,
            'variant_axes' => $variantAxes,
        ];
    }

    private function firstAxisValue(mixed $value): string
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return '';
            }
            $value = str_contains($value, ',') ? (preg_split('/\s*,\s*/', $value) ?: []) : [$value];
        }
        if (!is_array($value)) {
            return is_scalar($value) ? trim((string)$value) : '';
        }
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string)$item) !== '') {
                return trim((string)$item);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>|null
     */
    private function projectVideoItem(array $media): ?array
    {
        $path = trim((string)($media[Media::schema_fields_PATH] ?? ''));
        if ($path === '') {
            return null;
        }
        $mime = strtolower(trim((string)($media[Media::schema_fields_MIME_TYPE] ?? '')));
        $policy = $media[Media::schema_fields_ACCESS_POLICY_JSON] ?? null;
        $normalized = null;
        if (is_string($policy) && trim($policy) !== '') {
            try {
                $decoded = json_decode($policy, true, 64, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && is_array($decoded['video'] ?? null)) {
                    $normalized = $decoded['video'];
                }
            } catch (\JsonException) {
                $normalized = null;
            }
        } elseif (is_array($policy) && is_array($policy['video'] ?? null)) {
            $normalized = $policy['video'];
        }

        $provider = strtolower(trim((string)($normalized['provider'] ?? '')));
        $embedUrl = trim((string)($normalized['embed_url'] ?? ''));
        $watchUrl = trim((string)($normalized['watch_url'] ?? ''));
        $posterUrl = trim((string)($normalized['poster_url'] ?? ''));
        $providerId = trim((string)($normalized['provider_id'] ?? ''));
        $assetId = strtolower(trim((string)($media[Media::schema_fields_ASSET_ID] ?? '')));

        if ($provider === '' && $assetId !== '') {
            $provider = 'file';
        }
        if ($provider === '' && str_starts_with($path, 'video://youtube/')) {
            $provider = 'youtube';
            $providerId = substr($path, strlen('video://youtube/'));
            $embedUrl = 'https://www.youtube.com/embed/' . rawurlencode($providerId);
            $watchUrl = 'https://www.youtube.com/watch?v=' . rawurlencode($providerId);
            $posterUrl = 'https://i.ytimg.com/vi/' . rawurlencode($providerId) . '/hqdefault.jpg';
            $mime = $mime !== '' ? $mime : 'video/youtube';
        }
        if ($provider === '' && str_starts_with($path, 'video://vimeo/')) {
            $provider = 'vimeo';
            $providerId = substr($path, strlen('video://vimeo/'));
            $embedUrl = 'https://player.vimeo.com/video/' . rawurlencode($providerId);
            $watchUrl = 'https://vimeo.com/' . rawurlencode($providerId);
            $mime = $mime !== '' ? $mime : 'video/vimeo';
        }
        if ($provider === '' && preg_match('#^https?://#i', $path) === 1) {
            $provider = 'file';
            $embedUrl = $path;
            $watchUrl = $path;
        }
        if ($provider === '') {
            return null;
        }
        if ($embedUrl === '' && $assetId !== '') {
            $embedUrl = $path !== '' ? $path : ('asset://' . $assetId);
        }
        if ($embedUrl === '') {
            return null;
        }

        return [
            'type' => 'video',
            'provider' => $provider,
            'provider_id' => $providerId,
            'path' => $path,
            'asset_id' => $assetId,
            'src' => $embedUrl,
            'embed_url' => $embedUrl,
            'watch_url' => $watchUrl,
            'poster' => $posterUrl,
            'mime_type' => $mime,
            'position' => (int)($media[Media::schema_fields_POSITION] ?? 0),
        ];
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

    private function labelsForProduct(int $productId): StorefrontEavLabelResolver
    {
        $productId = max(0, $productId);
        return $this->labelsByProductId[$productId]
            ??= $this->labels()->forProduct($productId);
    }

    private function axesForProduct(int $productId): StorefrontVariantAxisResolver
    {
        $productId = max(0, $productId);
        return $this->axesByProductId[$productId]
            ??= $this->variantAxisResolver()->forProduct($productId);
    }

    private function isPublicSpecificationCode(string $code): bool
    {
        $code = strtolower(trim($code));

        return $code !== ''
            && !in_array($code, self::CONTENT_CODES, true)
            && !in_array($code, self::INTERNAL_CODES, true)
            && !str_starts_with($code, 'source_')
            && !str_starts_with($code, 'spec_');
    }

    /**
     * Skip an incompatible Han-script fallback and continue along the already
     * approved locale / scope chain. A cleared row still terminates inheritance.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $localeFallbacks
     * @param bool $allowHanIdentity When true, keep Han option/axis identity values
     *        on non-Han storefront locales (swatch chips need the source codes).
     */
    private function resolvePresentableAttribute(
        array $rows,
        int $storeId,
        string $locale,
        array $localeFallbacks,
        bool $allowHanIdentity = false,
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
            if ($allowHanIdentity || !$this->containsUnsupportedHanText(
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
            // Use the primary script, not Script_Extensions: common punctuation
            // such as the middle dot also matches the shorthand Han property.
            && preg_match('/\p{sc=Han}/u', $value) === 1;
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
