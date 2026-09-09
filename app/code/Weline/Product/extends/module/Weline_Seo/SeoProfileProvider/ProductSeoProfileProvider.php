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

        $canonicalUrl = $this->firstNonEmpty([
            $product['canonical'] ?? null,
            $product['url'] ?? null,
            $context['canonical_url'] ?? null,
            $context['url'] ?? null,
        ]);
        $variants = array_map(
            fn(array $offer): array => $this->normalizeVariant($offer, $canonicalUrl),
            $offers,
        );
        $profileProduct = $product;
        unset($profileProduct['storefront_offers']);
        // Keep product-level identity for Review SEO even when PDP clears offer uuid
        // until the shopper picks a variant (selection_required).
        if (trim((string)($profileProduct['global_offer_uuid'] ?? '')) === '') {
            $profileProduct['global_product_uuid'] = $this->firstNonEmpty([
                $profileProduct['global_product_uuid'] ?? null,
                $profileProduct['product_uuid'] ?? null,
                $offers[0]['global_product_uuid'] ?? null,
                $offers[0]['product_uuid'] ?? null,
                $offers[0]['global_offer_uuid'] ?? null,
            ]);
        }
        if (count($variants) > 1) {
            $profileProduct['schema_type'] = 'ProductGroup';
            $profileProduct['product_group_id'] = (string)($product['product_id'] ?? $product['sku'] ?? '');
            $profileProduct['variants'] = $variants;
            $profileProduct['varies_by'] = $this->schemaVariesByAxisCodes($offers);
        } else {
            $profileProduct = array_replace($profileProduct, $variants[0]);
        }

        $title = $this->firstNonEmpty([
            $product['meta_name'] ?? null,
            $product['name'] ?? null,
        ]);
        $description = $this->ensureMetaDescriptionLength(
            $this->firstNonEmpty([
                $product['meta_description'] ?? null,
                $product['short_description'] ?? null,
                $product['description'] ?? null,
            ]),
            $title,
            $this->firstNonEmpty([
                $product['brand'] ?? null,
                $product['brand_name'] ?? null,
            ]),
        );
        // Ensure JSON-LD Product.description sees the padded SEO description.
        $profileProduct['meta_description'] = $description;
        $profileProduct['description'] = $description;
        $keywords = $this->firstNonEmpty([
            $product['meta_keywords'] ?? null,
        ]);
        $image = $this->firstNonEmpty([
            $product['image'] ?? null,
            is_array($product['images'] ?? null) ? ($product['images'][0] ?? null) : null,
        ]);

        $crumbBundle = $this->resolveProductBreadcrumbBundle($title, $product, $context, $template);

        return [
            'page_type' => 'product',
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'image' => $image,
            'image_alt' => trim((string)($product['name'] ?? $title)),
            'product' => $profileProduct,
            'site_search_enabled' => true,
            'breadcrumbs' => $crumbBundle['primary'],
            'breadcrumb_trails' => $crumbBundle['trails'],
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $context
     * @param mixed $template
     * @return array{primary: list<array{name:string,url:string}>, trails: list<list<array{name:string,url:string}>>}
     */
    private function resolveProductBreadcrumbBundle(string $title, array $product, array $context, $template): array
    {
        $trailsFromContext = $context['breadcrumb_trails'] ?? null;
        if (is_array($trailsFromContext) && $trailsFromContext !== []) {
            $trails = [];
            foreach ($trailsFromContext as $trail) {
                if (!is_array($trail) || $trail === []) {
                    continue;
                }
                $normalized = array_values(array_filter(
                    $trail,
                    static fn($row): bool => is_array($row) && trim((string)($row['name'] ?? '')) !== ''
                ));
                if (count($normalized) >= 2) {
                    $trails[] = $normalized;
                }
            }
            if ($trails !== []) {
                $primary = is_array($context['breadcrumbs'] ?? null) && $context['breadcrumbs'] !== []
                    ? array_values(array_filter(
                        $context['breadcrumbs'],
                        static fn($row): bool => is_array($row) && trim((string)($row['name'] ?? '')) !== ''
                    ))
                    : $trails[0];

                return [
                    'primary' => count($primary) >= 2 ? $primary : $trails[0],
                    'trails' => $trails,
                ];
            }
        }

        $fromTemplate = $this->templateArray($template, 'storefront_product_breadcrumb_trails');
        if ($fromTemplate !== []) {
            $trails = [];
            foreach ($fromTemplate as $trail) {
                if (!is_array($trail)) {
                    continue;
                }
                $normalized = array_values(array_filter(
                    $trail,
                    static fn($row): bool => is_array($row) && trim((string)($row['name'] ?? '')) !== ''
                ));
                if (count($normalized) >= 2) {
                    $trails[] = $normalized;
                }
            }
            if ($trails !== []) {
                $primary = $this->resolveProductBreadcrumbs($title, $product, $context, $template);

                return [
                    'primary' => count($primary) >= 2 ? $primary : $trails[0],
                    'trails' => $trails,
                ];
            }
        }

        if (class_exists(\Weline\Product\Service\ProductStorefrontBreadcrumbBuilder::class)) {
            $remembered = \Weline\Product\Service\ProductStorefrontBreadcrumbBuilder::remembered();
            if (is_array($remembered) && ($remembered['trails'] ?? []) !== []) {
                $trails = [];
                foreach ($remembered['trails'] as $trail) {
                    if (!is_array($trail)) {
                        continue;
                    }
                    $normalized = array_values(array_filter(
                        $trail,
                        static fn($row): bool => is_array($row) && trim((string)($row['name'] ?? '')) !== ''
                    ));
                    if (count($normalized) >= 2) {
                        $trails[] = $normalized;
                    }
                }
                if ($trails !== []) {
                    $primary = is_array($remembered['primary'] ?? null) ? $remembered['primary'] : $trails[0];

                    return [
                        'primary' => count($primary) >= 2 ? array_values(array_filter(
                            $primary,
                            static fn($row): bool => is_array($row) && trim((string)($row['name'] ?? '')) !== ''
                        )) : $trails[0],
                        'trails' => $trails,
                    ];
                }
            }
        }

        $single = $this->resolveProductBreadcrumbs($title, $product, $context, $template);

        return [
            'primary' => $single,
            'trails' => count($single) >= 2 ? [$single] : [],
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $context
     * @param mixed $template
     * @return list<array{name:string,url:string}>
     */
    private function resolveProductBreadcrumbs(string $title, array $product, array $context, $template): array
    {
        if (is_array($context['breadcrumbs'] ?? null) && $context['breadcrumbs'] !== []) {
            return array_values(array_filter(
                $context['breadcrumbs'],
                static fn($row): bool => is_array($row) && trim((string)($row['name'] ?? '')) !== ''
            ));
        }

        $fromTemplate = $this->templateArray($template, 'breadcrumbs');
        if ($fromTemplate === []) {
            $fromTemplate = $this->templateArray($template, 'storefront_product_breadcrumbs');
        }
        if ($fromTemplate !== []) {
            return array_values(array_filter(
                $fromTemplate,
                static fn($row): bool => is_array($row) && trim((string)($row['name'] ?? '')) !== ''
            ));
        }

        $canonical = trim((string)($context['canonical_url'] ?? $context['url'] ?? ''));
        $siteRoot = '';
        if ($canonical !== '' && preg_match('#^(https?://[^/]+)#i', $canonical, $m)) {
            $siteRoot = $m[1] . '/';
        }
        $trail = [
            [
                'name' => \function_exists('__') ? (string)__('首页') : '首页',
                'url' => $siteRoot !== '' ? $siteRoot : '/',
            ],
        ];
        $categoryName = trim((string)$this->firstNonEmpty([
            $product['category_name'] ?? null,
            $product['primary_category_name'] ?? null,
        ]));
        if ($categoryName !== '') {
            $categoryUrl = trim((string)($product['category_url'] ?? ''));
            $trail[] = [
                'name' => $categoryName,
                'url' => $categoryUrl !== '' ? $categoryUrl : ($siteRoot !== '' ? $siteRoot : '/'),
            ];
        }
        $leafName = $this->firstNonEmpty([
            $product['name'] ?? null,
            $title,
        ]);
        if ($leafName !== '') {
            $trail[] = [
                'name' => $leafName,
                'url' => $canonical !== '' ? $canonical : '#',
            ];
        }

        return $trail;
    }

    private function ensureMetaDescriptionLength(string $description, string $title, string $brand): string
    {
        $description = trim(preg_replace('/\s+/u', ' ', strip_tags($description)) ?? $description);
        $length = mb_strlen($description);
        if ($length >= 80 && $length <= 170) {
            return $description;
        }

        if ($description === '') {
            $label = $title !== '' ? $title : '汉服商品';
            $prefix = $brand !== '' ? ($brand . '正品汉服：') : '';
            $description = $prefix . $label . '。查看颜色与尺码、实拍图片、库存与发货说明，便于日常与礼仪场景选购。';
        }
        if (mb_strlen($description) < 80) {
            $suffixOptions = [
                '适合日常穿着、节日出行与礼仪场合选购参考。',
                '支持颜色与尺码选择，提供实拍图、库存与发货信息，适合日常穿着与礼仪场合。',
            ];
            foreach ($suffixOptions as $suffix) {
                if (mb_strlen($description) >= 80) {
                    break;
                }
                if (str_contains($description, $suffix)) {
                    continue;
                }
                $description = rtrim($description, "。.;； ") . '。' . $suffix;
            }
            if (mb_strlen($description) < 80) {
                $description .= '欢迎对比形制与面料后再下单。';
            }
        }

        if (mb_strlen($description) > 170) {
            $description = rtrim(mb_substr($description, 0, 167)) . '…';
        }

        return $description;
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
    private function normalizeVariant(array $offer, string $canonicalUrl = ''): array
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

        $offerUuid = $this->firstNonEmpty([
            $offer['global_offer_uuid'] ?? null,
            $offer['offer_id'] ?? null,
            $offer['sku'] ?? null,
        ]);
        $baseName = trim((string)($offer['name'] ?? ''));
        $axisLabels = [];
        $additionalProperties = [];
        foreach ($this->variantAxisRows($offer) as $axis) {
            $code = strtolower(trim((string)($axis['code'] ?? '')));
            $label = $this->firstNonEmpty([
                $axis['value_label'] ?? null,
                $axis['value'] ?? null,
            ]);
            if ($code === '' || $label === '') {
                continue;
            }
            $axisLabels[] = $label;
            $schemaField = $this->schemaVariantField($code);
            if ($schemaField !== null) {
                continue;
            }
            $additionalProperties[] = [
                'name' => $this->firstNonEmpty([
                    $axis['label'] ?? null,
                    $axis['name'] ?? null,
                    $code,
                ]),
                'value' => $label,
                'propertyID' => $code,
                'code' => $code,
            ];
        }

        $variant = [
            'id' => $offerUuid,
            'name' => $axisLabels === []
                ? $baseName
                : trim($baseName . ' - ' . implode(' / ', array_values(array_unique($axisLabels)))),
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
        if ($additionalProperties !== []) {
            $variant['additional_property'] = $additionalProperties;
            $variant['additionalProperty'] = $additionalProperties;
        }
        if ($offerUuid !== '') {
            $variantUrlBase = $this->firstNonEmpty([
                $offer['url'] ?? null,
                $offer['canonical'] ?? null,
                $canonicalUrl,
            ]);
            $variant['url'] = $this->offerUrl($variantUrlBase, $offerUuid);
        }

        return $variant;
    }

    /**
     * @param array<string, mixed> $offer
     * @return list<array<string, mixed>>
     */
    private function variantAxisRows(array $offer): array
    {
        $rows = [];
        foreach (is_array($offer['variant_axes'] ?? null) ? $offer['variant_axes'] : [] as $axis) {
            if (is_array($axis)) {
                $rows[] = $axis;
            }
        }
        if ($rows !== []) {
            return $rows;
        }
        foreach (is_array($offer['combination'] ?? null) ? $offer['combination'] : [] as $code => $value) {
            $rows[] = [
                'code' => (string)$code,
                'value' => is_scalar($value) ? (string)$value : '',
                'value_label' => is_scalar($value) ? (string)$value : '',
            ];
        }

        return $rows;
    }

    private function schemaVariantField(string $attributeCode): ?string
    {
        return match (strtolower(trim($attributeCode))) {
            'color', 'colour' => 'color',
            'size' => 'size',
            'material' => 'material',
            'pattern', 'style', 'style_type' => 'pattern',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function variantValue(array $offer, string $attributeCode): string
    {
        $aliases = match ($attributeCode) {
            'color' => ['color', 'colour'],
            'pattern' => ['pattern', 'style', 'style_type'],
            default => [$attributeCode],
        };
        foreach ($aliases as $alias) {
            foreach ($this->variantAxisRows($offer) as $axis) {
                if (strtolower(trim((string)($axis['code'] ?? ''))) !== $alias) {
                    continue;
                }
                $value = $this->firstNonEmpty([
                    $axis['value_label'] ?? null,
                    $axis['value'] ?? null,
                ]);
                if ($value !== '') {
                    return $value;
                }
            }
            foreach (is_array($offer['specifications'] ?? null) ? $offer['specifications'] : [] as $specification) {
                if (!is_array($specification)
                    || strtolower(trim((string)($specification['code'] ?? ''))) !== $alias
                ) {
                    continue;
                }
                $value = trim((string)($specification['value'] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return list<string>
     */
    private function schemaVariesByAxisCodes(array $offers): array
    {
        $codes = [];
        foreach ($offers as $offer) {
            foreach ($this->variantAxisRows($offer) as $axis) {
                $field = $this->schemaVariantField((string)($axis['code'] ?? ''));
                if ($field !== null) {
                    $codes[$field] = true;
                }
            }
            if (is_array($offer['combination'] ?? null)) {
                foreach (array_keys($offer['combination']) as $code) {
                    $field = $this->schemaVariantField((string)$code);
                    if ($field !== null) {
                        $codes[$field] = true;
                    }
                }
            }
        }
        $result = array_keys($codes);
        sort($result, SORT_STRING);

        return $result;
    }

    private function offerUrl(string $baseUrl, string $offerUuid): string
    {
        $offerUuid = trim($offerUuid);
        if ($offerUuid === '') {
            return $baseUrl;
        }
        if ($baseUrl === '') {
            return '?offer=' . rawurlencode($offerUuid);
        }
        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'offer=' . rawurlencode($offerUuid);
        }
        $query = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }
        $query['offer'] = $offerUuid;
        $rebuilt = '';
        if (isset($parts['scheme'], $parts['host'])) {
            $rebuilt .= $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $rebuilt .= ':' . $parts['port'];
            }
        }
        $rebuilt .= (string)($parts['path'] ?? '');
        $rebuilt .= '?' . http_build_query($query);
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
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
