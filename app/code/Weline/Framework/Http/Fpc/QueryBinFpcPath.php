<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * Path-family recognition for BinQuery FPC cold leaves under query-bin.
 *
 * Plain: …/framework/query-bin
 * Leaf:  …/framework/query-bin/~fpc/{storefront_rel}/~q/{40-hex}
 */
final class QueryBinFpcPath
{
    public const FPC_SEGMENT = '~fpc';

    public static function normalizePath(string $urlOrPath): string
    {
        $raw = \trim($urlOrPath);
        if ($raw === '') {
            return '';
        }
        if (\preg_match('#^https?://#i', $raw) === 1) {
            $path = (string)(\parse_url($raw, \PHP_URL_PATH) ?: '');
        } else {
            $path = $raw;
        }
        $path = \strtolower(\trim($path));
        $q = \strpos($path, '?');
        if ($q !== false) {
            $path = \substr($path, 0, $q);
        }

        return \trim($path, '/');
    }

    public static function isPlainQueryBinPath(string $urlOrPath): bool
    {
        $normalized = self::normalizePath($urlOrPath);
        if ($normalized === '') {
            return false;
        }
        if (\str_contains($normalized, '/' . self::FPC_SEGMENT . '/')) {
            return false;
        }

        return $normalized === 'framework/query-bin'
            || \str_ends_with($normalized, '/framework/query-bin');
    }

    public static function isFpcLeafPath(string $urlOrPath): bool
    {
        return self::parseFpcLeaf($urlOrPath) !== null;
    }

    /**
     * @return array{storefront_rel:string,sha:string}|null
     */
    public static function parseFpcLeaf(string $urlOrPath): ?array
    {
        $normalized = self::normalizePath($urlOrPath);
        if ($normalized === '') {
            return null;
        }
        $marker = '/framework/query-bin/' . self::FPC_SEGMENT . '/';
        $pos = \strpos($normalized, $marker);
        if ($pos === false) {
            // bare framework/query-bin/~fpc/…
            $prefix = 'framework/query-bin/' . self::FPC_SEGMENT . '/';
            if (!\str_starts_with($normalized, $prefix)) {
                return null;
            }
            $rest = \substr($normalized, \strlen($prefix));
        } else {
            $rest = \substr($normalized, $pos + \strlen($marker));
        }
        if ($rest === '' || \str_contains($rest, '..')) {
            return null;
        }
        $qSeg = '/' . FpcHtmlDiskStore::QUERY_VARIANT_SEGMENT . '/';
        $qPos = \strrpos($rest, $qSeg);
        if ($qPos === false) {
            return null;
        }
        $rel = \trim(\substr($rest, 0, $qPos), '/');
        $sha = \substr($rest, $qPos + \strlen($qSeg));
        if ($rel === '' || \preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            return null;
        }
        // storefront_rel: no empty segments
        foreach (\explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return null;
            }
        }

        return ['storefront_rel' => $rel, 'sha' => $sha];
    }
}
