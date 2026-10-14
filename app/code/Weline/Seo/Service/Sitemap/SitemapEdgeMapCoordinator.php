<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Sitemap;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\SeoWebsiteDirectory;
use Weline\Seo\Service\WebSitemapData;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;
use Weline\Server\Service\Edge\Nginx\SitemapEdgeNginxMapWriter;

/**
 * After canonical sitemap files land under pub/sitemaps/{code}/, refresh the
 * managed-Nginx edge map and purge CDN URL caches for public sitemap URLs.
 */
final class SitemapEdgeMapCoordinator
{
    public function __construct(
        private readonly SeoWebsiteDirectory $websiteDirectory,
        private readonly SitemapEdgeNginxMapWriter $mapWriter = new SitemapEdgeNginxMapWriter(),
    ) {
    }

    /**
     * @param array<string,mixed> $canonicalPublishResult AtomicSitemapPublisher success payload
     * @return array{
     *   map:array<string,mixed>,
     *   reload:array<string,mixed>,
     *   cdn:array<string,mixed>,
     *   purge_urls:list<string>
     * }
     */
    public function afterCanonicalPublished(int $websiteId, string $websiteCode, array $canonicalPublishResult): array
    {
        $spec = $this->buildSpecFromWebsites();
        $map = $this->mapWriter->publish($spec);
        $reload = $this->reloadManagedNginxIfPossible();
        $purgeUrls = $this->collectPurgeUrls($websiteId, $websiteCode, $canonicalPublishResult);
        $cdn = $this->purgeCdnUrls($websiteId, $purgeUrls);

        return [
            'map' => $map,
            'reload' => $reload,
            'cdn' => $cdn,
            'purge_urls' => $purgeUrls,
        ];
    }

    /**
     * Rebuild map for every known website (CLI / recovery).
     *
     * @return array{map:array<string,mixed>, reload:array<string,mixed>}
     */
    public function rebuildAll(): array
    {
        $map = $this->mapWriter->publish($this->buildSpecFromWebsites());

        return [
            'map' => $map,
            'reload' => $this->reloadManagedNginxIfPossible(),
        ];
    }

    /**
     * @return array{
     *   default_website_code:string,
     *   host_to_code:array<string,string>,
     *   index_locations:list<array{path:string,website_code:string}>
     * }
     */
    public function buildSpecFromWebsites(): array
    {
        $hostToCode = [];
        $indexLocations = [];
        $defaultCode = 'default';
        foreach ($this->websiteDirectory->listWebsites() as $website) {
            if (!\is_array($website)) {
                continue;
            }
            $code = \trim((string)($website['code'] ?? ''));
            if ($code === '' || \preg_match('/^[A-Za-z0-9_-]+$/D', $code) !== 1) {
                continue;
            }
            if ((int)($website['website_id'] ?? $website['id'] ?? -1) === 0
                || $code === 'default'
            ) {
                $defaultCode = $code;
            }
            $canonicalDir = BP . '/' . WebSitemapData::SITEMAP_DIR . '/' . $code . '/canonical';
            if (!\is_dir($canonicalDir)) {
                continue;
            }
            $addedIndexForWebsite = false;
            foreach ($this->websiteDirectory->listPublicOrigins($website) as $origin) {
                if (!\is_array($origin)) {
                    continue;
                }
                $baseUrl = \rtrim((string)($origin['base_url'] ?? ''), '/');
                $host = \strtolower((string)(\parse_url($baseUrl, \PHP_URL_HOST) ?: ''));
                // sub_path 挂载只认 /{sub}/sitemap.xml，禁止把裸 Host 抢成该站
                // （否则同 Host 上默认站的 /sitemap.xml 会被后写站覆盖）。
                $subPath = \trim((string)($origin['sub_path'] ?? ''), '/');
                if ($subPath === '' && $host !== '') {
                    $isDefaultSite = $code === 'default'
                        || (int)($website['website_id'] ?? $website['id'] ?? -1) === 0;
                    if (!isset($hostToCode[$host]) || $isDefaultSite) {
                        $hostToCode[$host] = $code;
                    }
                }
                $path = $subPath === '' ? '/sitemap.xml' : '/' . $subPath . '/sitemap.xml';
                $indexLocations[] = [
                    'path' => $path,
                    'website_code' => $code,
                ];
                $addedIndexForWebsite = true;
            }
            if (!$addedIndexForWebsite) {
                $indexLocations[] = [
                    'path' => '/sitemap.xml',
                    'website_code' => $code,
                ];
            }
        }
        if ($hostToCode === []) {
            $hostToCode['localhost'] = $defaultCode;
        }
        if ($indexLocations === []) {
            $indexLocations[] = [
                'path' => '/sitemap.xml',
                'website_code' => $defaultCode,
            ];
        }

        return [
            'default_website_code' => $defaultCode,
            'host_to_code' => $hostToCode,
            'index_locations' => $indexLocations,
        ];
    }

    /**
     * @param array<string,mixed> $canonicalPublishResult
     * @return list<string>
     */
    private function collectPurgeUrls(int $websiteId, string $websiteCode, array $canonicalPublishResult): array
    {
        $urls = [];
        $website = $this->websiteDirectory->getWebsiteById($websiteId) ?? [];
        foreach ($this->websiteDirectory->listPublicOrigins($website) as $origin) {
            if (!\is_array($origin)) {
                continue;
            }
            $sitemapUrl = \trim((string)($origin['sitemap_url'] ?? ''));
            if ($sitemapUrl !== '' && \preg_match('#^https?://#i', $sitemapUrl) === 1) {
                $urls[$sitemapUrl] = $sitemapUrl;
            }
            $base = \rtrim((string)($origin['base_url'] ?? ''), '/');
            if ($base === '') {
                continue;
            }
            $urls[$base . '/sitemap.xml'] = $base . '/sitemap.xml';
            foreach ((array)($canonicalPublishResult['shards'] ?? []) as $shard) {
                if (!\is_array($shard)) {
                    continue;
                }
                $filename = \trim((string)($shard['filename'] ?? ''));
                if ($filename === '' || !\str_ends_with($filename, '.xml')) {
                    continue;
                }
                $shardUrl = $base . '/sitemaps/' . \rawurlencode($websiteCode)
                    . '/canonical/' . $filename;
                $urls[$shardUrl] = $shardUrl;
            }
        }
        $indexUrl = \trim((string)(($canonicalPublishResult['index']['url'] ?? '') ?: ''));
        if ($indexUrl !== '' && \preg_match('#^https?://#i', $indexUrl) === 1) {
            $urls[$indexUrl] = $indexUrl;
        }
        foreach ((array)($canonicalPublishResult['shards'] ?? []) as $shard) {
            if (!\is_array($shard)) {
                continue;
            }
            $shardAbs = \trim((string)($shard['url'] ?? ''));
            if ($shardAbs !== '' && \preg_match('#^https?://#i', $shardAbs) === 1) {
                $urls[$shardAbs] = $shardAbs;
            }
        }

        return \array_values($urls);
    }

    /**
     * @param list<string> $urls
     * @return array<string,mixed>
     */
    private function purgeCdnUrls(int $websiteId, array $urls): array
    {
        if ($urls === []) {
            return [
                'success' => true,
                'triggered' => false,
                'skipped' => true,
                'message' => 'no_urls',
            ];
        }
        try {
            if (!\class_exists(\Weline\Cdn\Service\CachePurger::class)) {
                return [
                    'success' => true,
                    'triggered' => false,
                    'skipped' => true,
                    'message' => 'cdn_module_absent',
                ];
            }
            /** @var \Weline\Cdn\Service\CachePurger $purger */
            $purger = ObjectManager::getInstance(\Weline\Cdn\Service\CachePurger::class);
            $domainModel = ObjectManager::getInstance(\Weline\Cdn\Model\Domain::class);
            $domains = (clone $domainModel)->reset()
                ->where(\Weline\Cdn\Model\Domain::schema_fields_SITE_ID, $websiteId)
                ->where(\Weline\Cdn\Model\Domain::schema_fields_ENABLED, 1)
                ->select()
                ->fetch()
                ->getItems();
            if ($domains === []) {
                return [
                    'success' => true,
                    'triggered' => true,
                    'skipped' => true,
                    'message' => 'no_cdn_domain',
                    'urls' => $urls,
                ];
            }
            $results = [];
            foreach ($domains as $domain) {
                if (!$domain instanceof \Weline\Cdn\Model\Domain) {
                    continue;
                }
                $domainId = (int)$domain->getData(\Weline\Cdn\Model\Domain::schema_fields_DOMAIN_ID);
                if ($domainId < 1) {
                    continue;
                }
                $results[] = $purger->purge($domainId, 'urls', ['urls' => $urls]);
            }

            $success = $results !== [];
            foreach ($results as $result) {
                $success = $success && (($result['success'] ?? false) === true);
            }

            return [
                'success' => $success,
                'triggered' => true,
                'urls' => $urls,
                'results' => $results,
            ];
        } catch (\Throwable $e) {
            w_log_warning('Sitemap CDN purge failed: ' . $e->getMessage(), [
                'website_id' => $websiteId,
            ], 'seo');

            return [
                'success' => false,
                'triggered' => true,
                'message' => $e->getMessage(),
                'urls' => $urls,
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function reloadManagedNginxIfPossible(): array
    {
        try {
            if (!\class_exists(ManagedNginxService::class)) {
                return ['ok' => false, 'skipped' => true, 'message' => 'managed_nginx_absent'];
            }
            /** @var ManagedNginxService $service */
            $service = ObjectManager::getInstance(ManagedNginxService::class);
            $result = $service->reload(null, ['light_verification' => true]);

            return \is_array($result) ? $result : ['ok' => false, 'message' => 'reload_invalid_result'];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'skipped' => true,
                'message' => $e->getMessage(),
            ];
        }
    }
}
