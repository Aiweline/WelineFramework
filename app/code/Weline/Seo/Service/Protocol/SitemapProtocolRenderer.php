<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Protocol;

use DOMDocument;
use DOMXPath;
use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Service\SeoWebsiteDirectory;
use Weline\Seo\Service\Sitemap\AtomicSitemapPublisher;
use Weline\Seo\Service\Sitemap\SitemapXmlExtensionRenderer;
use Weline\Seo\Service\WebSitemapData;

final class SitemapProtocolRenderer
{
    public function __construct(
        private readonly WebsiteProtocolResolver $websiteResolver,
        private readonly SitemapUrl $sitemapUrl,
        private readonly SitemapXmlExtensionRenderer $extensionRenderer,
        private readonly SeoWebsiteDirectory $websiteDirectory,
        private readonly \Weline\Seo\Service\StoreModeSeoHardGate $storeModeGate = new \Weline\Seo\Service\StoreModeSeoHardGate(),
    ) {
    }

    /** @return array{body:string,status:int} */
    public function render(): array
    {
        if ($this->storeModeGate->isHardNoIndexMode()) {
            return $this->storeModeGate->forceEmptySitemap();
        }

        try {
            $website = $this->websiteResolver->currentWebsite();
            $generated = $this->readCanonicalSitemap($website);
            if ($generated !== null) {
                return ['body' => $generated, 'status' => 200];
            }
            return ['body' => $this->renderDatabaseFallback($website), 'status' => 200];
        } catch (\Throwable $exception) {
            w_log_warning('Sitemap protocol unavailable: ' . $exception->getMessage(), [], 'seo');
            return [
                'body' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                    . '<error>' . $this->escape((string)__('Sitemap 暂不可用，请稍后重试')) . '</error>',
                'status' => 503,
            ];
        }
    }

    /**
     * Serve a generated sitemap index/shard under /sitemaps/{code}/{target}/{file}.xml
     * with loopback origins rewritten to the live public base.
     *
     * @return array{body:string,status:int}
     */
    public function renderFile(string $websiteCode, string $target, string $filename): array
    {
        if ($this->storeModeGate->isHardNoIndexMode()) {
            return $this->storeModeGate->forceEmptySitemap();
        }

        try {
            $websiteCode = trim($websiteCode);
            $target = trim($target);
            $filename = trim($filename);
            if (
                $websiteCode === ''
                || $target === ''
                || $filename === ''
                || preg_match('/^[A-Za-z0-9_-]+$/D', $websiteCode) !== 1
                || preg_match('/^[A-Za-z0-9_-]+$/D', $target) !== 1
                || preg_match('/^[A-Za-z0-9._-]+\.xml$/D', $filename) !== 1
                || str_contains($filename, '..')
            ) {
                throw new \RuntimeException((string)__('Sitemap 文件路径无效'));
            }

            $path = BP . '/' . WebSitemapData::SITEMAP_DIR
                . '/' . $websiteCode . '/' . $target . '/' . $filename;
            $realBase = realpath(BP . '/' . WebSitemapData::SITEMAP_DIR);
            $realPath = realpath($path);
            if (
                $realBase === false
                || $realPath === false
                || !is_file($realPath)
                || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)
            ) {
                return [
                    'body' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                        . '<error>' . $this->escape((string)__('Sitemap 文件不存在')) . '</error>',
                    'status' => 404,
                ];
            }

            $xml = file_get_contents($realPath);
            if (!is_string($xml) || $xml === '' || strlen($xml) > AtomicSitemapPublisher::STANDARD_MAX_BYTES) {
                throw new \RuntimeException((string)__('Sitemap 文件不可读或超过协议限制'));
            }

            $website = $this->websiteResolver->currentWebsite();
            $baseUrl = rtrim($this->websiteDirectory->effectivePublicBaseUrl($website), '/');
            $xml = $this->websiteDirectory->rewriteLoopbackOriginsInXml($xml, $baseUrl);
            if ($baseUrl !== '') {
                $this->validateXmlAndOrigins($xml, $baseUrl);
            }

            return ['body' => $xml, 'status' => 200];
        } catch (\Throwable $exception) {
            w_log_warning('Sitemap file protocol unavailable: ' . $exception->getMessage(), [], 'seo');
            return [
                'body' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                    . '<error>' . $this->escape((string)__('Sitemap 暂不可用，请稍后重试')) . '</error>',
                'status' => 503,
            ];
        }
    }

    /** @param array<string,mixed> $website */
    private function readCanonicalSitemap(array $website): ?string
    {
        $websiteCode = trim((string)($website['code'] ?? 'default'));
        if ($websiteCode === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $websiteCode) !== 1) {
            throw new \RuntimeException((string)__('站点代码无法安全映射 Sitemap 目录'));
        }
        $path = BP . '/' . WebSitemapData::SITEMAP_DIR . '/' . $websiteCode . '/canonical/sitemap.xml';
        if (!is_file($path)) {
            return null;
        }
        $xml = file_get_contents($path);
        if (!is_string($xml) || $xml === '' || strlen($xml) > AtomicSitemapPublisher::STANDARD_MAX_BYTES) {
            throw new \RuntimeException((string)__('Canonical Sitemap 不可读或超过协议限制'));
        }
        $baseUrl = rtrim($this->websiteDirectory->effectivePublicBaseUrl($website), '/');
        $xml = $this->websiteDirectory->rewriteLoopbackOriginsInXml($xml, $baseUrl);
        $this->validateXmlAndOrigins($xml, $baseUrl);
        return $xml;
    }

    /** @param array<string,mixed> $website */
    private function renderDatabaseFallback(array $website): string
    {
        if (!array_key_exists('website_id', $website) && !array_key_exists('id', $website)) {
            throw new \RuntimeException((string)__('当前请求缺少站点上下文'));
        }
        $websiteId = (int)($website['website_id'] ?? $website['id']);
        if ($websiteId < 0) {
            throw new \RuntimeException((string)__('当前请求站点 ID 非法'));
        }
        $baseUrl = rtrim($this->websiteDirectory->effectivePublicBaseUrl($website), '/');
        $rows = $this->sitemapUrl->reset()->getActiveUrls($websiteId);
        if (count($rows) > AtomicSitemapPublisher::STANDARD_MAX_URLS) {
            throw new \RuntimeException((string)__('数据库 fallback URL 数超过单文件限制'));
        }
        usort($rows, static fn (array $left, array $right): int => [
            (string)($left[SitemapUrl::schema_fields_LOCALE] ?? ''),
            (string)($left[SitemapUrl::schema_fields_URL] ?? ''),
            (string)($left[SitemapUrl::schema_fields_URL_KEY] ?? ''),
        ] <=> [
            (string)($right[SitemapUrl::schema_fields_LOCALE] ?? ''),
            (string)($right[SitemapUrl::schema_fields_URL] ?? ''),
            (string)($right[SitemapUrl::schema_fields_URL_KEY] ?? ''),
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= $this->extensionRenderer->urlsetOpenTag($rows) . "\n";
        $seen = [];
        foreach ($rows as $row) {
            $loc = trim((string)($row[SitemapUrl::schema_fields_URL] ?? ''));
            if (!preg_match('#^https?://#i', $loc)) {
                $loc = $baseUrl . '/' . ltrim($loc, '/');
            }
            $loc = $this->websiteDirectory->rewriteLoopbackPublicUrl($loc, $baseUrl);
            $this->assertSameOrigin($loc, $baseUrl);
            if (strlen($loc) >= 2048 || isset($seen[$loc])) {
                throw new \RuntimeException((string)__('数据库 fallback 包含重复或超长 URL'));
            }
            $seen[$loc] = true;
            $row[SitemapUrl::schema_fields_URL] = $loc;
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $this->escape($loc) . "</loc>\n";
            $lastmod = trim((string)($row[SitemapUrl::schema_fields_LASTMOD] ?? ''));
            if ($lastmod !== '') {
                $timestamp = strtotime($lastmod);
                if ($timestamp === false) {
                    throw new \RuntimeException((string)__('数据库 fallback lastmod 无效'));
                }
                $xml .= '    <lastmod>' . gmdate('Y-m-d', $timestamp) . "</lastmod>\n";
            }
            $xml .= '    <changefreq>' . $this->escape((string)($row[SitemapUrl::schema_fields_CHANGEFREQ] ?? 'weekly')) . "</changefreq>\n";
            $xml .= '    <priority>' . $this->escape((string)($row[SitemapUrl::schema_fields_PRIORITY] ?? '0.5')) . "</priority>\n";
            $xml .= $this->extensionRenderer->renderUrlExtensions($row);
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';
        if (strlen($xml) > AtomicSitemapPublisher::STANDARD_MAX_BYTES) {
            throw new \RuntimeException((string)__('数据库 fallback 超过单文件字节限制'));
        }
        $this->validateXmlAndOrigins($xml, $baseUrl);
        return $xml;
    }

    private function validateXmlAndOrigins(string $xml, string $baseUrl): void
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || !in_array($document->documentElement?->localName, ['urlset', 'sitemapindex'], true)) {
            throw new \RuntimeException((string)__('Sitemap XML 根节点无效'));
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $nodes = $xpath->query('//s:loc');
        if ($nodes === false || $nodes->length > AtomicSitemapPublisher::STANDARD_MAX_URLS) {
            throw new \RuntimeException((string)__('Sitemap XML 条目数无效'));
        }
        foreach ($nodes as $node) {
            $this->assertSameOrigin(trim($node->textContent), $baseUrl);
        }
    }

    private function assertSameOrigin(string $url, string $baseUrl): void
    {
        $urlParts = parse_url($url);
        $baseParts = parse_url($baseUrl);
        if (!is_array($urlParts) || !is_array($baseParts) || !isset($urlParts['scheme'], $urlParts['host'], $baseParts['scheme'], $baseParts['host'])) {
            throw new \RuntimeException((string)__('Sitemap loc 不是有效绝对 URL'));
        }
        $urlScheme = strtolower((string)$urlParts['scheme']);
        $baseScheme = strtolower((string)$baseParts['scheme']);
        $urlPort = (int)($urlParts['port'] ?? ($urlScheme === 'https' ? 443 : 80));
        $basePort = (int)($baseParts['port'] ?? ($baseScheme === 'https' ? 443 : 80));
        if ($urlScheme !== $baseScheme || strtolower((string)$urlParts['host']) !== strtolower((string)$baseParts['host']) || $urlPort !== $basePort) {
            throw new \RuntimeException((string)__('Sitemap loc 与当前站点不同源'));
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
