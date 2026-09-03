<?php

declare(strict_types=1);

namespace Weline\Product\Service\Hanfu1688;

final class OfferEavMapper
{
    /**
     * @param array<string,mixed> $offer
     * @param array<string,mixed> $source
     * @return list<array<string,mixed>>
     */
    /**
     * Build the EAV definitions and the real configurable-product matrix from a public 1688 offer.
     *
     * @param array<string,mixed> $offer
     * @return array{
     *   definitions:list<array<string,mixed>>,
     *   axes:list<array<string,mixed>>,
     *   product_values:array<string,list<string>>,
     *   sku_overrides:array<string,string>,
     *   prices:list<array<string,mixed>>,
     *   inventory:list<array<string,mixed>>,
     *   variant_media:list<array{combination:array<string,string>,image_url:string}>
     * }
     */
    public function catalog(array $offer, string $skuPrefix): array
    {
        $definitions = [];
        foreach ($this->specifications($offer) as $name => $values) {
            $code = $this->attributeCode($name);
            $variant = $this->isVariantAxis($name);
            $definition = $definitions[$code] ?? [
                'code' => $code,
                'name' => $this->attributeName($code, $name),
                'variant' => $variant,
                'multiple' => $variant || count($values) > 1,
                'options' => [],
            ];
            $usedCodes = [];
            foreach ($definition['options'] as $option) {
                $usedCodes[(string)$option['code']] = (string)$option['label'];
            }
            foreach ($values as $label) {
                $optionCode = $this->optionCode($code, $label);
                if (isset($usedCodes[$optionCode]) && $usedCodes[$optionCode] !== $label) {
                    $optionCode = rtrim(substr($optionCode, 0, 8), '-') . '-' . substr(hash('sha256', $label), 0, 4);
                }
                if (!isset($usedCodes[$optionCode])) {
                    $definition['options'][] = ['code' => $optionCode, 'label' => $label];
                    $usedCodes[$optionCode] = $label;
                }
            }
            $definition['variant'] = $definition['variant'] || $variant;
            $definition['multiple'] = $definition['multiple']
                || $variant
                || count($definition['options']) > 1;
            $definitions[$code] = $definition;
        }

        $variants = array_values(array_filter(
            is_array($offer['variants'] ?? null) ? $offer['variants'] : [],
            static fn(mixed $variant): bool => is_array($variant)
                && trim((string)($variant['specification'] ?? '')) !== '',
        ));
        $axisDefinitions = [];
        foreach ($definitions as $definition) {
            if (empty($definition['variant'])) {
                continue;
            }
            $complete = true;
            foreach ($variants as $variant) {
                if ($this->matchOption(
                    (string)$variant['specification'],
                    $definition['options'],
                ) === null) {
                    $complete = false;
                    break;
                }
            }
            if ($variants === [] || $complete) {
                $axisDefinitions[] = $definition;
            }
        }

        $axes = [];
        foreach ($axisDefinitions as $definition) {
            $axes[] = [
                'code' => $definition['code'],
                'label' => $definition['name'],
                'options' => array_map(
                    static fn(array $option): array => [
                        'value' => (string)$option['code'],
                        'label' => (string)$option['label'],
                    ],
                    $definition['options'],
                ),
            ];
        }

        $productValues = [];
        foreach ($definitions as $definition) {
            $productValues[(string)$definition['code']] = array_values(array_map(
                static fn(array $option): string => (string)$option['code'],
                $definition['options'],
            ));
        }

        $skuOverrides = [];
        $prices = [];
        $inventory = [];
        $variantMedia = [];
        $seenCombinations = [];
        foreach ($variants as $variant) {
            $combination = [];
            foreach ($axisDefinitions as $definition) {
                $option = $this->matchOption(
                    (string)$variant['specification'],
                    $definition['options'],
                );
                if ($option === null) {
                    $combination = [];
                    break;
                }
                $combination[(string)$definition['code']] = (string)$option['code'];
            }
            if ($combination === []) {
                continue;
            }
            $combinationKey = $this->combinationKey($combination);
            if (isset($seenCombinations[$combinationKey])) {
                continue;
            }
            $seenCombinations[$combinationKey] = true;
            $sku = $this->variantSku($skuPrefix, $combination);
            $skuOverrides[$combinationKey] = $sku;
            $variantImageUrl = trim((string)($variant['image_url'] ?? ''));
            if ($variantImageUrl !== '') {
                $variantMedia[$combinationKey] = [
                    'combination' => $combination,
                    'image_url' => $variantImageUrl,
                ];
            }

            $priceMinor = $this->yuanToMinor($variant['price'] ?? null);
            if ($priceMinor !== null) {
                $prices[] = [
                    'sku' => $sku,
                    'store_id' => 0,
                    'currency' => 'CNY',
                    'scope_state' => 'explicit',
                    'amount_minor' => $priceMinor,
                ];
            }
            if (($variant['public_available_quantity'] ?? null) !== null) {
                $inventory[] = [
                    'sku' => $sku,
                    'store_id' => 0,
                    'on_hand_minor' => max(0, (int)$variant['public_available_quantity']),
                ];
            }
        }

        return [
            'definitions' => array_values($definitions),
            'axes' => $axes,
            'product_values' => $productValues,
            'sku_overrides' => $skuOverrides,
            'prices' => $prices,
            'inventory' => $inventory,
            'variant_media' => array_values($variantMedia),
        ];
    }

    /**
     * @param array<string,mixed> $offer
     * @param array<string,mixed> $source
     * @param array<string,mixed>|null $catalog
     * @return list<array<string,mixed>>
     */
    public function map(
        array $offer,
        array $source,
        string $snapshotDigest,
        ?array $catalog = null,
    ): array {
        $offerId = trim((string)($offer['offer_id'] ?? ''));
        $sourceUrl = trim((string)($offer['detail_url'] ?? $offer['source_url'] ?? ''));
        $base = [
            'source_platform' => '1688',
            'source_offer_id' => $offerId,
            'source_url' => $sourceUrl,
            'source_shop_url' => trim((string)($source['shop_url'] ?? '')),
            'source_factory_url' => trim((string)($source['factory_url'] ?? '')),
            'source_snapshot_digest' => trim($snapshotDigest),
            'source_company_name' => trim((string)($source['company_name'] ?? '')),
        ];
        if (($offer['minimum_order_quantity'] ?? null) !== null) {
            $base['source_minimum_order_quantity'] = (string)max(
                0,
                (int)$offer['minimum_order_quantity'],
            );
        }

        $rows = [];
        foreach ($base as $code => $value) {
            if ($value === '') {
                continue;
            }
            $rows[] = $this->row($code, 'string', $value);
        }

        $catalog ??= $this->catalog($offer, 'HANFU');
        $definitions = [];
        foreach (is_array($catalog['definitions'] ?? null) ? $catalog['definitions'] : [] as $definition) {
            if (is_array($definition) && trim((string)($definition['code'] ?? '')) !== '') {
                $definitions[(string)$definition['code']] = $definition;
            }
        }
        foreach (is_array($catalog['product_values'] ?? null) ? $catalog['product_values'] : [] as $code => $values) {
            if (!is_array($values) || $values === []) {
                continue;
            }
            $values = array_values(array_unique(array_map('strval', $values)));
            $multiple = !empty($definitions[(string)$code]['multiple']);
            $rows[] = $this->row(
                (string)$code,
                $multiple ? 'multiselect' : 'select',
                $multiple ? $values : $values[0],
            );
        }

        return $rows;
    }

    /** @return array<string,list<string>> */
    private function specifications(array $offer): array
    {
        $result = [];
        foreach (is_array($offer['specifications'] ?? null) ? $offer['specifications'] : [] as $name => $values) {
            $name = $this->clean((string)$name);
            if ($name === '' || !is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                $value = $this->clean((string)$value);
                if ($value !== '') {
                    $result[$name][$value] = true;
                }
            }
        }
        foreach ($result as $name => $values) {
            $result[$name] = array_keys($values);
        }
        return $result;
    }

    private function attributeCode(string $name): string
    {
        $normalized = strtolower($name);
        return match (true) {
            preg_match('/颜色|色系|colour|color/u', $normalized) === 1 => 'color',
            preg_match('/尺码|尺寸|身高|size/u', $normalized) === 1 => 'size',
            preg_match('/款式|类型|组合|style|type/u', $normalized) === 1 => 'style_type',
            preg_match('/材质|面料|material|fabric/u', $normalized) === 1 => 'material',
            default => 'hanfu_' . str_replace('-', '_', $this->slug($name, 50)),
        };
    }

    private function attributeName(string $code, string $fallback): string
    {
        return match ($code) {
            'color' => '颜色',
            'size' => '尺码',
            'style_type' => '类型',
            'material' => '材质',
            default => $fallback,
        };
    }

    private function isVariantAxis(string $name): bool
    {
        return preg_match('/颜色|色系|colour|color|尺码|尺寸|身高|size|款式|类型|组合|style|type/iu', $name) === 1;
    }

    private function optionCode(string $attributeCode, string $label): string
    {
        if ($attributeCode === 'size') {
            $size = strtoupper((string)preg_replace('/\s+/u', '', $label));
            $known = [
                'S' => 's',
                'M' => 'm',
                'L' => 'l',
                'XL' => 'xl',
                'XXL' => 'xxl',
                'XXXL' => 'xxxl',
                '2XL' => '2xl',
                '3XL' => '3xl',
            ];
            if (isset($known[$size])) {
                return $known[$size];
            }
        }
        return $this->slug($label, 12);
    }

    /** @param list<array{code:string,label:string}> $options */
    private function matchOption(string $specification, array $options): ?array
    {
        $specification = $this->clean($specification);
        $parts = preg_split('/[;；,，|\/]+/u', $specification) ?: [];
        usort(
            $options,
            static fn(array $left, array $right): int => mb_strlen((string)$right['label'])
                <=> mb_strlen((string)$left['label']),
        );
        foreach ($options as $option) {
            $label = $this->clean((string)($option['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            foreach ($parts as $part) {
                if (strcasecmp(trim((string)$part), $label) === 0) {
                    return $option;
                }
            }
        }
        foreach ($options as $option) {
            $label = $this->clean((string)($option['label'] ?? ''));
            if ($label !== '' && mb_stripos($specification, $label) !== false) {
                return $option;
            }
        }
        return null;
    }

    /** @param array<string,string> $combination */
    private function combinationKey(array $combination): string
    {
        ksort($combination, SORT_STRING);
        $segments = [];
        foreach ($combination as $code => $value) {
            $segments[] = rawurlencode($code) . '=' . rawurlencode($value);
        }
        return implode('|', $segments);
    }

    /** @param array<string,string> $combination */
    /** @param array<string,string> $combination */
    private function variantSku(string $prefix, array $combination): string
    {
        $parts = [trim($prefix)];
        foreach ($combination as $axis => $value) {
            $parts[] = $this->compactSkuToken((string)$axis, (string)$value);
        }
        $sku = trim(implode('-', array_filter($parts, static fn(string $part): bool => $part !== '')), '-');
        if (strlen($sku) <= 36) {
            return $sku;
        }

        $shortPrefix = rtrim(substr(trim($prefix), 0, 21), '-');
        if ($shortPrefix === '') {
            $shortPrefix = 'HF';
        }
        return $shortPrefix
            . '-V'
            . strtoupper(substr(hash('sha256', $this->combinationKey($combination)), 0, 8));
    }

    private function compactSkuToken(string $axis, string $value): string
    {
        $axis = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $axis), '-'));
        $axisToken = match ($axis) {
            'color' => 'C',
            'size' => 'S',
            default => strtoupper(substr($axis !== '' ? $axis : 'x', 0, 1)),
        };
        $value = strtolower(trim($value));

        return $axisToken
            . strtoupper(substr(hash('sha256', $axis . '=' . $value), 0, 4));
    }

    private function yuanToMinor(mixed $amount): ?int
    {
        $amount = trim((string)$amount);
        if ($amount === '' || preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $amount, $match) !== 1) {
            return null;
        }
        return ((int)$match[1] * 100) + (int)str_pad($match[2] ?? '', 2, '0');
    }

    private function slug(string $value, int $maxLength): string
    {
        $source = $this->clean($value);
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Han-Latin; Latin-ASCII; Lower()');
            if ($transliterator !== null) {
                $value = (string)$transliterator->transliterate($source);
            }
        }
        $value = strtolower($value);
        $value = trim((string)preg_replace('/[^a-z0-9]+/', '-', $value), '-');
        if ($value === '') {
            $value = 'v-' . substr(hash('sha256', $source), 0, 10);
        }
        return rtrim(substr($value, 0, $maxLength), '-');
    }

    /** @return array<string,mixed> */
    private function row(string $code, string $valueType, mixed $value): array
    {
        return [
            'attribute_code' => $code,
            'store_id' => 0,
            'entity_type' => 'product',
            'locale' => '',
            'scope_state' => 'explicit',
            'value_type' => $valueType,
            'value' => $value,
        ];
    }


    private function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
