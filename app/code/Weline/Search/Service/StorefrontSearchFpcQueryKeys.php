<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Http\Request;

/**
 * Explicit storefront /search Extra query_keys (+ SSR bind helpers).
 *
 * Controllers duplicate {@see csv()} in `@Extra query_keys=`; contracts assert match.
 * Keep ≤ FpcExtraType::MAX_QUERY_KEYS (32). Value cardinality of `q` is unbounded —
 * rely on Search Extra TTL (hours), never year-long like catalog shells.
 */
final class StorefrontSearchFpcQueryKeys
{
    /** @var list<string> */
    private const KEYS = [
        'blog_category_id',
        'category_id',
        'page',
        'page_size',
        'q',
        'type',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::KEYS;
    }

    public static function csv(): string
    {
        return \implode(',', self::KEYS);
    }

    /**
     * True when request carries a non-empty allowlisted search param that should
     * fork Shared identity (empty /search stays path-only shell).
     */
    public static function requestHasAllowlisted(Request $request): bool
    {
        return self::fromRequest($request) !== [];
    }

    /**
     * Params for SearchHub / template bind (allowlisted only).
     *
     * @return array<string, string>
     */
    public static function fromRequest(Request $request): array
    {
        $allow = \array_fill_keys(self::KEYS, true);
        $out = [];
        $params = [];
        if (\method_exists($request, 'getParams')) {
            $raw = $request->getParams();
            if (\is_array($raw)) {
                $params = $raw;
            }
        }
        if ($params === [] && \method_exists($request, 'getGet')) {
            $get = $request->getGet();
            if (\is_array($get)) {
                $params = $get;
            }
        }
        foreach ($params as $key => $value) {
            $key = \strtolower(\trim((string)$key));
            if ($key === '' || !isset($allow[$key]) || \array_key_exists($key, $out)) {
                continue;
            }
            if (\is_array($value)) {
                $value = \reset($value);
            }
            if (!\is_scalar($value) && $value !== null) {
                continue;
            }
            $scalar = \trim((string)$value);
            if ($scalar === '') {
                continue;
            }
            $out[$key] = $scalar;
        }

        return $out;
    }

    /**
     * Project search BinQuery params → /search?… canonical URI for FPC disk.
     *
     * @param array<string, mixed> $params
     */
    public static function canonicalStorefrontUriFromBinQueryParams(array $params, string $hostWithScheme): ?string
    {
        $hostWithScheme = \rtrim(\trim($hostWithScheme), '/');
        if ($hostWithScheme === '' || !\preg_match('#^https?://#i', $hostWithScheme)) {
            return null;
        }
        $bag = [];
        foreach (self::KEYS as $key) {
            if (!\array_key_exists($key, $params)) {
                continue;
            }
            $value = $params[$key];
            if (\is_array($value)) {
                $value = \reset($value);
            }
            if (!\is_scalar($value) && $value !== null) {
                continue;
            }
            $scalar = \trim((string)$value);
            if ($scalar === '') {
                continue;
            }
            $bag[$key] = $scalar;
        }
        $allow = \array_fill_keys(self::KEYS, true);
        $canonicalQuery = \Weline\Framework\Http\Fpc\FpcAllowlistedQueryIdentity::filterQueryString(
            \http_build_query($bag),
            $allow,
            false,
        );
        if ($canonicalQuery === '') {
            return null;
        }

        return $hostWithScheme . '/search?' . $canonicalQuery;
    }
}
