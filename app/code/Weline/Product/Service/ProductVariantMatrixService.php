<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Deterministic variant matrix generator. EAV owns axis definitions; Product
 * owns the selected axis values and resulting Offer combinations.
 */
final class ProductVariantMatrixService
{
    private const MAX_COMBINATIONS = 10000;

    /**
     * @param list<array{code:string,label?:string,options:list<mixed>}> $axes
     * @param array<string, string> $skuOverrides combination_key => SKU
     * @return list<array{combination:array<string,string>,combination_key:string,sku:string}>
     */
    public function generate(array $axes, string $skuPrefix, array $skuOverrides = []): array
    {
        $axes = $this->normalizeAxes($axes);
        if ($axes === []) {
            throw new \InvalidArgumentException('variant_axes_required');
        }
        $skuPrefix = $this->normalizeSku($skuPrefix, 'variant_sku_prefix_invalid');
        $overrides = [];
        foreach ($skuOverrides as $key => $sku) {
            if (!is_string($key) || !is_string($sku)) {
                throw new \InvalidArgumentException('variant_sku_override_invalid');
            }
            $key = trim($key);
            if ($key === '' || isset($overrides[$key])) {
                throw new \InvalidArgumentException('variant_sku_override_duplicate');
            }
            $overrides[$key] = $this->normalizeSku($sku, 'variant_sku_override_invalid');
        }

        $count = 1;
        foreach ($axes as $axis) {
            $count *= count($axis['options']);
            if ($count > self::MAX_COMBINATIONS) {
                throw new \InvalidArgumentException('variant_combination_limit_exceeded');
            }
        }

        $combinations = [[]];
        foreach ($axes as $axis) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($axis['options'] as $option) {
                    $candidate = $combination;
                    $candidate[$axis['code']] = $option['value'];
                    $next[] = $candidate;
                }
            }
            $combinations = $next;
        }

        $rows = [];
        $seenKeys = [];
        $seenSkus = [];
        $usedOverrides = [];
        foreach ($combinations as $combination) {
            $key = $this->combinationKey($combination);
            if (isset($seenKeys[$key])) {
                throw new \InvalidArgumentException('variant_combination_duplicate');
            }
            $seenKeys[$key] = true;
            $sku = $overrides[$key] ?? $this->generatedSku($skuPrefix, $combination);
            if (isset($overrides[$key])) {
                $usedOverrides[$key] = true;
            }
            $skuIdentity = strtolower($sku);
            if (isset($seenSkus[$skuIdentity])) {
                throw new \InvalidArgumentException('variant_sku_duplicate');
            }
            $seenSkus[$skuIdentity] = true;
            $rows[] = [
                'combination' => $combination,
                'combination_key' => $key,
                'sku' => $sku,
            ];
        }
        foreach ($overrides as $key => $_sku) {
            if (!isset($usedOverrides[$key])) {
                throw new \InvalidArgumentException('variant_sku_override_unknown_combination');
            }
        }

        return $rows;
    }

    /** @param array<string, string> $combination */
    public function combinationKey(array $combination): string
    {
        if ($combination === []) {
            throw new \InvalidArgumentException('variant_combination_empty');
        }
        ksort($combination, SORT_STRING);
        $segments = [];
        foreach ($combination as $axis => $value) {
            $axis = trim((string)$axis);
            $value = trim((string)$value);
            if ($axis === '' || $value === '') {
                throw new \InvalidArgumentException('variant_combination_invalid');
            }
            $segments[] = rawurlencode($axis) . '=' . rawurlencode($value);
        }
        $key = implode('|', $segments);
        if (strlen($key) > 512) {
            throw new \InvalidArgumentException('variant_combination_key_too_long');
        }
        return $key;
    }

    /**
     * @param list<array{code:string,label?:string,options:list<mixed>}> $axes
     * @param list<array<string,mixed>> $submittedRows
     * @param list<array<string,mixed>> $existingOffers
     * @return array{axes:list<array<string,mixed>>,desired:list<array<string,mixed>>,create:list<array<string,mixed>>,update:list<array<string,mixed>>,disable:list<array<string,mixed>>,impact:list<array<string,mixed>>}
     */
    public function reconcile(
        array $axes,
        string $skuPrefix,
        array $submittedRows,
        array $existingOffers,
    ): array {
        $normalizedAxes = $this->normalizeAxes($axes);
        $submittedByKey = [];
        $skuOverrides = [];
        foreach ($submittedRows as $row) {
            if (!is_array($row) || !is_array($row['combination'] ?? null)) {
                throw new \InvalidArgumentException('variant_offer_row_invalid');
            }
            $combination = [];
            foreach ($row['combination'] as $axis => $value) {
                $combination[trim((string)$axis)] = trim((string)$value);
            }
            $key = $this->combinationKey($combination);
            $claimedKey = trim((string)($row['combination_key'] ?? ''));
            if ($claimedKey !== '' && $claimedKey !== $key) {
                throw new \InvalidArgumentException('variant_combination_key_mismatch');
            }
            if (isset($submittedByKey[$key])) {
                throw new \InvalidArgumentException('variant_combination_duplicate');
            }
            $sku = trim((string)($row['sku'] ?? ''));
            if ($sku !== '') {
                $skuOverrides[$key] = $sku;
            }
            $row['combination'] = $combination;
            $row['combination_key'] = $key;
            $submittedByKey[$key] = $row;
        }

        $generated = $this->generate($normalizedAxes, $skuPrefix, $skuOverrides);
        $generatedKeys = array_fill_keys(array_column($generated, 'combination_key'), true);
        foreach (array_keys($submittedByKey) as $submittedKey) {
            if (!isset($generatedKeys[$submittedKey])) {
                throw new \InvalidArgumentException('variant_combination_not_in_axes');
            }
        }

        $existingByKey = [];
        $existingSkuKeys = [];
        foreach ($existingOffers as $offer) {
            if (!is_array($offer)) {
                throw new \InvalidArgumentException('variant_existing_offer_invalid');
            }
            $key = trim((string)($offer['combination_key'] ?? ''));
            if ($key === '') {
                throw new \InvalidArgumentException('variant_existing_combination_invalid');
            }
            if (isset($existingByKey[$key])) {
                throw new \InvalidArgumentException('variant_existing_combination_duplicate');
            }
            $existingByKey[$key] = $offer;
            $existingSku = strtolower(trim((string)($offer['sku'] ?? '')));
            if ($existingSku !== '') {
                $existingSkuKeys[$existingSku] = $key;
            }
        }

        $desired = [];
        $create = [];
        $update = [];
        $usedExisting = [];
        foreach ($generated as $generatedRow) {
            $key = $generatedRow['combination_key'];
            $input = $submittedByKey[$key] ?? [];
            $row = array_merge($input, $generatedRow);
            $reservedBy = $existingSkuKeys[strtolower($row['sku'])] ?? null;
            if ($reservedBy !== null && $reservedBy !== $key) {
                throw new \InvalidArgumentException('variant_sku_reserved');
            }
            $existing = $existingByKey[$key] ?? null;
            if ($existing === null) {
                if (trim((string)($input['global_offer_uuid'] ?? '')) !== '') {
                    throw new \InvalidArgumentException('variant_offer_uuid_unknown');
                }
                $create[] = $row;
                $desired[] = $row;
                continue;
            }

            $usedExisting[$key] = true;
            $expectedUuid = trim((string)($existing['global_offer_uuid'] ?? ''));
            $submittedUuid = trim((string)($input['global_offer_uuid'] ?? ''));
            if ($submittedUuid !== '' && $submittedUuid !== $expectedUuid) {
                throw new \InvalidArgumentException('variant_offer_uuid_mismatch');
            }
            foreach ([
                'offer_version' => 'publish_version',
                'identity_version' => 'identity_version',
            ] as $submittedVersion => $existingVersion) {
                if (!array_key_exists($submittedVersion, $input)) {
                    throw new \InvalidArgumentException('variant_' . $submittedVersion . '_required');
                }
                if ((int)$input[$submittedVersion] !== (int)($existing[$existingVersion] ?? -1)) {
                    throw new ProductV2ConflictException(
                        'variant_' . $submittedVersion . '_conflict',
                        (string)__('Offer 规格版本冲突，请刷新后重试'),
                        [
                            'combination_key' => $key,
                            'expected' => (int)$input[$submittedVersion],
                            'actual' => (int)($existing[$existingVersion] ?? -1),
                        ],
                    );
                }
            }
            $row['offer_id'] = (int)($existing['offer_id'] ?? 0);
            $row['global_offer_uuid'] = $expectedUuid;
            $row['offer_version'] = (int)($existing['publish_version'] ?? 0);
            $row['identity_version'] = (int)($existing['identity_version'] ?? 0);
            $row['existing_status'] = (string)($existing['status'] ?? 'draft');
            $update[] = $row;
            $desired[] = $row;
        }

        $disable = [];
        $impact = [];
        foreach ($existingByKey as $key => $offer) {
            if (isset($usedExisting[$key])
                || in_array((string)($offer['status'] ?? ''), ['disabled', 'archived'], true)
            ) {
                continue;
            }
            $disable[] = $offer;
            $impact[] = [
                'action' => 'disable',
                'global_offer_uuid' => (string)($offer['global_offer_uuid'] ?? ''),
                'sku' => (string)($offer['sku'] ?? ''),
                'combination_key' => $key,
                'status' => (string)($offer['status'] ?? ''),
            ];
        }

        return [
            'axes' => $normalizedAxes,
            'desired' => $desired,
            'create' => $create,
            'update' => $update,
            'disable' => $disable,
            'impact' => $impact,
        ];
    }

    /**
     * @param list<array{code:string,label?:string,options:list<mixed>}> $axes
     * @return list<array{code:string,label:string,options:list<array{value:string,label:string}>}>
     */
    private function normalizeAxes(array $axes): array
    {
        $result = [];
        $seenAxes = [];
        foreach ($axes as $axis) {
            if (!is_array($axis)) {
                throw new \InvalidArgumentException('variant_axis_invalid');
            }
            $code = strtolower(trim((string)($axis['code'] ?? '')));
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code)) {
                throw new \InvalidArgumentException('variant_axis_code_invalid');
            }
            if (isset($seenAxes[$code])) {
                throw new \InvalidArgumentException('variant_axis_duplicate');
            }
            $seenAxes[$code] = true;
            $rawOptions = $axis['options'] ?? null;
            if (!is_array($rawOptions) || $rawOptions === []) {
                throw new \InvalidArgumentException('variant_axis_options_required');
            }
            $options = [];
            $seenOptions = [];
            foreach ($rawOptions as $rawOption) {
                if (is_array($rawOption)) {
                    $value = trim((string)($rawOption['value'] ?? ''));
                    $label = trim((string)($rawOption['label'] ?? $value));
                } elseif (is_scalar($rawOption)) {
                    $value = trim((string)$rawOption);
                    $label = $value;
                } else {
                    throw new \InvalidArgumentException('variant_axis_option_invalid');
                }
                if ($value === '' || strlen($value) > 128) {
                    throw new \InvalidArgumentException('variant_axis_option_invalid');
                }
                $identity = strtolower($value);
                if (isset($seenOptions[$identity])) {
                    throw new \InvalidArgumentException('variant_axis_option_duplicate');
                }
                $seenOptions[$identity] = true;
                $options[] = ['value' => $value, 'label' => $label === '' ? $value : $label];
            }
            $result[] = [
                'code' => $code,
                'label' => trim((string)($axis['label'] ?? $code)),
                'options' => $options,
            ];
        }
        return $result;
    }

    /** @param array<string, string> $combination */
    private function generatedSku(string $prefix, array $combination): string
    {
        $parts = [$prefix];
        foreach ($combination as $value) {
            $part = strtoupper(trim((string)$value));
            $part = preg_replace('/[^A-Z0-9]+/', '-', $part) ?? '';
            $part = trim($part, '-');
            if ($part === '') {
                $part = substr(strtoupper(hash('sha256', (string)$value)), 0, 8);
            }
            $parts[] = $part;
        }
        return $this->normalizeSku(implode('-', $parts), 'variant_sku_too_long');
    }

    private function normalizeSku(string $sku, string $errorCode): string
    {
        $sku = trim($sku);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $sku)) {
            throw new \InvalidArgumentException($errorCode);
        }
        return $sku;
    }
}
