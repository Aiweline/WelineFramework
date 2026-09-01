<?php

declare(strict_types=1);

namespace Weline\Product\Service;

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
     * @return array<string, mixed>
     */
    public function project(
        array $offer,
        array $attributeRows,
        array $mediaRows,
        int $storeId,
        string $locale,
    ): array {
        $byCode = [];
        foreach ($attributeRows as $row) {
            $code = strtolower(trim((string)($row['attribute_code'] ?? '')));
            if ($code !== '') {
                $byCode[$code][] = $row;
            }
        }

        $resolved = [];
        foreach ($byCode as $code => $rows) {
            $value = $this->resolver->resolveAttribute($rows, $storeId, $locale, ['']);
            if (!$value->isExplicit()) {
                continue;
            }
            $normalized = $this->normalizeResolvedValue($value->value);
            if ($normalized !== '') {
                $resolved[$code] = $normalized;
            }
        }

        $typeConfiguration = $this->parseTypeConfiguration($resolved['type_configuration'] ?? '');
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
            $specifications[] = [
                'code' => $code,
                'value' => $this->labels()->resolve($code, $value),
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
        $images = [];
        $primaryImage = trim((string)($offer['image'] ?? ''));
        if ($primaryImage !== '') {
            $images[$primaryImage] = true;
        }
        foreach ($mediaRows as $media) {
            $path = trim((string)($media[Media::schema_fields_PATH] ?? ''));
            if ($path !== '') {
                $images[$path] = true;
            }
        }

        $localizedName = trim((string)($resolved['name'] ?? ''));
        $slug = $this->normalizeSlug(
            (string)($resolved['source_slug'] ?? $resolved['slug'] ?? $offer['slug'] ?? ''),
        );

        $variantAxes = $this->variantAxisResolver()->buildAxes($typeConfiguration, $resolved);
        $galleryImages = $this->variantAxisResolver()->resolveGalleryImages($variantAxes, $combination);
        if ($galleryImages !== []) {
            $imageList = $galleryImages;
            $primaryImage = $galleryImages[0];
        } else {
            $imageList = array_keys($images);
            $previewImage = $this->variantAxisResolver()->resolvePreviewImage($variantAxes, $combination);
            if ($previewImage !== '') {
                $imageList = array_values(array_unique(array_merge([$previewImage], $imageList)));
                $primaryImage = $previewImage;
            }
        }

        return array_merge($offer, [
            'name' => $localizedName !== '' ? $localizedName : (string)($offer['name'] ?? ''),
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
            try {
                return trim((string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } catch (\JsonException) {
                return '';
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTypeConfiguration(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

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

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) !== 1) {
            return '';
        }

        return $slug;
    }
}
