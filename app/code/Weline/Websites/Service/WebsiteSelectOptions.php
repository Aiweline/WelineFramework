<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

/**
 * WebsiteSelect taglib 选项组装（value / label / meta）。
 *
 * 跨模块请用 w_query('websites', 'getWebsiteSelectOptions')，不要硬绑本类。
 */
final class WebsiteSelectOptions
{
    /**
     * @param list<mixed> $rows website 行（website_id|id、name、code）
     * @return list<array{value: string, label: string, meta: string}>
     */
    public static function fromRows(array $rows): array
    {
        $options = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row['website_id'] ?? $row['id'] ?? -1);
            if ($id < 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $name = \trim((string)($row['name'] ?? ''));
            $code = \trim((string)($row['code'] ?? ''));
            $options[] = [
                'value' => (string)$id,
                'label' => $name !== '' ? $name : (string)__('站点 %{1}', [$id]),
                'meta' => $code,
            ];
        }

        return $options;
    }

    /**
     * @param list<array{value?: string, label?: string, meta?: string}> $options
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
     * @return list<array{value: string, label: string, meta: string}>
     */
    public static function loadViaQuery(string $area = 'backend'): array
    {
        $rows = [];
        try {
            $queried = \w_query('websites', 'getWebsiteList', [], $area);
            if (\is_array($queried)) {
                $rows = $queried;
            }
        } catch (\Throwable) {
            $rows = [];
        }

        return self::fromRows($rows);
    }

    /**
     * @return array{options: list<array{value: string, label: string, meta: string}>, options_json: string, display: string}
     */
    public static function forSelect(string $selectedValue = '', string $area = 'backend'): array
    {
        $options = self::loadViaQuery($area);
        $json = \json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';

        return [
            'options' => $options,
            'options_json' => $json,
            'display' => self::resolveDisplay($options, $selectedValue),
        ];
    }
}
