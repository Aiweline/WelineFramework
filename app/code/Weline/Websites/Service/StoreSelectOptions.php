<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

/**
 * StoreSelect taglib 选项组装（value = store_id）。
 *
 * 跨模块请用 w_query('websites', 'getStoreCatalogV1', ['website_id' => $id])，
 * 再经本类 fromRows；不要硬绑 Store Model。
 */
final class StoreSelectOptions
{
    /**
     * @param list<mixed> $rows store 行（store_id|id、name、code）
     * @return list<array{value: string, label: string, meta: string, code?: string}>
     */
    public static function fromRows(array $rows): array
    {
        $options = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row['store_id'] ?? $row['id'] ?? -1);
            if ($id < 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $name = \trim((string)($row['name'] ?? ''));
            $code = \trim((string)($row['code'] ?? ''));
            $metaParts = [];
            if ($code !== '') {
                $metaParts[] = $code;
            }
            if (!empty($row['is_default'])) {
                $metaParts[] = (string)__('默认');
            }
            $option = [
                'value' => (string)$id,
                'label' => $name !== '' ? ('#' . $id . ' ' . $name) : ('#' . $id),
                'meta' => \implode(' · ', $metaParts),
            ];
            if ($code !== '') {
                $option['code'] = $code;
            }
            $options[] = $option;
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string, meta: string, code?: string}>
     */
    public static function forWebsite(int $websiteId, string $area = 'backend'): array
    {
        if ($websiteId < 0) {
            return [];
        }
        $rows = [];
        try {
            $queried = \w_query('websites', 'getStoreCatalogV1', ['website_id' => $websiteId], $area);
            if (\is_array($queried)) {
                $rows = $queried;
            }
        } catch (\Throwable) {
            $rows = [];
        }

        return self::fromRows($rows);
    }

    /**
     * @param list<array{value?: string, label?: string}> $options
     */
    public static function resolveDisplay(array $options, string $selectedValue): string
    {
        $selectedValue = \trim($selectedValue);
        if ($selectedValue === '') {
            return '';
        }
        foreach ($options as $option) {
            if (!\is_array($option)) {
                continue;
            }
            if ((string)($option['value'] ?? '') !== $selectedValue) {
                continue;
            }
            $label = \trim((string)($option['label'] ?? ''));

            return $label !== '' ? $label : ('#' . $selectedValue);
        }

        return '#' . $selectedValue;
    }

    /**
     * @return array{options: list<array{value: string, label: string, meta: string}>, options_json: string, value: string, display: string}
     */
    public static function forSelect(int $websiteId, string $selectedValue = '0', string $area = 'backend'): array
    {
        $options = self::forWebsite($websiteId, $area);
        if ($options === []) {
            $options = [[
                'value' => '0',
                'label' => '#0 ' . (string)__('默认店铺'),
                'meta' => (string)__('默认'),
            ]];
        }
        $selectedValue = \trim($selectedValue);
        $values = \array_map(static fn(array $o): string => (string)($o['value'] ?? ''), $options);
        if ($selectedValue === '' || !\in_array($selectedValue, $values, true)) {
            $selectedValue = (string)($options[0]['value'] ?? '0');
        }
        $json = \json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';

        return [
            'options' => $options,
            'options_json' => $json,
            'value' => $selectedValue,
            'display' => self::resolveDisplay($options, $selectedValue),
        ];
    }
}
