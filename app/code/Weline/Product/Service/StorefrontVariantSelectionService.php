<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Storefront configurable-product variant resolution.
 *
 * combination_key format: color=m-white|size=m (segments sorted by axis code).
 */
final class StorefrontVariantSelectionService
{
    /**
     * @return array<string, string>
     */
    public function parseCombinationKey(string $combinationKey): array
    {
        $combinationKey = trim($combinationKey);
        if ($combinationKey === '') {
            return [];
        }

        $combination = [];
        foreach (explode('|', $combinationKey) as $segment) {
            if (!str_contains($segment, '=')) {
                continue;
            }
            [$axis, $value] = explode('=', $segment, 2);
            $axis = strtolower(trim(rawurldecode($axis)));
            $value = trim(rawurldecode($value));
            if ($axis !== '' && $value !== '') {
                $combination[$axis] = $value;
            }
        }

        ksort($combination, SORT_STRING);

        return $combination;
    }

    /**
     * Axis codes that participate in offer SKU combinations.
     *
     * Product-level multiselect metadata on offer.variant_axes may retain orphan
     * axes that no combination_key uses; those must not become selectable PDP axes.
     *
     * @param list<array<string, mixed>> $offers
     * @return list<string>
     */
    public function collectAxisCodes(array $offers): array
    {
        $codes = [];
        foreach ($offers as $offer) {
            foreach ($this->offerCombination($offer) as $axis => $_value) {
                $codes[$axis] = true;
            }
        }

        $sorted = array_keys($codes);
        sort($sorted, SORT_STRING);

        return $sorted;
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @param array<string, string> $selection
     */
    public function matchOffer(array $offers, array $selection, bool $requireExact = true): ?array
    {
        $selection = $this->normalizeSelection($selection);
        if ($selection === []) {
            return null;
        }

        $bestPartial = null;
        $bestPartialScore = -1;
        foreach ($offers as $offer) {
            $combination = $this->offerCombination($offer);
            if ($combination === []) {
                continue;
            }

            $matchedAxes = 0;
            foreach ($selection as $axis => $value) {
                if (($combination[$axis] ?? '') !== $value) {
                    continue 2;
                }
                $matchedAxes++;
            }

            if ($matchedAxes === count($selection) && $matchedAxes === count($combination)) {
                return $offer;
            }

            if (!$requireExact && $matchedAxes > $bestPartialScore) {
                $bestPartialScore = $matchedAxes;
                $bestPartial = $offer;
            }
        }

        return $requireExact ? null : $bestPartial;
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @param array<string, mixed> $query
     */
    public function resolveSelectedOffer(array $offers, array $query = []): ?array
    {
        if ($offers === []) {
            return null;
        }

        $requestedOfferUuid = trim((string)($query['offer'] ?? ''));
        if ($requestedOfferUuid !== '') {
            foreach ($offers as $offer) {
                if (hash_equals(
                    trim((string)($offer['global_offer_uuid'] ?? '')),
                    $requestedOfferUuid,
                )) {
                    return $offer;
                }
            }
        }

        $axisCodes = $this->collectAxisCodes($offers);
        $selection = [];
        foreach ($axisCodes as $axisCode) {
            $value = trim((string)($query[$axisCode] ?? ''));
            if ($value !== '') {
                $selection[$axisCode] = $value;
            }
        }
        if ($selection !== []) {
            $matched = $this->matchOffer($offers, $selection, true);
            if ($matched !== null) {
                return $matched;
            }
        }

        if (count($offers) === 1) {
            return $offers[0];
        }

        foreach ($offers as $offer) {
            if (!empty($offer['is_default'])) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return array{
     *     axes:list<array{code:string,label:string,options:list<array{value:string,label:string}>}>,
     *     offers:list<array<string,mixed>>,
     *     selected:array<string,string>
     * }
     */
    public function buildCatalog(array $offers, ?array $selectedOffer = null): array
    {
        $offerAxisCodes = [];
        foreach ($this->collectAxisCodes($offers) as $code) {
            $offerAxisCodes[$code] = true;
        }

        $axes = [];
        $axisOrder = [];
        if ($selectedOffer !== null) {
            foreach ((array)($selectedOffer['variant_axes'] ?? []) as $axisRow) {
                if (!is_array($axisRow)) {
                    continue;
                }
                $code = strtolower(trim((string)($axisRow['code'] ?? '')));
                // Product-level multiselect can retain orphan axes that no live offer
                // combination uses. collectAxisCodes is combination-only; drop the rest.
                if ($code === '' || !isset($offerAxisCodes[$code])) {
                    continue;
                }
                $axisOrder[] = $code;
                $axes[$code] = [
                    'code' => $code,
                    'label' => $this->preferAxisDisplayLabel(
                        trim((string)($axisRow['label'] ?? '')),
                        $code,
                        $offers,
                    ),
                    'options' => [],
                ];
                foreach ((array)($axisRow['options'] ?? []) as $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $value = trim((string)($option['value'] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $axes[$code]['options'][$value] = $this->normalizeCatalogOption(
                        $value,
                        trim((string)($option['label'] ?? $value)),
                        $option,
                    );
                }
            }
        }

        foreach ($this->collectAxisCodes($offers) as $code) {
            if (!isset($axes[$code])) {
                $axes[$code] = [
                    'code' => $code,
                    'label' => $this->resolveAxisLabelFromOffers($offers, $code),
                    'options' => [],
                ];
                $axisOrder[] = $code;
            }
        }

        $catalogOffers = [];
        foreach ($offers as $offer) {
            $combination = $this->offerCombination($offer);
            foreach ($combination as $axis => $value) {
                if (!isset($axes[$axis])) {
                    $axes[$axis] = [
                        'code' => $axis,
                        'label' => $this->resolveAxisLabelFromOffers($offers, $axis),
                        'options' => [],
                    ];
                    $axisOrder[] = $axis;
                }
                if (!isset($axes[$axis]['options'][$value])) {
                    $label = $value;
                    $optionMeta = [];
                    foreach ((array)($offer['variant_axes'] ?? []) as $axisRow) {
                        if (!is_array($axisRow) || strtolower((string)($axisRow['code'] ?? '')) !== $axis) {
                            continue;
                        }
                        foreach ((array)($axisRow['options'] ?? []) as $option) {
                            if (!is_array($option)) {
                                continue;
                            }
                            if (trim((string)($option['value'] ?? '')) === $value) {
                                $label = trim((string)($option['label'] ?? $value));
                                $optionMeta = $option;
                                break 2;
                            }
                        }
                    }
                    $axes[$axis]['options'][$value] = $this->normalizeCatalogOption($value, $label, $optionMeta);
                }
            }

            $images = array_values(array_filter(
                array_map(static fn(mixed $value): string => trim((string)$value), (array)($offer['images'] ?? [])),
                static fn(string $value): bool => $value !== '',
            ));
            $primaryImage = trim((string)($offer['image'] ?? ''));
            if ($primaryImage !== '' && !in_array($primaryImage, $images, true)) {
                array_unshift($images, $primaryImage);
            } elseif ($primaryImage !== '' && $images === []) {
                $images = [$primaryImage];
            }

            $unitPriceMinor = max(0, (int)($offer['unit_price_minor'] ?? 0));
            $catalogPriceMinor = max(0, (int)($offer['catalog_price_minor'] ?? $unitPriceMinor));
            $compareAtMinor = max(0, (int)($offer['compare_at_minor'] ?? $catalogPriceMinor));
            $catalogOffers[] = [
                'global_offer_uuid' => trim((string)($offer['global_offer_uuid'] ?? '')),
                'sku' => trim((string)($offer['sku'] ?? '')),
                'combination' => $combination,
                'image' => $primaryImage,
                'images' => $images,
                'currency' => strtoupper(trim((string)($offer['currency'] ?? 'CNY'))),
                // catalog_price_minor = raw list; unit_price_minor = Assembler final.
                // PDP JS must not treat unit as catalog or deals stack on variant switch.
                'catalog_price_minor' => $catalogPriceMinor,
                'compare_at_minor' => $compareAtMinor,
                'unit_price_minor' => $unitPriceMinor,
                'has_deal' => !empty($offer['has_deal']) || ($catalogPriceMinor > 0 && $unitPriceMinor > 0 && $unitPriceMinor < $catalogPriceMinor),
                'stock' => max(0, (int)($offer['stock'] ?? 0)),
                'sellable' => !empty($offer['sellable']),
                'quote_only' => !empty($offer['quote_only']),
                'message' => trim((string)($offer['message'] ?? '')),
                'provider_code' => trim((string)($offer['provider_code'] ?? 'product')),
                'product_id' => max(0, (int)($offer['product_id'] ?? 0)),
            ];
        }

        $normalizedAxes = [];
        foreach ($axisOrder as $code) {
            if (!isset($axes[$code])) {
                continue;
            }
            $options = array_values($axes[$code]['options']);
            usort($options, static fn(array $left, array $right): int => $left['value'] <=> $right['value']);
            $normalizedAxes[] = [
                'code' => $axes[$code]['code'],
                'label' => $axes[$code]['label'],
                'options' => $options,
            ];
        }

        $selected = $selectedOffer !== null
            ? $this->offerCombination($selectedOffer)
            : [];

        return [
            'axes' => $normalizedAxes,
            'offers' => $catalogOffers,
            'selected' => $selected,
        ];
    }

    /**
     * Move gallery images shared by every offer to one top-level list.
     *
     * Variant-specific secondary images stay on the offer. Primary images
     * remain authoritative in the offer's `image` field and are excluded from
     * the shared base gallery, so the browser can always put the selected EAV
     * combination first without serializing the same gallery hundreds of times.
     *
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    public function compactCatalogMedia(array $catalog): array
    {
        $offers = array_values(array_filter((array)($catalog['offers'] ?? []), 'is_array'));
        if (count($offers) < 2) {
            return $catalog;
        }

        $imagesByOffer = [];
        $commonImages = null;
        $primaryImages = [];
        foreach ($offers as $index => $offer) {
            $images = array_values(array_unique(array_filter(
                array_map(
                    static fn(mixed $value): string => trim((string)$value),
                    (array)($offer['images'] ?? []),
                ),
                static fn(string $value): bool => $value !== '',
            )));
            $primaryImage = trim((string)($offer['image'] ?? ''));
            if ($primaryImage !== '' && !in_array($primaryImage, $images, true)) {
                array_unshift($images, $primaryImage);
            }
            if ($primaryImage !== '') {
                $primaryImages[$primaryImage] = true;
            }
            $imagesByOffer[$index] = $images;
            $imageSet = array_fill_keys($images, true);
            $commonImages = $commonImages === null
                ? $imageSet
                : array_intersect_key($commonImages, $imageSet);
        }

        if ($commonImages === null || $commonImages === []) {
            return $catalog;
        }

        $baseImages = array_values(array_filter(
            $imagesByOffer[0] ?? [],
            static fn(string $image): bool => isset($commonImages[$image])
                && !isset($primaryImages[$image]),
        ));

        foreach ($offers as $index => $offer) {
            $primaryImage = trim((string)($offer['image'] ?? ''));
            $offer['images'] = array_values(array_filter(
                $imagesByOffer[$index] ?? [],
                static fn(string $image): bool => !isset($commonImages[$image])
                    && $image !== $primaryImage,
            ));
            $offers[$index] = $offer;
        }
        $catalog['offers'] = $offers;
        $catalog['base_images'] = $baseImages;

        return $catalog;
    }

    /**
     * Prefer a human display label over bare attribute codes when catalog axes are backfilled.
     *
     * @param list<array<string, mixed>> $offers
     */
    private function resolveAxisLabelFromOffers(array $offers, string $code): string
    {
        return $this->preferAxisDisplayLabel('', $code, $offers);
    }

    /**
     * @param list<array<string, mixed>> $offers
     */
    private function preferAxisDisplayLabel(string $candidate, string $code, array $offers): string
    {
        $candidate = trim($candidate);
        if ($candidate !== '' && strcasecmp($candidate, $code) !== 0) {
            return $candidate;
        }
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            foreach ((array)($offer['variant_axes'] ?? []) as $axisRow) {
                if (!is_array($axisRow)) {
                    continue;
                }
                if (strtolower(trim((string)($axisRow['code'] ?? ''))) !== $code) {
                    continue;
                }
                $label = trim((string)($axisRow['label'] ?? ''));
                if ($label !== '' && strcasecmp($label, $code) !== 0) {
                    return $label;
                }
            }
        }

        return $candidate !== '' ? $candidate : $code;
    }

    /**
     * @param array<string, mixed> $option
     * @return array{value:string,label:string,code?:string,swatch_color?:string,swatch_image?:string,gallery_images?:list<string>}
     */
    private function normalizeCatalogOption(string $value, string $label, array $option): array
    {
        $entry = ['value' => $value, 'label' => $label];
        $optionCode = trim((string)($option['code'] ?? ''));
        if ($optionCode !== '') {
            $entry['code'] = $optionCode;
        }
        $swatchColor = trim((string)($option['swatch_color'] ?? $option['swatch'] ?? ''));
        if ($swatchColor !== '') {
            $entry['swatch_color'] = $swatchColor;
        }
        $swatchImage = trim((string)($option['swatch_image'] ?? ''));
        if ($swatchImage !== '') {
            $entry['swatch_image'] = $swatchImage;
        }
        if (is_array($option['gallery_images'] ?? null)) {
            $gallery = array_values(array_unique(array_filter(
                array_map(static fn(mixed $path): string => trim((string)$path), (array)$option['gallery_images']),
                static fn(string $path): bool => $path !== '',
            )));
            if ($gallery !== []) {
                $entry['gallery_images'] = $gallery;
            }
        }
        if (is_array($option['gallery_by_color'] ?? null)) {
            $byColor = [];
            foreach ((array)$option['gallery_by_color'] as $colorCode => $paths) {
                $colorCode = strtolower(trim((string)$colorCode));
                if ($colorCode === '' || !is_array($paths)) {
                    continue;
                }
                $gallery = array_values(array_unique(array_filter(
                    array_map(static fn(mixed $path): string => trim((string)$path), $paths),
                    static fn(string $path): bool => $path !== '',
                )));
                if ($gallery !== []) {
                    $byColor[$colorCode] = $gallery;
                }
            }
            if ($byColor !== []) {
                $entry['gallery_by_color'] = $byColor;
            }
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, string>
     */
    public function offerCombination(array $offer): array
    {
        $combination = $offer['combination'] ?? null;
        if (is_array($combination) && $combination !== []) {
            return $this->normalizeSelection($combination);
        }

        return $this->parseCombinationKey((string)($offer['combination_key'] ?? ''));
    }

    /**
     * @param array<string, mixed> $selection
     * @return array<string, string>
     */
    private function normalizeSelection(array $selection): array
    {
        $normalized = [];
        foreach ($selection as $axis => $value) {
            $axis = strtolower(trim((string)$axis));
            $value = trim((string)$value);
            if ($axis !== '' && $value !== '') {
                $normalized[$axis] = $value;
            }
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
