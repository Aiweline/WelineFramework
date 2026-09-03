<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Seo\Interface\SeoProfileProviderInterface;

/**
 * Supplies Product EAV and Offer facts to the shared SEO head pipeline.
 */
final class ProductSeoProfileProvider implements SeoProfileProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        $slot = strtolower(trim((string)($context['_slot'] ?? 'head')));
        if ($slot !== '' && $slot !== 'head') {
            return [];
        }
        if (strtolower(trim((string)($context['page_type'] ?? ''))) !== 'product') {
            return [];
        }

        $product = is_array($context['product'] ?? null)
            ? $context['product']
            : $this->templateArray($template, 'storefront_offer');
        if ($product === []) {
            return [];
        }

        $offers = is_array($product['storefront_offers'] ?? null)
            ? $product['storefront_offers']
            : $this->templateArray($template, 'storefront_offers');
        if ($offers === []) {
            $offers = [$product];
        }
        $offers = array_values(array_filter($offers, 'is_array'));
        if ($offers === []) {
            $offers = [$product];
        }

        $variants = array_map(
            fn(array $offer): array => $this->normalizeVariant($offer),
            $offers,
        );
        $profileProduct = $product;
        unset($profileProduct['storefront_offers']);
        if (count($variants) > 1) {
            $profileProduct['schema_type'] = 'ProductGroup';
            $profileProduct['product_group_id'] = (string)($product['product_id'] ?? $product['sku'] ?? '');
            $profileProduct['variants'] = $variants;
            $profileProduct['varies_by'] = $this->variantAxisCodes($offers);
        } else {
            $profileProduct = array_replace($profileProduct, $variants[0]);
        }

        $title = $this->firstNonEmpty([
            $product['meta_name'] ?? null,
            $product['name'] ?? null,
        ]);
        $description = $this->firstNonEmpty([
            $product['meta_description'] ?? null,
            $product['short_description'] ?? null,
            $product['description'] ?? null,
        ]);
        $keywords = $this->firstNonEmpty([
            $product['meta_keywords'] ?? null,
        ]);
        $image = $this->firstNonEmpty([
            $product['image'] ?? null,
            is_array($product['images'] ?? null) ? ($product['images'][0] ?? null) : null,
        ]);

        return [
            'page_type' => 'product',
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'image' => $image,
            'image_alt' => trim((string)($product['name'] ?? $title)),
            'product' => $profileProduct,
        ];
    }

    /**
     * @param mixed $template
     * @return array<int|string, mixed>
     */
    private function templateArray($template, string $key): array
    {
        if (!is_object($template) || !method_exists($template, 'getData')) {
            return [];
        }
        $value = $template->getData($key);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function normalizeVariant(array $offer): array
    {
        $images = array_values(array_unique(array_filter(
            array_map(
                static fn(mixed $image): string => trim((string)$image),
                is_array($offer['images'] ?? null) ? $offer['images'] : [],
            ),
            static fn(string $image): bool => $image !== '',
        )));
        $image = trim((string)($offer['image'] ?? ''));
        if ($image !== '' && !in_array($image, $images, true)) {
            array_unshift($images, $image);
        }

        $variant = [
            'id' => $this->firstNonEmpty([
                $offer['global_offer_uuid'] ?? null,
                $offer['offer_id'] ?? null,
                $offer['sku'] ?? null,
            ]),
            'name' => trim((string)($offer['name'] ?? '')),
            'sku' => trim((string)($offer['sku'] ?? '')),
            'image' => $image,
            'images' => $images,
            'price' => number_format(max(0, (int)($offer['unit_price_minor'] ?? 0)) / 100, 2, '.', ''),
            'currency' => strtoupper(trim((string)($offer['currency'] ?? 'CNY'))) ?: 'CNY',
            'availability' => !empty($offer['sellable'])
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
        ];
        foreach (['color', 'size', 'material', 'pattern'] as $attributeCode) {
            $value = $this->variantValue($offer, $attributeCode);
            if ($value !== '') {
                $variant[$attributeCode] = $value;
            }
        }

        return $variant;
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function variantValue(array $offer, string $attributeCode): string
    {
        foreach (is_array($offer['variant_axes'] ?? null) ? $offer['variant_axes'] : [] as $axis) {
            if (!is_array($axis)
                || strtolower(trim((string)($axis['code'] ?? ''))) !== $attributeCode
            ) {
                continue;
            }
            return $this->firstNonEmpty([
                $axis['value_label'] ?? null,
                $axis['value'] ?? null,
            ]);
        }
        foreach (is_array($offer['specifications'] ?? null) ? $offer['specifications'] : [] as $specification) {
            if (!is_array($specification)
                || strtolower(trim((string)($specification['code'] ?? ''))) !== $attributeCode
            ) {
                continue;
            }
            return trim((string)($specification['value'] ?? ''));
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return list<string>
     */
    private function variantAxisCodes(array $offers): array
    {
        $codes = [];
        foreach ($offers as $offer) {
            foreach (is_array($offer['variant_axes'] ?? null) ? $offer['variant_axes'] : [] as $axis) {
                if (!is_array($axis)) {
                    continue;
                }
                $code = strtolower(trim((string)($axis['code'] ?? '')));
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
            if (is_array($offer['combination'] ?? null)) {
                foreach (array_keys($offer['combination']) as $code) {
                    $code = strtolower(trim((string)$code));
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
            }
        }
        $result = array_keys($codes);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }

        return '';
    }
}
