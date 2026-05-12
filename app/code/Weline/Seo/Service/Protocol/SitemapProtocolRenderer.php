<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Protocol;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Service\WebSitemapData;

class SitemapProtocolRenderer
{
    public function __construct(
        private readonly WebsiteProtocolResolver $websiteResolver,
        private readonly SitemapUrl $sitemapUrl
    ) {
    }

    public function render(): string
    {
        $website = $this->websiteResolver->currentWebsite();
        $sitemaps = $this->collectGeneratedSitemaps($website);
        if ($sitemaps !== []) {
            return $this->renderSitemapIndex($sitemaps);
        }

        return $this->renderUrlSet($this->collectDatabaseUrls($website));
    }

    /**
     * @param array<string, mixed> $website
     * @return array<int, array{loc:string,lastmod:string}>
     */
    private function collectGeneratedSitemaps(array $website): array
    {
        $websiteCode = (string)($website['code'] ?? 'default');
        $baseUrl = rtrim((string)($website['url'] ?? ''), '/');
        $siteDir = BP . '/' . WebSitemapData::SITEMAP_DIR . '/' . $websiteCode;
        if (!is_dir($siteDir) || $baseUrl === '') {
            return [];
        }

        $result = [];
        foreach (glob($siteDir . '/*/sitemap.xml') ?: [] as $file) {
            $platform = basename(dirname($file));
            $result[] = [
                'loc' => $baseUrl . '/sitemaps/' . rawurlencode($websiteCode) . '/' . rawurlencode($platform) . '/sitemap.xml',
                'lastmod' => date('c', filemtime($file) ?: time()),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $website
     * @return array<int, array{loc:string,lastmod:string}>
     */
    private function collectDatabaseUrls(array $website): array
    {
        $websiteId = (int)($website['website_id'] ?? 0);
        if ($websiteId <= 0) {
            return [];
        }

        try {
            $rows = $this->sitemapUrl->reset()->getActiveUrls($websiteId);
        } catch (\Throwable) {
            $rows = [];
        }

        $baseUrl = rtrim((string)($website['url'] ?? ''), '/');
        $result = [];
        foreach ($rows as $row) {
            $loc = (string)($row[SitemapUrl::schema_fields_URL] ?? $row['loc'] ?? $row['url'] ?? '');
            if ($loc === '') {
                continue;
            }
            if (!preg_match('/^https?:\/\//i', $loc) && $baseUrl !== '') {
                $loc = $baseUrl . '/' . ltrim($loc, '/');
            }
            $lastmod = (string)($row[SitemapUrl::schema_fields_LASTMOD] ?? $row['lastmod'] ?? '');
            $result[] = [
                'loc' => $loc,
                'lastmod' => $lastmod !== '' ? date('c', strtotime($lastmod) ?: time()) : date('c'),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array{loc:string,lastmod:string}> $items
     */
    private function renderSitemapIndex(array $items): string
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($items as $item) {
            $xml[] = '  <sitemap>';
            $xml[] = '    <loc>' . $this->escape($item['loc']) . '</loc>';
            $xml[] = '    <lastmod>' . $this->escape($item['lastmod']) . '</lastmod>';
            $xml[] = '  </sitemap>';
        }
        $xml[] = '</sitemapindex>';
        return implode("\n", $xml);
    }

    /**
     * @param array<int, array{loc:string,lastmod:string}> $items
     */
    private function renderUrlSet(array $items): string
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($items as $item) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . $this->escape($item['loc']) . '</loc>';
            $xml[] = '    <lastmod>' . $this->escape($item['lastmod']) . '</lastmod>';
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';
        return implode("\n", $xml);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
