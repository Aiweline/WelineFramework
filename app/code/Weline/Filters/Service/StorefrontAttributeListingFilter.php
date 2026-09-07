<?php

declare(strict_types=1);

namespace Weline\Filters\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

/**
 * Applies storefront attribute facet query params (af_<code>=value) to offer rows.
 *
 * Prefer matching values already projected onto storefront offers (brand / specifications).
 * Classic EAV value-table facets may be empty when Product stores attrs on Website shard / snapshot.
 */
final class StorefrontAttributeListingFilter
{
    public const QUERY_PREFIX = 'af_';

    /**
     * @return array<string, string> code => selected value
     */
    public function normalizeFromRequest(array $query): array
    {
        $selected = [];
        foreach ($query as $key => $value) {
            $key = strtolower(trim((string)$key));
            if (!str_starts_with($key, self::QUERY_PREFIX)) {
                continue;
            }
            $code = substr($key, strlen(self::QUERY_PREFIX));
            $code = preg_replace('/[^a-z0-9_\\-]/', '', $code) ?? '';
            $raw = is_array($value) ? (string)reset($value) : (string)$value;
            $raw = trim($raw);
            if ($code === '' || $raw === '') {
                continue;
            }
            $selected[$code] = $raw;
        }

        return $selected;
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @param array<string, string> $selected code => value
     * @return list<array<string, mixed>>
     */
    public function apply(array $offers, array $selected): array
    {
        if ($offers === [] || $selected === []) {
            return $offers;
        }

        return array_values(array_filter(
            $offers,
            function (array $offer) use ($selected): bool {
                foreach ($selected as $code => $want) {
                    $wantNorm = $this->normalizeComparable($want);
                    if ($wantNorm === '') {
                        return false;
                    }
                    $matched = false;
                    foreach ($this->extractOfferAttributeValues($offer, (string)$code) as $value) {
                        if ($this->normalizeComparable($value) === $wantNorm) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * Count attribute values projected on offers for the given filterable codes.
     *
     * @param list<array<string, mixed>> $offers
     * @param array<string, string> $codeNames code => display name
     * @return array<string, array{name:string,counts:array<string,int>}>
     */
    public function countOfferAttributeValues(array $offers, array $codeNames): array
    {
        $result = [];
        foreach ($codeNames as $code => $name) {
            $code = strtolower(trim((string)$code));
            if ($code === '' || str_starts_with($code, 'source_')) {
                continue;
            }
            $counts = [];
            foreach ($offers as $offer) {
                if (!is_array($offer)) {
                    continue;
                }
                foreach ($this->extractOfferAttributeValues($offer, $code) as $value) {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
            if ($counts === []) {
                continue;
            }
            $result[$code] = [
                'name' => trim((string)$name) !== '' ? trim((string)$name) : $code,
                'counts' => $counts,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $offer
     * @return list<string>
     */
    public function extractOfferAttributeValues(array $offer, string $code): array
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return [];
        }

        $rawValues = [];
        if ($code === 'brand') {
            $brand = trim((string)($offer['brand'] ?? ''));
            if ($brand !== '') {
                $rawValues[] = $brand;
            }
        }

        $direct = $offer[$code] ?? null;
        if (is_string($direct) || is_numeric($direct)) {
            $rawValues[] = (string)$direct;
        } elseif (is_array($direct)) {
            foreach ($direct as $item) {
                if (is_string($item) || is_numeric($item)) {
                    $rawValues[] = (string)$item;
                }
            }
        }

        $specs = $offer['specifications'] ?? null;
        if (is_array($specs)) {
            foreach ($specs as $key => $spec) {
                if (is_array($spec)) {
                    $specCode = strtolower(trim((string)($spec['code'] ?? $spec['attribute_code'] ?? '')));
                    if ($specCode !== $code) {
                        continue;
                    }
                    $rawValues[] = (string)($spec['value'] ?? $spec['label'] ?? '');
                    continue;
                }
                if (is_string($key) && strtolower(trim($key)) === $code) {
                    if (is_string($spec) || is_numeric($spec)) {
                        $rawValues[] = (string)$spec;
                    }
                }
            }
        }

        $values = [];
        foreach ($rawValues as $raw) {
            foreach ($this->splitMultiValue((string)$raw) as $part) {
                $values[$part] = true;
            }
        }

        return array_keys($values);
    }

    /**
     * @return list<string>
     */
    public function splitMultiValue(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s*[、,;|／\/]\s*/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out !== [] ? $out : [$raw];
    }

    /**
     * Build a storefront listing href that keeps currency/language path prefixes.
     *
     * @param array<string, string> $selected
     * @param array<string, scalar|array|null> $extra
     */
    public function buildListingUrl(string $basePath, array $selected, array $extra = []): string
    {
        $params = [];
        foreach ($extra as $key => $value) {
            if ($value === null || is_array($value)) {
                continue;
            }
            $text = trim((string)$value);
            if ($text === '') {
                continue;
            }
            $params[(string)$key] = $text;
        }
        foreach ($selected as $code => $value) {
            $params[self::QUERY_PREFIX . $code] = $value;
        }

        $route = ltrim(trim($basePath), '/');
        // Drop any existing currency/lang segments so Url::getFrontendUrl can
        // re-attach the current request locale without doubling prefixes.
        $segments = $route === '' ? [] : explode('/', $route);
        while ($segments !== []) {
            $first = (string)$segments[0];
            if (\Weline\Framework\App\State::isAllowedCurrencyCode($first)
                || \Weline\Framework\App\State::isAllowedLanguageCode($first)
            ) {
                array_shift($segments);
                continue;
            }
            break;
        }
        $route = implode('/', $segments);
        if ($route === '') {
            $route = 'categories';
        }

        try {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            $built = (string)$url->getFrontendUrl($route, $params);
            $path = parse_url($built, PHP_URL_PATH);
            $query = parse_url($built, PHP_URL_QUERY);
            if (is_string($path) && $path !== '') {
                return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
            }
        } catch (\Throwable) {
            // Fall back to a bare path when Url is unavailable (CLI/unit without request).
        }

        $fallback = '/' . $route;
        if ($params === []) {
            return $fallback;
        }

        return $fallback . '?' . http_build_query($params);
    }

    private function normalizeComparable(string $value): string
    {
        return strtolower(trim($value));
    }
}
