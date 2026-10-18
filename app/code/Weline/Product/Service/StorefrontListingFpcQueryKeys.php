<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Filters\Service\StorefrontFacetPresentationPolicy;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * Explicit PLP FPC Extra query_keys (+ SSR bind helpers).
 *
 * Must stay ≤ FpcExtraType::MAX_QUERY_KEYS (32). Controllers duplicate the CSV
 * in `@Extra query_keys=` PHPDoc; contracts assert they match {@see csv()}.
 */
final class StorefrontListingFpcQueryKeys
{
    /** @var list<string> */
    private const BASE_KEYS = ['page', 'price', 'sort'];

    /**
     * Sorted unique allowlist (price/sort/page + af_{shopper facet codes}).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $keys = [];
        foreach (self::BASE_KEYS as $key) {
            $keys[$key] = true;
        }
        foreach (self::shopperFacetCodes() as $code) {
            $keys['af_' . $code] = true;
        }
        $list = \array_keys($keys);
        \sort($list, \SORT_STRING);

        return $list;
    }

    public static function csv(): string
    {
        return \implode(',', self::all());
    }

    /**
     * True when the request carries at least one allowlisted listing param.
     */
    public static function requestHasAllowlisted(Request $request): bool
    {
        return self::fromRequest($request) !== [];
    }

    /**
     * Query bag for {@see StorefrontListingShell::projectFiltered} / normalizeQuery.
     *
     * @return array<string, string>
     */
    public static function fromRequest(Request $request): array
    {
        $allow = \array_fill_keys(self::all(), true);
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
     * Project filteredListingPage BinQuery params → storefront canonical URI for FPC disk.
     * Returns null when no allowlisted query remains (path-only — skip bq leaf).
     *
     * @param array<string, mixed> $params
     */
    public static function canonicalStorefrontUriFromBinQueryParams(array $params, string $hostWithScheme): ?string
    {
        $hostWithScheme = \rtrim(\trim($hostWithScheme), '/');
        if ($hostWithScheme === '' || !\preg_match('#^https?://#i', $hostWithScheme)) {
            return null;
        }
        $surface = \strtolower(\trim((string)($params['surface'] ?? 'products')));
        $listingPath = \trim(\str_replace('\\', '/', (string)($params['listing_path'] ?? '')), '/');
        if ($listingPath === '') {
            $listingPath = $surface === 'category' ? 'category' : 'products';
        }
        $path = '/' . $listingPath;

        $bag = [];
        foreach (['price', 'sort', 'page'] as $key) {
            $v = $params[$key] ?? null;
            if (\is_array($v)) {
                $v = \reset($v);
            }
            if (!\is_scalar($v) && $v !== null) {
                continue;
            }
            $scalar = \trim((string)$v);
            if ($scalar === '' || ($key === 'page' && $scalar === '1')) {
                continue;
            }
            $bag[$key] = $scalar;
        }
        $filters = \is_array($params['filters'] ?? null) ? $params['filters'] : [];
        foreach ($filters as $code => $value) {
            $code = \strtolower(\trim((string)$code));
            $code = \preg_replace('/[^a-z0-9_\-]/', '', $code) ?? '';
            if ($code === '') {
                continue;
            }
            if (!\str_starts_with($code, 'af_')) {
                $code = 'af_' . $code;
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
            $bag[$code] = $scalar;
        }

        $allow = \array_fill_keys(self::all(), true);
        $canonicalQuery = \Weline\Framework\Http\Fpc\FpcAllowlistedQueryIdentity::filterQueryString(
            \http_build_query($bag),
            $allow,
            false,
        );
        if ($canonicalQuery === '') {
            return null;
        }

        return $hostWithScheme . $path . '?' . $canonicalQuery;
    }

    /**
     * @return list<string>
     */
    private static function shopperFacetCodes(): array
    {
        if (!\class_exists(StorefrontFacetPresentationPolicy::class)) {
            return [];
        }
        try {
            /** @var StorefrontFacetPresentationPolicy $policy */
            $policy = ObjectManager::getInstance(StorefrontFacetPresentationPolicy::class);

            return $policy->allCandidateCodes();
        } catch (\Throwable) {
            return [];
        }
    }
}
