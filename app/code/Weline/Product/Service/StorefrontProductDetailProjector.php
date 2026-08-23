<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Model\Shard\Media;

/**
 * Pure storefront projection for public Product details.
 *
 * Attribute rows have already been bounded to one Product and its active
 * Website/Store layers. This projector applies the catalog overlay contract,
 * removes import-only metadata and returns a template-safe data shape.
 */
final class StorefrontProductDetailProjector
{
    private const CONTENT_CODES = ['name', 'short_description', 'description'];
    private const INTERNAL_CODES = ['quote_only'];

    public function __construct(
        private readonly CatalogOverlayResolver $resolver = new CatalogOverlayResolver(),
    ) {
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
            $text = trim((string)$value->value);
            if ($text !== '') {
                $resolved[$code] = $text;
            }
        }

        $specifications = [];
        foreach ($resolved as $code => $value) {
            if (in_array($code, self::CONTENT_CODES, true)
                || in_array($code, self::INTERNAL_CODES, true)
                || str_starts_with($code, 'source_')
            ) {
                continue;
            }
            $specifications[] = ['code' => $code, 'value' => $value];
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

        return array_merge($offer, [
            'short_description' => $resolved['short_description'] ?? '',
            'description' => $resolved['description'] ?? '',
            'quote_only' => ($resolved['quote_only'] ?? '0') === '1',
            'specifications' => $specifications,
            'images' => array_keys($images),
        ]);
    }
}
