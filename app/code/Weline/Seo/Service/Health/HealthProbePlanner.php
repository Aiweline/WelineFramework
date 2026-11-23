<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Model\UrlHealth;

/**
 * 选样与预算：优先“从未探测”，其次最久未探测，最后是连续失败。
 *
 * 用有界候选池，避免一次把几万条 URL 全读进内存。
 */
final class HealthProbePlanner
{
    public function __construct(
        private SitemapUrl $sitemapUrl,
        private UrlHealth $health
    ) {
    }

    /**
     * @return array{
     *   urls:list<array{
     *     url:string, url_hash:string, sitemap_url_id:int, module:string, scope:string,
     *     url_key:string, locale:string, last_checked_at:string, consecutive_failures:int
     *   }>,
     *   total_active:int,
     *   total_removed:int,
     *   truncated:bool
     * }
     */
    public function plan(int $websiteId, int $maxUrls): array
    {
        $maxUrls = max(1, $maxUrls);
        $healthMap = $this->loadHealthMap($websiteId);

        $query = $this->sitemapUrl->reset()
            ->where(SitemapUrl::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapUrl::schema_fields_STATUS, 1)
            ->select('url_id,url,module,scope,url_key,locale')
            ->order(SitemapUrl::schema_fields_ID, 'ASC');

        $pool = [];
        $limit = $maxUrls * 4;
        $totalActive = 0;
        $seen = [];

        foreach ($query->fetchIterator() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = trim((string)($row[SitemapUrl::schema_fields_URL] ?? ''));
            if ($url === '') {
                continue;
            }
            $totalActive++;
            $hash = UrlHealthKey::hash($url);
            $seen[$hash] = true;
            $health = $healthMap[$hash] ?? null;
            $lastCheckedAt = (string)($health['last_checked_at'] ?? '');
            $score = $lastCheckedAt !== '' ? (int)(strtotime($lastCheckedAt) ?: 0) : 0;

            $pool[] = [
                'score' => $score,
                'url' => $url,
                'url_hash' => $hash,
                'sitemap_url_id' => (int)($row[SitemapUrl::schema_fields_ID] ?? 0),
                'module' => (string)($row[SitemapUrl::schema_fields_MODULE] ?? ''),
                'scope' => (string)($row[SitemapUrl::schema_fields_SCOPE] ?? ''),
                'url_key' => (string)($row[SitemapUrl::schema_fields_URL_KEY] ?? ''),
                'locale' => (string)($row[SitemapUrl::schema_fields_LOCALE] ?? ''),
                'last_checked_at' => $lastCheckedAt,
                'consecutive_failures' => (int)($health['consecutive_failures'] ?? 0),
            ];

            if (count($pool) >= $limit) {
                $pool = $this->trimPool($pool, $maxUrls);
            }
        }

        // 已自动移出 sitemap 的 URL 仍要探测，否则永远不知道它已恢复、无法自动加回。
        // 单独成池并优先占用最多 20%（上限 50）的名额，避免被新 URL 永久挤掉。
        $removedQuery = $this->health->reset()
            ->where(UrlHealth::schema_fields_WEBSITE_ID, $websiteId)
            ->where(UrlHealth::schema_fields_STATE, UrlHealth::STATE_AUTO_REMOVED)
            ->select('url_hash,url,module,scope,url_key,locale,sitemap_url_id,last_checked_at,consecutive_failures');
        $removedPool = [];
        $totalRemoved = 0;
        $removedCap = max(1, min(50, (int)ceil($maxUrls * 0.2)));
        foreach ($removedQuery->fetchIterator() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hash = (string)($row[UrlHealth::schema_fields_URL_HASH] ?? '');
            $url = trim((string)($row[UrlHealth::schema_fields_URL] ?? ''));
            if ($hash === '' || $url === '' || isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $totalRemoved++;
            $lastCheckedAt = (string)($row[UrlHealth::schema_fields_LAST_CHECKED_AT] ?? '');
            $removedPool[] = [
                'score' => $lastCheckedAt !== '' ? (int)(strtotime($lastCheckedAt) ?: 0) : 0,
                'url' => $url,
                'url_hash' => $hash,
                'sitemap_url_id' => (int)($row[UrlHealth::schema_fields_SITEMAP_URL_ID] ?? 0),
                'module' => (string)($row[UrlHealth::schema_fields_MODULE] ?? ''),
                'scope' => (string)($row[UrlHealth::schema_fields_SCOPE] ?? ''),
                'url_key' => (string)($row[UrlHealth::schema_fields_URL_KEY] ?? ''),
                'locale' => (string)($row[UrlHealth::schema_fields_LOCALE] ?? ''),
                'last_checked_at' => $lastCheckedAt,
                'consecutive_failures' => (int)($row[UrlHealth::schema_fields_CONSECUTIVE_FAILURES] ?? 0),
            ];
            if (count($removedPool) >= $removedCap * 4) {
                $removedPool = $this->trimPool($removedPool, $removedCap);
            }
        }

        $removedSelected = $this->trimPool($removedPool, $removedCap);
        $activeSelected = $this->trimPool($pool, max(1, $maxUrls - count($removedSelected)));
        $pool = array_merge($removedSelected, $activeSelected);
        $urls = array_map(static function (array $entry): array {
            unset($entry['score']);

            return $entry;
        }, $pool);

        return [
            'urls' => array_values($urls),
            'total_active' => $totalActive,
            'total_removed' => $totalRemoved,
            'truncated' => ($totalActive + $totalRemoved) > count($urls),
        ];
    }

    /**
     * @param list<array{score:int}> $pool
     * @return list<array{score:int}>
     */
    private function trimPool(array $pool, int $maxUrls): array
    {
        usort($pool, static function (array $left, array $right): int {
            $scoreCompare = ((int)$left['score']) <=> ((int)$right['score']);
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return strcmp((string)$left['url'], (string)$right['url']);
        });

        return array_slice($pool, 0, max(1, $maxUrls));
    }

    /**
     * @return array<string, array{last_checked_at:string, consecutive_failures:int, state:string}>
     */
    private function loadHealthMap(int $websiteId): array
    {
        $map = [];
        $query = $this->health->reset()
            ->where(UrlHealth::schema_fields_WEBSITE_ID, $websiteId)
            ->select('url_hash,last_checked_at,consecutive_failures,state');

        foreach ($query->fetchIterator() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hash = (string)($row[UrlHealth::schema_fields_URL_HASH] ?? '');
            if ($hash === '') {
                continue;
            }
            $map[$hash] = [
                'last_checked_at' => (string)($row[UrlHealth::schema_fields_LAST_CHECKED_AT] ?? ''),
                'consecutive_failures' => (int)($row[UrlHealth::schema_fields_CONSECUTIVE_FAILURES] ?? 0),
                'state' => (string)($row[UrlHealth::schema_fields_STATE] ?? UrlHealth::STATE_OPEN),
            ];
        }

        return $map;
    }
}
