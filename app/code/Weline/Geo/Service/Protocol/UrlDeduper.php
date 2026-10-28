<?php

declare(strict_types=1);

namespace Weline\Geo\Service\Protocol;

use Weline\Geo\Model\FeedItem;

/**
 * Canonical-URL dedupe for GEO protocol documents (E9: llms.txt listed the same
 * product hundreds of times because feeds stored http/https + www variants).
 *
 * Two URLs are the same entry when host (www-insensitive), path and query match;
 * scheme is deliberately ignored because protocol documents are public HTTPS copy.
 */
final class UrlDeduper
{
    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function dedupe(array $items, string $baseUrl = ''): array
    {
        $kept = [];
        $seen = [];
        foreach ($items as $item) {
            $url = trim((string)($item[FeedItem::schema_fields_URL] ?? $item['url'] ?? ''));
            $key = $this->key($url, $baseUrl);
            if ($key === '') {
                $kept[] = $item;
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $kept[] = $item;
        }

        return $kept;
    }

    public function key(string $url, string $baseUrl = ''): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $baseUrl = rtrim(trim($baseUrl), '/');
            if ($baseUrl === '') {
                return '';
            }
            $url = $baseUrl . '/' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return strtolower($url);
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            return strtolower($url);
        }
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : 0;
        $portSuffix = ($port > 0 && !in_array($port, [80, 443], true)) ? ':' . $port : '';
        $path = (string)($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        $query = (string)($parts['query'] ?? '');

        return $host . $portSuffix . $path . ($query !== '' ? '?' . $query : '');
    }
}
