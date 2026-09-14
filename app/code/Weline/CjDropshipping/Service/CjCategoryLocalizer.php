<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Service;

/**
 * Localize CJ category display names for admin locale.
 *
 * CJ OpenAPI /product/getCategory returns English-only labels (no lang param).
 * We overlay a curated EN→ZH map for zh* locales; other locales keep English.
 */
final class CjCategoryLocalizer
{
    private static ?array $zhMap = null;

    public static function localePrefersZh(string $locale): bool
    {
        $locale = strtolower(str_replace('-', '_', trim($locale)));
        if ($locale === '') {
            return true;
        }

        return str_starts_with($locale, 'zh');
    }

    /**
     * Translate a single CJ English label for the given locale.
     */
    public static function translateLabel(string $label, string $locale): string
    {
        $label = trim($label);
        if ($label === '' || !self::localePrefersZh($locale)) {
            return $label;
        }

        return self::translatePart($label, self::zhMap());
    }

    /**
     * @param list<array{id:string,name:string,parent_id?:string,level?:int,path?:string}> $nodes
     * @return list<array{id:string,name:string,parent_id?:string,level?:int,path?:string}>
     */
    public static function localizeNodes(array $nodes, string $locale): array
    {
        if (!self::localePrefersZh($locale)) {
            return $nodes;
        }
        $map = self::zhMap();
        if ($map === []) {
            return $nodes;
        }
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $name = trim((string)($node['name'] ?? ''));
            $path = trim((string)($node['path'] ?? ''));
            if ($name !== '') {
                $node['name'] = self::translatePart($name, $map);
            }
            if ($path !== '') {
                $parts = preg_split('/\s*\/\s*/', $path) ?: [];
                $localized = [];
                foreach ($parts as $part) {
                    $part = trim((string)$part);
                    if ($part === '') {
                        continue;
                    }
                    $localized[] = self::translatePart($part, $map);
                }
                $node['path'] = implode(' / ', $localized);
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * @param array<string, string> $map
     */
    private static function translatePart(string $en, array $map): string
    {
        if (isset($map[$en]) && $map[$en] !== '') {
            return $map[$en];
        }

        return $en;
    }

    /**
     * @return array<string, string>
     */
    private static function zhMap(): array
    {
        if (self::$zhMap !== null) {
            return self::$zhMap;
        }
        $file = dirname(__DIR__) . '/etc/data/cj_category_zh.php';
        if (!is_file($file)) {
            self::$zhMap = [];

            return self::$zhMap;
        }
        $loaded = include $file;
        self::$zhMap = is_array($loaded) ? $loaded : [];

        return self::$zhMap;
    }
}
