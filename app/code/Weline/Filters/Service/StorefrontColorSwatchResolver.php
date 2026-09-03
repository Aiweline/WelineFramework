<?php

declare(strict_types=1);

namespace Weline\Filters\Service;

/**
 * Resolves display swatches / hex values from color-like filter option labels.
 */
final class StorefrontColorSwatchResolver
{
    /** @var array<string, string> longest-first Chinese / English tokens → #RRGGBB */
    private const COLOR_MAP = [
        '浅灰色' => '#BDBDBD',
        '深灰色' => '#616161',
        '浅蓝色' => '#90CAF9',
        '深蓝色' => '#1565C0',
        '浅粉色' => '#F8BBD0',
        '深红色' => '#B71C1C',
        '米白色' => '#F5F0E6',
        '象牙白' => '#FFFFF0',
        '藏青色' => '#1A237E',
        '墨绿色' => '#1B5E20',
        '咖啡色' => '#6D4C41',
        '土黄色' => '#C7A86B',
        '粉红色' => '#EC407A',
        '紫红色' => '#C2185B',
        '天蓝色' => '#29B6F6',
        '白色' => '#FFFFFF',
        '黑色' => '#212121',
        '灰色' => '#9E9E9E',
        '红色' => '#C62828',
        '粉色' => '#EC407A',
        '蓝色' => '#1E88E5',
        '绿色' => '#43A047',
        '黄色' => '#FDD835',
        '紫色' => '#8E24AA',
        '橙色' => '#FB8C00',
        '青色' => '#00ACC1',
        '棕色' => '#6D4C41',
        '褐色' => '#795548',
        '米色' => '#F5E6C8',
        '金色' => '#C9A227',
        '银色' => '#B0BEC5',
        '卡其' => '#C3B091',
        '白' => '#FFFFFF',
        '黑' => '#212121',
        '灰' => '#9E9E9E',
        '红' => '#C62828',
        '粉' => '#EC407A',
        '蓝' => '#1E88E5',
        '绿' => '#43A047',
        '黄' => '#FDD835',
        '紫' => '#8E24AA',
        '橙' => '#FB8C00',
        '青' => '#00ACC1',
        '棕' => '#6D4C41',
        '米' => '#F5E6C8',
        '金' => '#C9A227',
        '银' => '#B0BEC5',
        'white' => '#FFFFFF',
        'black' => '#212121',
        'grey' => '#9E9E9E',
        'gray' => '#9E9E9E',
        'red' => '#C62828',
        'pink' => '#EC407A',
        'blue' => '#1E88E5',
        'green' => '#43A047',
        'yellow' => '#FDD835',
        'purple' => '#8E24AA',
        'orange' => '#FB8C00',
        'brown' => '#6D4C41',
        'beige' => '#F5E6C8',
        'gold' => '#C9A227',
        'silver' => '#B0BEC5',
        'navy' => '#1A237E',
    ];

    public function isColorAttribute(string $code, string $name = ''): bool
    {
        $code = strtolower(trim($code));
        $name = trim($name);
        if ($code === '') {
            return false;
        }
        if (in_array($code, ['color', 'colour', 'available_colors', 'available_colours', 'colors', 'colours'], true)) {
            return true;
        }
        if (str_contains($code, 'color') || str_contains($code, 'colour')) {
            return true;
        }

        return $name !== '' && (str_contains($name, '颜色') || str_contains($name, '色彩') || $name === '色');
    }

    /**
     * @return list<array{hex:string,token:string}>
     */
    public function resolveFromLabel(string $label): array
    {
        $label = trim($label);
        if ($label === '') {
            return [];
        }

        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $label) === 1) {
            return [['hex' => $this->normalizeHex($label), 'token' => $label]];
        }

        $found = [];
        $haystack = $label;
        $tokens = array_keys(self::COLOR_MAP);
        usort($tokens, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($tokens as $token) {
            $isAscii = preg_match('/^[a-z]+$/i', $token) === 1;
            if ($isAscii) {
                if (preg_match('/\b' . preg_quote($token, '/') . '\b/i', $haystack) !== 1) {
                    continue;
                }
            } elseif (!str_contains($haystack, $token)) {
                continue;
            }
            $hex = self::COLOR_MAP[$token];
            $key = strtolower($hex);
            if (isset($found[$key])) {
                continue;
            }
            $found[$key] = ['hex' => $hex, 'token' => $token];
            // Avoid shorter overlapping tokens after a longer match (e.g. 白色 then 白).
            $haystack = str_replace($token, str_repeat('·', mb_strlen($token)), $haystack);
        }

        return array_values($found);
    }

    /**
     * @param array{label?:string,value?:string,swatch_color?:string|null,color_hex?:string|null} $option
     * @return list<array{hex:string,token:string}>
     */
    public function resolveFromOption(array $option): array
    {
        foreach (['swatch_color', 'color_hex', 'hex'] as $key) {
            $raw = trim((string)($option[$key] ?? ''));
            if ($raw !== '' && preg_match('/^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $raw) === 1) {
                $hex = $this->normalizeHex($raw);

                return [['hex' => $hex, 'token' => $hex]];
            }
        }

        $label = trim((string)($option['label'] ?? $option['value'] ?? ''));

        return $this->resolveFromLabel($label);
    }

    private function normalizeHex(string $raw): string
    {
        $raw = ltrim(trim($raw), '#');
        if (strlen($raw) === 3) {
            $raw = $raw[0] . $raw[0] . $raw[1] . $raw[1] . $raw[2] . $raw[2];
        }

        return '#' . strtoupper($raw);
    }
}
