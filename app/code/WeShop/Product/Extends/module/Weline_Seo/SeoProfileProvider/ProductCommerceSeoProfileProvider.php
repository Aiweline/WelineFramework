<?php

declare(strict_types=1);

namespace WeShop\Product\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Seo\Interface\SeoProfileProviderInterface;

class ProductCommerceSeoProfileProvider implements SeoProfileProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        if (($context['page_type'] ?? '') !== 'product') {
            return [];
        }

        $product = $this->toArray($context['product'] ?? []);
        if ($product === []) {
            return [];
        }

        $pageUrl = (string) ($context['canonical_url'] ?? $context['url'] ?? '');
        $attributes = $this->listArray($this->readTemplate($template, 'attributes'));
        $productImages = $this->listArray($this->readTemplate($template, 'product_images'));
        $configurableOptions = $this->toArray($this->readTemplate($template, 'configurable_options'));
        if ($configurableOptions === []) {
            $configurableOptions = $this->toArray($product['configurable_options'] ?? []);
        }

        $attributeMap = $this->attributeMap($attributes);
        $this->applyAttributeValues($product, $attributeMap);

        $images = $this->collectImages($product, $productImages, $pageUrl);
        if ($images !== []) {
            $product['images'] = $images;
            $product['image'] = $images[0];
            $context['image'] = $images[0];
        }

        $properties = $this->additionalProperties($product, $attributes);
        if ($properties !== []) {
            $product['additional_property'] = $properties;
            $product['attributes'] = $attributes;
        }

        $this->applyConfigurableOptions($product, $configurableOptions);

        $categoryPath = $this->categoryPath($context['breadcrumbs'] ?? []);
        if ($categoryPath !== '') {
            $product['category_path'] = $categoryPath;
            $product['category'] = $this->lastCategory($context['breadcrumbs'] ?? []);
        }

        $canonicalUrl = $this->canonicalUrl($product, $context);
        $profile = [
            'canonical_url' => $canonicalUrl,
            'breadcrumbs' => $this->withProductBreadcrumb($context['breadcrumbs'] ?? [], $product, $canonicalUrl),
            'product' => $product,
        ];
        if (isset($context['image']) && trim((string)$context['image']) !== '') {
            $profile['image'] = (string)$context['image'];
        }
        $faqs = $this->qaFaqs($this->listArray($this->readTemplate($template, 'qa')));
        if ($faqs !== []) {
            $profile['faqs'] = $faqs;
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, array{label:string,value:string}> $attributeMap
     */
    private function applyAttributeValues(array &$product, array $attributeMap): void
    {
        foreach ([
            'brand' => ['brand', 'manufacturer'],
            'mpn' => ['mpn', 'manufacturer_part_number'],
            'gtin' => ['gtin'],
            'gtin8' => ['gtin8'],
            'gtin12' => ['gtin12', 'upc'],
            'gtin13' => ['gtin13', 'ean'],
            'gtin14' => ['gtin14'],
            'material' => ['material', 'fabric'],
            'pattern' => ['pattern'],
            'color' => ['color', 'colour'],
            'size' => ['size'],
            'gender' => ['gender'],
            'age_group' => ['age_group', 'age'],
            'item_condition' => ['condition', 'item_condition'],
        ] as $target => $codes) {
            if (isset($product[$target]) && trim((string) $product[$target]) !== '') {
                continue;
            }
            $value = $this->attributeValue($attributeMap, $codes);
            if ($value !== '') {
                $product[$target] = $value;
            }
        }

        if (!isset($product['product_group_id']) && trim((string) ($product['spu'] ?? '')) !== '') {
            $product['product_group_id'] = (string) $product['spu'];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $attributes
     * @return array<string, array{label:string,value:string}>
     */
    private function attributeMap(array $attributes): array
    {
        $map = [];
        foreach ($attributes as $group) {
            $items = is_array($group['items'] ?? null) ? $group['items'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $label = trim((string) ($item['label'] ?? $item['name'] ?? ''));
                $value = trim((string) ($item['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                foreach ([$item['code'] ?? '', $item['attribute_code'] ?? '', $label] as $key) {
                    $key = $this->normalizeKey((string) $key);
                    if ($key !== '') {
                        $map[$key] = ['label' => $label, 'value' => $value];
                    }
                }
            }
        }
        return $map;
    }

    /**
     * @param array<string, array{label:string,value:string}> $attributeMap
     * @param string[] $codes
     */
    private function attributeValue(array $attributeMap, array $codes): string
    {
        foreach ($codes as $code) {
            $key = $this->normalizeKey($code);
            if (isset($attributeMap[$key])) {
                return $attributeMap[$key]['value'];
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $productImages
     * @return string[]
     */
    private function collectImages(array $product, array $productImages, string $pageUrl): array
    {
        $images = [];
        foreach ([
            $product['main_image'] ?? null,
            $product['image'] ?? null,
            $product['images'] ?? null,
            $productImages,
        ] as $source) {
            foreach ($this->listArray($source) as $item) {
                if (is_array($item)) {
                    $item = $item['url'] ?? $item['src'] ?? $item['image'] ?? '';
                }
                $url = $this->absoluteUrl((string) $item, $pageUrl);
                if ($url !== '') {
                    $images[$url] = true;
                }
            }
        }
        return array_keys($images);
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $attributes
     * @return array<int, array<string, string>>
     */
    private function additionalProperties(array $product, array $attributes): array
    {
        $properties = [];
        foreach ([$product['specifications'] ?? [], $product['additional_property'] ?? []] as $source) {
            foreach ($this->listArray($source) as $item) {
                $this->appendProperty($properties, $item);
            }
        }

        foreach ($attributes as $group) {
            $items = is_array($group['items'] ?? null) ? $group['items'] : [];
            foreach ($items as $item) {
                $this->appendProperty($properties, $item);
            }
        }

        return array_slice(array_values($properties), 0, 80);
    }

    /**
     * @param array<string, array<string, string>> $properties
     */
    private function appendProperty(array &$properties, mixed $item): void
    {
        if (!is_array($item)) {
            return;
        }
        $name = trim((string) ($item['label'] ?? $item['name'] ?? ''));
        $value = $item['value'] ?? '';
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map('strval', $value)));
        }
        $value = trim((string) $value);
        if ($name === '' || $value === '') {
            return;
        }
        $property = [
            '@type' => 'PropertyValue',
            'name' => $name,
            'value' => $value,
        ];
        $code = trim((string) ($item['code'] ?? $item['attribute_code'] ?? $item['propertyID'] ?? ''));
        if ($code !== '') {
            $property['propertyID'] = $code;
        }
        $properties[$this->normalizeKey($name)] = $property;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $configurableOptions
     */
    private function applyConfigurableOptions(array &$product, array $configurableOptions): void
    {
        $attributes = $this->listArray($configurableOptions['attributes'] ?? []);
        $variants = $this->listArray($configurableOptions['variants'] ?? []);
        if ($attributes === [] && $variants === []) {
            return;
        }

        $optionLookup = $this->optionLookup($attributes);
        $normalizedVariants = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            foreach ($this->listArray($variant['option_ids'] ?? []) as $optionId) {
                $option = $optionLookup[(int) $optionId] ?? null;
                if (!$option) {
                    continue;
                }
                $code = $this->normalizeKey($option['attribute_code']);
                if (in_array($code, ['color', 'colour', 'size', 'material', 'pattern'], true)) {
                    $target = $code === 'colour' ? 'color' : $code;
                    $variant[$target] = $option['value'];
                }
            }
            $normalizedVariants[] = $variant;
        }

        $variesBy = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $code = trim((string) ($attribute['code'] ?? $attribute['name'] ?? ''));
            if ($code !== '') {
                $variesBy[] = $code;
            }
        }

        if ($normalizedVariants !== []) {
            $product['schema_type'] = 'ProductGroup';
            $product['variants'] = $normalizedVariants;
            $product['product_group_id'] = (string) ($product['product_group_id'] ?? $product['spu'] ?? $product['product_id'] ?? $product['sku'] ?? '');
        }
        if ($variesBy !== []) {
            $product['varies_by'] = array_values(array_unique($variesBy));
        }
    }

    /**
     * @param array<int, mixed> $attributes
     * @return array<int, array{attribute_code:string,value:string}>
     */
    private function optionLookup(array $attributes): array
    {
        $lookup = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $attributeCode = (string) ($attribute['code'] ?? $attribute['name'] ?? '');
            foreach ($this->listArray($attribute['options'] ?? []) as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $optionId = (int) ($option['option_id'] ?? 0);
                $value = trim((string) ($option['value'] ?? $option['origin_value'] ?? ''));
                if ($optionId > 0 && $value !== '') {
                    $lookup[$optionId] = [
                        'attribute_code' => $attributeCode,
                        'value' => $value,
                    ];
                }
            }
        }
        return $lookup;
    }

    /**
     * @param mixed $breadcrumbs
     */
    private function categoryPath(mixed $breadcrumbs): string
    {
        $names = [];
        foreach ($this->listArray($breadcrumbs) as $breadcrumb) {
            if (!is_array($breadcrumb)) {
                continue;
            }
            $name = trim((string) ($breadcrumb['name'] ?? $breadcrumb['label'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return implode(' > ', $names);
    }

    private function lastCategory(mixed $breadcrumbs): string
    {
        $path = $this->categoryPath($breadcrumbs);
        if ($path === '') {
            return '';
        }
        $parts = array_map('trim', explode('>', $path));
        return (string) end($parts);
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $context
     */
    private function canonicalUrl(array $product, array $context): string
    {
        $current = (string) ($context['url'] ?? '');
        $canonical = (string) ($context['canonical_url'] ?? '');
        $productUrl = trim((string) ($product['url'] ?? ''));
        if ($productUrl !== '') {
            return $this->canonicalize($this->absoluteUrl($productUrl, $current));
        }

        $handle = trim((string) ($product['handle'] ?? ''));
        if ($handle !== '') {
            return $this->origin($current) . '/product/' . rawurlencode(trim($handle, '/'));
        }

        $productId = (int) ($product['product_id'] ?? $product['id'] ?? 0);
        if ($productId > 0 && $current !== '') {
            $currentParts = parse_url($current);
            $canonicalParts = parse_url($canonical);
            parse_str((string) ($currentParts['query'] ?? ''), $query);
            $currentId = (int) ($query['id'] ?? $query['product_id'] ?? 0);
            if ($currentId === $productId
                && is_array($currentParts)
                && is_array($canonicalParts)
                && ($currentParts['path'] ?? '') === ($canonicalParts['path'] ?? '')
                && empty($canonicalParts['query'])) {
                $port = isset($currentParts['port']) ? ':' . $currentParts['port'] : '';
                return (string) ($currentParts['scheme'] ?? 'https') . '://' . (string) ($currentParts['host'] ?? '') . $port
                    . (string) ($currentParts['path'] ?? '/')
                    . '?id=' . $productId;
            }
        }

        return $canonical;
    }

    /**
     * @param mixed $breadcrumbs
     * @param array<string, mixed> $product
     * @return array<int, array<string, string>>
     */
    private function withProductBreadcrumb(mixed $breadcrumbs, array $product, string $productUrl): array
    {
        $items = [];
        foreach ($this->listArray($breadcrumbs) as $breadcrumb) {
            if (!is_array($breadcrumb)) {
                continue;
            }
            $name = trim((string) ($breadcrumb['name'] ?? $breadcrumb['label'] ?? ''));
            if ($name === '') {
                continue;
            }
            $items[] = [
                'name' => $name,
                'url' => (string) ($breadcrumb['url'] ?? ''),
            ];
        }

        $name = trim((string) ($product['name'] ?? $product['title'] ?? ''));
        if ($name !== '' && (string) ($items[array_key_last($items)]['name'] ?? '') !== $name) {
            $items[] = [
                'name' => $name,
                'url' => $productUrl,
            ];
        }

        return $items;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array{question:string,answer:string}>
     */
    private function qaFaqs(array $items): array
    {
        $faqs = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? $item['q'] ?? $item['title'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? $item['a'] ?? $item['content'] ?? ''));
            if ($question !== '' && $answer !== '') {
                $faqs[] = ['question' => $question, 'answer' => $answer];
            }
        }
        return $faqs;
    }

    private function readTemplate($template, string $key): mixed
    {
        if (is_object($template) && method_exists($template, 'getData')) {
            try {
                return $template->getData($key);
            } catch (\Throwable) {
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int, mixed>
     */
    private function listArray(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_is_list($decoded) ? $decoded : [$decoded];
            }
            return [$value];
        }
        if (!is_array($value)) {
            return [$value];
        }
        return array_is_list($value) ? $value : [$value];
    }

    private function absoluteUrl(string $url, string $pageUrl): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $url)) {
            return $this->canonicalize($url);
        }
        $origin = $this->origin($pageUrl);
        if ($origin === '') {
            return $url;
        }
        return $origin . '/' . ltrim($url, '/');
    }

    private function canonicalize(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        return (string) ($parts['scheme'] ?? 'https') . '://' . (string) $parts['host'] . $port . $path;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return (string) ($parts['scheme'] ?? 'https') . '://' . (string) $parts['host'] . $port;
    }

    private function normalizeKey(string $value): string
    {
        return trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $value)), '_');
    }
}
