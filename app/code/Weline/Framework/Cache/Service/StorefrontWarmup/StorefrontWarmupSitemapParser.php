<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

/**
 * Parse sitemap index / urlset XML without owning-module soft-pull.
 */
final class StorefrontWarmupSitemapParser
{
    /**
     * @return list<string> absolute or path URLs listed under sitemapindex <loc>
     */
    public function extractSitemapDocUrls(string $xml): array
    {
        return $this->extractLocs($xml);
    }

    /**
     * @return list<string>
     */
    public function extractPageLocs(string $xml): array
    {
        return $this->extractLocs($xml);
    }

    /**
     * Convert absolute loc to storefront path; drop non-http(s) and pure XML docs
     * when $htmlOnly is true (phase D).
     */
    public function locToPath(string $loc, bool $htmlOnly = false): ?string
    {
        $loc = \trim($loc);
        if ($loc === '') {
            return null;
        }
        if (\str_contains($loc, '://')) {
            $parts = \parse_url($loc);
            if (!\is_array($parts)) {
                return null;
            }
            $scheme = \strtolower((string)($parts['scheme'] ?? ''));
            if ($scheme !== 'http' && $scheme !== 'https') {
                return null;
            }
            $path = (string)($parts['path'] ?? '/');
            $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
            $loc = $path . $query;
        }
        if ($loc === '' || $loc[0] !== '/') {
            $loc = '/' . \ltrim($loc, '/');
        }
        if ($htmlOnly) {
            $pathOnly = (string)(\parse_url($loc, \PHP_URL_PATH) ?: $loc);
            if (\str_ends_with(\strtolower($pathOnly), '.xml')) {
                return null;
            }
        }

        return $loc;
    }

    /**
     * @return list<string>
     */
    private function extractLocs(string $xml): array
    {
        $xml = \trim($xml);
        if ($xml === '') {
            return [];
        }
        $locs = [];
        if (\preg_match_all('#<\s*loc\s*>\s*([^<]+?)\s*<\s*/\s*loc\s*>#i', $xml, $matches) === false) {
            return [];
        }
        foreach ($matches[1] ?? [] as $raw) {
            $value = \html_entity_decode(\trim((string)$raw), \ENT_QUOTES | \ENT_XML1);
            if ($value === '') {
                continue;
            }
            $locs[$value] = $value;
        }

        return \array_values($locs);
    }
}
