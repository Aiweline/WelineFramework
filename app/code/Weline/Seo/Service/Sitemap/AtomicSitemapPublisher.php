<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Sitemap;

use DOMDocument;
use DOMXPath;
use Weline\Seo\Model\SitemapUrl;

final class AtomicSitemapPublisher
{
    public const STANDARD_MAX_URLS = 50000;
    public const STANDARD_MAX_BYTES = 52428800;
    private const GENERATED_FILE_PATTERN = '/^sitemap_[a-z0-9-]+_[a-z0-9-]+_\d+_[a-f0-9]{12}\.xml$/D';

    public function __construct(
        private readonly SitemapOperationLock $operationLock,
        private readonly SitemapXmlExtensionRenderer $extensionRenderer,
    ) {
    }

    /**
     * @param list<array{module:string,scope:string,locale:string,urls:list<array<string,mixed>>}> $buckets
     * @return array<string,mixed>
     */
    public function publish(
        int $websiteId,
        string $websiteCode,
        string $baseUrl,
        array $buckets,
        string $target = 'canonical',
        int $maxUrls = self::STANDARD_MAX_URLS,
        int $maxBytes = self::STANDARD_MAX_BYTES,
    ): array {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('website_id 不能为负数'));
        }
        $websiteCode = $this->safeDirectoryToken($websiteCode, 'website_code');
        $target = $this->safeDirectoryToken($target, 'target');
        $baseUrl = rtrim(trim($baseUrl), '/');
        $this->assertBaseUrl($baseUrl);
        $maxUrls = min(self::STANDARD_MAX_URLS, max(1, $maxUrls));
        $maxBytes = min(self::STANDARD_MAX_BYTES, max(1024, $maxBytes));

        $locked = $this->operationLock->run('generate', [$websiteId, $target], function () use (
            $websiteId,
            $websiteCode,
            $baseUrl,
            $buckets,
            $target,
            $maxUrls,
            $maxBytes,
        ): array {
            return $this->publishLocked(
                $websiteId,
                $websiteCode,
                $baseUrl,
                $buckets,
                $target,
                $maxUrls,
                $maxBytes,
            );
        });

        if (!$locked['acquired']) {
            return [
                'success' => false,
                'error' => 'generation_in_progress',
                'message' => __('该站点的 Sitemap 正在生成，请稍后重试'),
                'retryable' => true,
                'website_id' => $websiteId,
                'target' => $target,
            ];
        }
        return is_array($locked['result']) ? $locked['result'] : [];
    }

    /**
     * @param list<array{module:string,scope:string,locale:string,urls:list<array<string,mixed>>}> $buckets
     * @return array<string,mixed>
     */
    private function publishLocked(
        int $websiteId,
        string $websiteCode,
        string $baseUrl,
        array $buckets,
        string $target,
        int $maxUrls,
        int $maxBytes,
    ): array {
        $generationId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        $targetDirectory = BP . '/pub/sitemaps/' . $websiteCode . '/' . $target;
        $this->ensureDirectory($targetDirectory);
        $journalDirectory = BP . '/var/weline-seo/sitemap-journals';
        $this->ensureDirectory($journalDirectory);
        $journalPath = $journalDirectory . '/' . hash('sha256', $websiteId . '|' . $target) . '.json';
        $recovery = $this->recoverJournal($journalPath, $targetDirectory);

        $indexPath = $targetDirectory . '/sitemap.xml';
        $oldHash = is_file($indexPath) ? (string)hash_file('sha256', $indexPath) : '';
        $oldReferenced = $this->readIndexReferences($indexPath);
        $targetUrl = $baseUrl . '/sitemaps/' . rawurlencode($websiteCode) . '/' . rawurlencode($target);
        $temporaryDirectory = $targetDirectory . '/.tmp-' . $generationId;
        $this->ensureDirectory($temporaryDirectory);
        $createdThisGeneration = [];

        try {
            $prepared = $this->prepareShards($buckets, $targetUrl, $maxUrls, $maxBytes);
            foreach ($prepared['shards'] as $shard) {
                $this->writeFile($temporaryDirectory . '/' . $shard['filename'], $shard['xml']);
            }

            $indexXml = $prepared['shards'] === []
                ? $this->buildUrlset([])
                : $this->buildIndex($prepared['shards']);
            $this->validateXml($indexXml, $prepared['shards'] === [] ? 'urlset' : 'sitemapindex', self::STANDARD_MAX_URLS);
            if (strlen($indexXml) > self::STANDARD_MAX_BYTES || count($prepared['shards']) > self::STANDARD_MAX_URLS) {
                throw new \RuntimeException((string)__('Sitemap 索引超过协议限制'));
            }
            $this->writeFile($temporaryDirectory . '/sitemap.xml', $indexXml);

            foreach ($prepared['shards'] as $shard) {
                $source = $temporaryDirectory . '/' . $shard['filename'];
                $destination = $targetDirectory . '/' . $shard['filename'];
                if (is_file($destination)) {
                    if (!hash_equals((string)hash_file('sha256', $destination), hash('sha256', $shard['xml']))) {
                        throw new \RuntimeException((string)__('内容寻址 Sitemap 文件发生哈希冲突'));
                    }
                    unlink($source);
                    continue;
                }
                if (!rename($source, $destination)) {
                    throw new \RuntimeException((string)__('无法发布 Sitemap shard：%{1}', $shard['filename']));
                }
                $createdThisGeneration[] = $shard['filename'];
            }

            $newReferenced = array_column($prepared['shards'], 'filename');
            $stale = array_values(array_diff($oldReferenced, $newReferenced));
            $newHash = hash('sha256', $indexXml);
            $journal = [
                'version' => 1,
                'website_id' => $websiteId,
                'target' => $target,
                'target_directory' => $targetDirectory,
                'old_index_hash' => $oldHash,
                'new_index_hash' => $newHash,
                'created' => $createdThisGeneration,
                'stale' => $stale,
                'generation_id' => $generationId,
            ];
            $this->writeJsonAtomically($journalPath, $journal);

            $indexTemporaryPath = $targetDirectory . '/.sitemap.xml.' . $generationId . '.tmp';
            $this->writeFile($indexTemporaryPath, $indexXml);
            if (!rename($indexTemporaryPath, $indexPath)) {
                throw new \RuntimeException((string)__('无法原子切换 Sitemap 索引'));
            }

            $removed = $this->removeUnreferenced($targetDirectory, $stale, $newReferenced);
            $manifest = [
                'version' => 1,
                'generation_id' => $generationId,
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
                'target' => $target,
                'index' => [
                    'filename' => 'sitemap.xml',
                    'path' => $indexPath,
                    'url' => $targetUrl . '/sitemap.xml',
                    'size' => strlen($indexXml),
                    'hash' => $newHash,
                ],
                'buckets' => $prepared['buckets'],
                'shards' => array_map(static fn (array $shard): array => array_diff_key($shard, ['xml' => true]), $prepared['shards']),
                'total_urls' => $prepared['total_urls'],
                'total_files' => count($prepared['shards']) + 1,
                'created_files' => count($createdThisGeneration),
                'reused_files' => count($newReferenced) - count($createdThisGeneration),
                'removed_files' => $removed,
                'recovery' => $recovery,
                'generated_at' => gmdate('c'),
            ];
            try {
                $this->writeJsonAtomically($targetDirectory . '/.manifest.json', $manifest);
            } catch (\Throwable $exception) {
                $manifest['warnings'][] = __('Sitemap manifest 写入失败：%{1}', $exception->getMessage());
            }
            if (is_file($journalPath)) {
                unlink($journalPath);
            }
            $this->removeTemporaryDirectory($temporaryDirectory);
            return ['success' => true, 'retryable' => false] + $manifest;
        } catch (\Throwable $exception) {
            if (is_file($journalPath)) {
                $this->recoverJournal($journalPath, $targetDirectory);
            } else {
                $this->removeUnreferenced($targetDirectory, $createdThisGeneration, $oldReferenced);
            }
            $this->removeTemporaryDirectory($temporaryDirectory);
            throw $exception;
        }
    }

    /**
     * @param list<array{module:string,scope:string,locale:string,urls:list<array<string,mixed>>}> $buckets
     * @return array{shards:list<array<string,mixed>>,buckets:list<array<string,mixed>>,total_urls:int}
     */
    private function prepareShards(array $buckets, string $targetUrl, int $maxUrls, int $maxBytes): array
    {
        usort($buckets, static fn (array $left, array $right): int => [
            (string)($left['module'] ?? ''), (string)($left['scope'] ?? ''), (string)($left['locale'] ?? ''),
        ] <=> [
            (string)($right['module'] ?? ''), (string)($right['scope'] ?? ''), (string)($right['locale'] ?? ''),
        ]);
        $shards = [];
        $bucketManifest = [];
        $seenLocations = [];
        $totalUrls = 0;
        foreach ($buckets as $bucket) {
            $module = trim((string)($bucket['module'] ?? ''));
            $scope = trim((string)($bucket['scope'] ?? ''));
            $locale = trim((string)($bucket['locale'] ?? ''));
            $urls = array_values(array_filter((array)($bucket['urls'] ?? []), 'is_array'));
            usort($urls, static fn (array $left, array $right): int => [
                (string)($left[SitemapUrl::schema_fields_URL] ?? ''),
                (string)($left[SitemapUrl::schema_fields_URL_KEY] ?? ''),
            ] <=> [
                (string)($right[SitemapUrl::schema_fields_URL] ?? ''),
                (string)($right[SitemapUrl::schema_fields_URL_KEY] ?? ''),
            ]);
            foreach ($urls as $urlIndex => $url) {
                $loc = $this->normalizePublishedLoc(
                    trim((string)($url[SitemapUrl::schema_fields_URL] ?? '')),
                    $targetUrl,
                );
                $url[SitemapUrl::schema_fields_URL] = $loc;
                $urls[$urlIndex] = $url;
                $duplicate = hash('sha256', json_encode([$locale, $loc], JSON_THROW_ON_ERROR));
                if (isset($seenLocations[$duplicate])) {
                    throw new \RuntimeException((string)__('多个 Provider 生成了重复 canonical URL：%{1}', $loc));
                }
                $seenLocations[$duplicate] = true;
            }

            $providerToken = $this->fileToken($module . '-' . $scope, 'provider');
            $localeToken = $this->fileToken($locale === '' ? 'default' : $locale, 'default');
            $chunks = $this->splitRows($urls, $maxUrls, $maxBytes);
            $bucketFiles = [];
            foreach ($chunks as $index => $chunk) {
                $xml = $this->buildUrlset($chunk);
                $filename = sprintf(
                    'sitemap_%s_%s_%d_%s.xml',
                    $providerToken,
                    $localeToken,
                    $index + 1,
                    substr(hash('sha256', $xml), 0, 12),
                );
                $lastmod = $this->maxLastmod($chunk);
                $shard = [
                    'filename' => $filename,
                    'url' => $targetUrl . '/' . rawurlencode($filename),
                    'count' => count($chunk),
                    'size' => strlen($xml),
                    'hash' => hash('sha256', $xml),
                    'lastmod' => $lastmod,
                    'module' => $module,
                    'scope' => $scope,
                    'locale' => $locale,
                    'xml' => $xml,
                ];
                $shards[] = $shard;
                $bucketFiles[] = $filename;
            }
            $bucketManifest[] = [
                'module' => $module,
                'scope' => $scope,
                'locale' => $locale,
                'url_count' => count($urls),
                'files' => $bucketFiles,
            ];
            $totalUrls += count($urls);
        }
        return ['shards' => $shards, 'buckets' => $bucketManifest, 'total_urls' => $totalUrls];
    }

    /** @param list<array<string,mixed>> $rows @return list<list<array<string,mixed>>> */
    private function splitRows(array $rows, int $maxUrls, int $maxBytes): array
    {
        $result = [];
        foreach (array_chunk($rows, $maxUrls) as $chunk) {
            $queue = [$chunk];
            while ($queue !== []) {
                $candidate = array_shift($queue);
                if ($candidate === null || $candidate === []) {
                    continue;
                }
                $xml = $this->buildUrlset($candidate);
                if (strlen($xml) <= $maxBytes) {
                    $this->validateXml($xml, 'urlset', $maxUrls);
                    $result[] = $candidate;
                    continue;
                }
                if (count($candidate) === 1) {
                    throw new \RuntimeException((string)__('单条 Sitemap URL 超过平台文件大小限制'));
                }
                $middle = intdiv(count($candidate), 2);
                array_unshift($queue, array_slice($candidate, $middle), array_slice($candidate, 0, $middle));
            }
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $rows */
    private function buildUrlset(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= $this->extensionRenderer->urlsetOpenTag($rows) . "\n";
        foreach ($rows as $row) {
            $loc = (string)($row[SitemapUrl::schema_fields_URL] ?? '');
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $this->escape($loc) . "</loc>\n";
            $lastmod = trim((string)($row[SitemapUrl::schema_fields_LASTMOD] ?? ''));
            if ($lastmod !== '') {
                $timestamp = strtotime($lastmod);
                if ($timestamp === false) {
                    throw new \RuntimeException((string)__('数据库中的 lastmod 无法解析'));
                }
                $xml .= '    <lastmod>' . gmdate('Y-m-d', $timestamp) . "</lastmod>\n";
            }
            $xml .= '    <changefreq>' . $this->escape((string)($row[SitemapUrl::schema_fields_CHANGEFREQ] ?? 'weekly')) . "</changefreq>\n";
            $xml .= '    <priority>' . $this->escape((string)($row[SitemapUrl::schema_fields_PRIORITY] ?? '0.5')) . "</priority>\n";
            $xml .= $this->extensionRenderer->renderUrlExtensions($row);
            $xml .= "  </url>\n";
        }
        return $xml . '</urlset>';
    }

    /** @param list<array<string,mixed>> $shards */
    private function buildIndex(array $shards): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($shards as $shard) {
            $xml .= "  <sitemap>\n";
            $xml .= '    <loc>' . $this->escape((string)$shard['url']) . "</loc>\n";
            if (trim((string)($shard['lastmod'] ?? '')) !== '') {
                $xml .= '    <lastmod>' . $this->escape((string)$shard['lastmod']) . "</lastmod>\n";
            }
            $xml .= "  </sitemap>\n";
        }
        return $xml . '</sitemapindex>';
    }

    /** @param list<array<string,mixed>> $rows */
    private function maxLastmod(array $rows): string
    {
        $maximum = '';
        foreach ($rows as $row) {
            $value = trim((string)($row[SitemapUrl::schema_fields_LASTMOD] ?? ''));
            if ($value !== '' && ($maximum === '' || strcmp($value, $maximum) > 0)) {
                $maximum = $value;
            }
        }
        if ($maximum === '') {
            return '';
        }
        $timestamp = strtotime($maximum);
        return $timestamp === false ? '' : gmdate('Y-m-d', $timestamp);
    }

    private function validateXml(string $xml, string $rootName, int $maxEntries): void
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $document->documentElement?->localName !== $rootName) {
            throw new \RuntimeException((string)__('生成的 Sitemap XML 无效'));
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $query = $rootName === 'urlset' ? '/s:urlset/s:url' : '/s:sitemapindex/s:sitemap';
        if ($xpath->query($query)->length > $maxEntries) {
            throw new \RuntimeException((string)__('生成的 Sitemap XML 条目数超过限制'));
        }
    }

    /** @return list<string> */
    private function readIndexReferences(string $indexPath): array
    {
        if (!is_file($indexPath)) {
            return [];
        }
        $xml = file_get_contents($indexPath);
        if (!is_string($xml) || $xml === '') {
            throw new \RuntimeException((string)__('现有 Sitemap 索引不可读'));
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \RuntimeException((string)__('现有 Sitemap 索引 XML 无效'));
        }
        if ($document->documentElement?->localName !== 'sitemapindex') {
            return [];
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $references = [];
        foreach ($xpath->query('/s:sitemapindex/s:sitemap/s:loc') as $node) {
            $filename = basename((string)(parse_url(trim($node->textContent), PHP_URL_PATH) ?: ''));
            if ($this->isGeneratedFilename($filename)) {
                $references[$filename] = $filename;
            }
        }
        return array_values($references);
    }

    /** @return array<string,mixed> */
    private function recoverJournal(string $journalPath, string $targetDirectory): array
    {
        if (!is_file($journalPath)) {
            return ['recovered' => false];
        }
        $decoded = json_decode((string)file_get_contents($journalPath), true);
        if (!is_array($decoded) || (string)($decoded['target_directory'] ?? '') !== $targetDirectory) {
            throw new \RuntimeException((string)__('Sitemap cleanup journal 无效或目录不匹配'));
        }
        $indexPath = $targetDirectory . '/sitemap.xml';
        $currentHash = is_file($indexPath) ? (string)hash_file('sha256', $indexPath) : '';
        $oldHash = (string)($decoded['old_index_hash'] ?? '');
        $newHash = (string)($decoded['new_index_hash'] ?? '');
        $currentReferences = $this->readIndexReferences($indexPath);
        if (hash_equals($oldHash, $currentHash)) {
            $removed = $this->removeUnreferenced($targetDirectory, (array)($decoded['created'] ?? []), $currentReferences);
            $state = 'old_index';
        } elseif (hash_equals($newHash, $currentHash)) {
            $removed = $this->removeUnreferenced($targetDirectory, (array)($decoded['stale'] ?? []), $currentReferences);
            $state = 'new_index';
        } else {
            throw new \RuntimeException((string)__('Sitemap journal 与当前索引状态不一致，需要人工检查'));
        }
        unlink($journalPath);
        return ['recovered' => true, 'state' => $state, 'removed_files' => $removed];
    }

    /** @param list<string> $candidates @param list<string> $referenced */
    private function removeUnreferenced(string $directory, array $candidates, array $referenced): int
    {
        $referencedMap = array_fill_keys($referenced, true);
        $removed = 0;
        foreach (array_values(array_unique(array_map('strval', $candidates))) as $filename) {
            if (!$this->isGeneratedFilename($filename) || isset($referencedMap[$filename])) {
                continue;
            }
            $path = $directory . '/' . $filename;
            if (is_file($path) && unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** @param array<string,mixed> $journal */
    private function writeJsonAtomically(string $path, array $journal): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $this->writeFile($temporary, json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException((string)__('无法原子写入 Sitemap 元数据'));
        }
    }

    private function writeFile(string $path, string $content): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException((string)__('无法创建 Sitemap 文件：%{1}', basename($path)));
        }
        try {
            $offset = 0;
            $length = strlen($content);
            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException((string)__('Sitemap 文件写入不完整：%{1}', basename($path)));
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new \RuntimeException((string)__('Sitemap 文件刷新失败：%{1}', basename($path)));
            }
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            fclose($handle);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException((string)__('无法创建 Sitemap 目录：%{1}', $directory));
        }
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        if (!is_dir($directory) || !str_contains(basename($directory), '.tmp-')) {
            return;
        }
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    private function safeDirectoryToken(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || $value === '.' || $value === '..' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException((string)__('%{1} 不是安全目录标识', $field));
        }
        return $value;
    }

    private function fileToken(string $value, string $fallback): string
    {
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));
        $slug = substr($slug !== '' ? $slug : $fallback, 0, 40);
        return $slug . '-' . substr(hash('sha256', $value), 0, 16);
    }

    private function isGeneratedFilename(string $filename): bool
    {
        return preg_match(self::GENERATED_FILE_PATTERN, $filename) === 1;
    }

    private function assertBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException((string)__('站点基础 URL 必须是绝对 HTTP(S) URL'));
        }
    }

    private function normalizePublishedLoc(string $loc, string $targetUrl): string
    {
        if ($loc === '' || strlen($loc) >= 2048 || preg_match('/[\x00-\x1F\x7F]/', $loc) === 1) {
            throw new \RuntimeException((string)__('Sitemap URL 为空、过长或包含控制字符'));
        }
        $baseParts = parse_url($targetUrl);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            throw new \RuntimeException((string)__('Sitemap 目标 URL 无效'));
        }
        if (str_starts_with($loc, '//')) {
            throw new \RuntimeException((string)__('Sitemap URL 不允许省略协议'));
        }
        if (!preg_match('#^https?://#i', $loc)) {
            $origin = strtolower((string)$baseParts['scheme']) . '://' . (string)$baseParts['host'];
            if (isset($baseParts['port'])) {
                $origin .= ':' . (int)$baseParts['port'];
            }
            $loc = $origin . '/' . ltrim($loc, '/');
        }
        $origin = strtolower((string)$baseParts['scheme']) . '://' . (string)$baseParts['host'];
        if (isset($baseParts['port'])) {
            $origin .= ':' . (int)$baseParts['port'];
        }
        $loc = $this->rewriteLoopbackLoc($loc, $origin);
        if (strlen($loc) >= 2048) {
            throw new \RuntimeException((string)__('Sitemap URL 长度必须小于 2048 字节'));
        }
        $locParts = parse_url($loc);
        if (!is_array($locParts) || !isset($locParts['scheme'], $locParts['host'])) {
            throw new \RuntimeException((string)__('Sitemap URL 不是绝对 URL'));
        }
        if (isset($locParts['user']) || isset($locParts['pass']) || isset($locParts['fragment'])) {
            throw new \RuntimeException((string)__('Sitemap URL 不允许 credentials 或 fragment'));
        }
        $locScheme = strtolower((string)$locParts['scheme']);
        $baseScheme = strtolower((string)($baseParts['scheme'] ?? ''));
        if (!in_array($locScheme, ['http', 'https'], true)) {
            throw new \RuntimeException((string)__('Sitemap URL 仅支持 HTTP(S)'));
        }
        $locPort = (int)($locParts['port'] ?? ($locScheme === 'https' ? 443 : 80));
        $basePort = (int)($baseParts['port'] ?? ($baseScheme === 'https' ? 443 : 80));
        if ($locScheme !== $baseScheme || strtolower((string)$locParts['host']) !== strtolower((string)($baseParts['host'] ?? '')) || $locPort !== $basePort) {
            throw new \RuntimeException((string)__('Sitemap URL 与站点不同源'));
        }
        return $loc;
    }

    private function rewriteLoopbackLoc(string $loc, string $origin): string
    {
        $host = strtolower((string)(parse_url($loc, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return $loc;
        }
        $isLoopback = $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || $host === '[::1]'
            || str_ends_with($host, '.localhost');
        if (!$isLoopback) {
            return $loc;
        }
        $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?: ''));
        if ($originHost === '' || $originHost === 'localhost' || $originHost === '127.0.0.1') {
            return $loc;
        }
        $path = (string)(parse_url($loc, PHP_URL_PATH) ?: '/');
        if ($path === '') {
            $path = '/';
        }
        return rtrim($origin, '/') . ($path === '/' ? '/' : $path);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
