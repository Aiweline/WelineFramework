<?php

declare(strict_types=1);

namespace Weline\Compare\Service;

use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\CompareMode;

/**
 * Builds storefront compare rows from Product EAV specifications.
 */
final class CompareSpecificationMatrix
{
    public const PRODUCT_ENTITY_CODE = 'product';

    /** @var array<string, AttributeMetadata>|null */
    private ?array $attributeIndex = null;

    public function __construct(
        private readonly AttributeMetadataCatalogInterface $catalog,
        private readonly CompareNumericValueParser $parser = new CompareNumericValueParser(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{code: string, label: string, values: list<string>, differs: bool, compare_mode: string}>
     */
    public function buildRows(array $items): array
    {
        if ($items === []) {
            return [];
        }

        /** @var array<int, array<string, string>> $valueMaps */
        $valueMaps = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $map = [];
            foreach ($this->specificationsFromItem($item) as $spec) {
                $code = strtolower(trim((string)($spec['code'] ?? '')));
                $value = trim((string)($spec['value'] ?? ''));
                if ($code !== '' && $value !== '') {
                    $map[$code] = $value;
                }
            }
            $valueMaps[$productId] = $map;
        }

        $codes = [];
        foreach ($valueMaps as $map) {
            foreach (array_keys($map) as $code) {
                $codes[$code] = true;
            }
        }

        $sortedCodes = array_keys($codes);
        sort($sortedCodes);

        $rows = [];
        foreach ($sortedCodes as $code) {
            $values = [];
            $hasAny = false;
            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $value = trim((string)($valueMaps[$productId][$code] ?? ''));
                $values[] = $value;
                if ($value !== '') {
                    $hasAny = true;
                }
            }
            if (!$hasAny) {
                continue;
            }

            $metadata = $this->attributeIndex()[$code] ?? null;
            $compareMode = CompareMode::normalize($metadata?->compareMode ?? CompareMode::NONE);
            $rows[] = [
                'code' => $code,
                'label' => $this->labelFor($code, $metadata),
                'values' => $values,
                'differs' => $this->rowDiffers($values),
                'compare_mode' => $compareMode,
            ];
        }

        return $rows;
    }

    /**
     * @param array{values?: list<string>, compare_mode?: string} $specRow
     */
    public function specLabelHighlightClass(array $specRow): string
    {
        $values = $this->valuesFromSpecRow($specRow);
        $mode = CompareMode::normalize($specRow['compare_mode'] ?? CompareMode::NONE);
        if ($mode === CompareMode::NONE) {
            return '';
        }
        if ($mode === CompareMode::DIFF || $this->shouldFallbackToDiff($values, $mode)) {
            return $this->labelIsHighlight($values) ? ' storefront-compare__label--hit' : '';
        }

        return '';
    }

    /**
     * @param array{values?: list<string>, compare_mode?: string} $specRow
     */
    public function specCellHighlightClass(int $index, array $specRow): string
    {
        $values = $this->valuesFromSpecRow($specRow);
        $mode = CompareMode::normalize($specRow['compare_mode'] ?? CompareMode::NONE);
        if ($mode === CompareMode::NONE) {
            return '';
        }

        $value = trim((string)($values[$index] ?? ''));
        if ($mode === CompareMode::DIFF || $this->shouldFallbackToDiff($values, $mode)) {
            return $this->cellIsHighlight($value, $values) ? ' storefront-compare__cell--hit' : '';
        }

        if (in_array($index, $this->bestValueIndices($values, $mode), true)) {
            return ' storefront-compare__cell--best-price';
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function priceCellIsHighlight(int $index, array $items): bool
    {
        return in_array($index, $this->lowestPriceIndices($items), true);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<int>
     */
    public function lowestPriceIndices(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $min = null;
        /** @var list<int> $indices */
        $indices = [];
        $comparablePrices = [];

        foreach ($items as $itemIndex => $item) {
            $price = (float)($item['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $comparablePrices[] = $price;
            if ($min === null || $price < $min) {
                $min = $price;
                $indices = [$itemIndex];
                continue;
            }
            if ($price === $min) {
                $indices[] = $itemIndex;
            }
        }

        if ($min === null || count(array_unique($comparablePrices)) <= 1) {
            return [];
        }

        return $indices;
    }

    /**
     * @param list<string> $values
     */
    public function rowDiffers(array $values): bool
    {
        if ($values === []) {
            return false;
        }

        $normalized = [];
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text === '' || $text === '—') {
                $text = '';
            } else {
                $text = mb_strtolower($text);
            }
            $normalized[] = $text;
        }

        return count(array_unique($normalized)) > 1;
    }

    /**
     * @param list<string> $values
     */
    public function labelIsHighlight(array $values): bool
    {
        if (!$this->rowDiffers($values)) {
            return false;
        }

        foreach ($values as $value) {
            if ($this->cellHasValue($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $values
     */
    public function cellIsHighlight(string $value, array $values): bool
    {
        return $this->labelIsHighlight($values) && $this->cellHasValue($value);
    }

    /**
     * @param list<string> $values
     * @return list<int>
     */
    public function bestValueIndices(array $values, string $mode): array
    {
        $mode = CompareMode::normalize($mode);
        if (!in_array($mode, [CompareMode::HIGHER_BETTER, CompareMode::LOWER_BETTER], true)) {
            return [];
        }

        /** @var array<int, float> $parsed */
        $parsed = [];
        foreach ($values as $index => $value) {
            $number = $this->parser->parse((string)$value);
            if ($number !== null) {
                $parsed[$index] = $number;
            }
        }

        if (count($parsed) < 2 || count(array_unique($parsed)) <= 1) {
            return [];
        }

        $target = $mode === CompareMode::HIGHER_BETTER
            ? max($parsed)
            : min($parsed);

        $indices = [];
        foreach ($parsed as $index => $number) {
            if ($number === $target) {
                $indices[] = $index;
            }
        }

        return $indices;
    }

    private function cellHasValue(string $value): bool
    {
        $text = trim($value);
        return $text !== '' && $text !== '—';
    }

    /**
     * @param list<string> $values
     */
    private function shouldFallbackToDiff(array $values, string $mode): bool
    {
        if (!in_array($mode, [CompareMode::HIGHER_BETTER, CompareMode::LOWER_BETTER], true)) {
            return false;
        }

        $parsedCount = 0;
        foreach ($values as $value) {
            if ($this->parser->parse((string)$value) !== null) {
                ++$parsedCount;
            }
        }

        return $parsedCount < 2;
    }

    /**
     * @param array{values?: list<string>} $specRow
     * @return list<string>
     */
    private function valuesFromSpecRow(array $specRow): array
    {
        $values = $specRow['values'] ?? [];
        return is_array($values) ? array_map(static fn ($value): string => trim((string)$value), $values) : [];
    }

    /**
     * @return array<string, AttributeMetadata>
     */
    private function attributeIndex(): array
    {
        if ($this->attributeIndex === null) {
            $this->attributeIndex = $this->catalog->attributeIndexByEntityCode(self::PRODUCT_ENTITY_CODE);
        }

        return $this->attributeIndex;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array{code: string, value: string}>
     */
    private function specificationsFromItem(array $item): array
    {
        $specifications = $item['specifications'] ?? null;
        if (is_array($specifications) && $specifications !== []) {
            $out = [];
            foreach ($specifications as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $code = strtolower(trim((string)($spec['code'] ?? '')));
                $value = trim((string)($spec['value'] ?? ''));
                if ($code !== '' && $value !== '') {
                    $out[] = ['code' => $code, 'value' => $value];
                }
            }

            return $out;
        }

        $attributes = $item['attributes'] ?? null;
        if (!is_array($attributes)) {
            return [];
        }

        $out = [];
        foreach ($attributes as $code => $value) {
            if (!is_string($code) || (!is_string($value) && !is_numeric($value))) {
                continue;
            }
            $normalizedCode = strtolower(trim($code));
            $normalizedValue = trim((string)$value);
            if ($normalizedCode !== '' && $normalizedValue !== '') {
                $out[] = ['code' => $normalizedCode, 'value' => $normalizedValue];
            }
        }

        return $out;
    }

    private function labelFor(string $code, ?AttributeMetadata $metadata): string
    {
        if ($metadata !== null && trim($metadata->name) !== '') {
            return $metadata->name;
        }

        return ucwords(str_replace('_', ' ', $code));
    }
}
