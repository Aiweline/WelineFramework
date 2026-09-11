<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Audit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\SiteCrawlerAuditInterface;
use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Service\SeoWebsiteDirectory;

class SitemapCrawlerAuditService implements SiteCrawlerAuditInterface
{
    private const CONTRACT_VERSION = 'weline-seo-site-crawl/v1';
    private const COMMAND = 'weline-seo-site-crawl-report';
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;
    private const DEFAULT_TIMEOUT = 6;
    private const DEFAULT_BATCH_SIZE = 2;
    private const MAX_SITEMAP_DEPTH = 2;
    private const RESOURCE_HEAD_BUDGET = 24;
    /** Discover more sitemap locs than the audit limit so structure sampling can collapse families. */
    private const DISCOVERY_LIMIT = 500;

    /** @var list<string> */
    private array $sitemapMessages = [];

    /** @var array<string, array<string, mixed>> */
    private array $resourceHeadCache = [];

    /** @var array<string, bool> */
    private array $relaxedTlsHosts = [];

    public function __construct(
        private ?SeoWebsiteDirectory $websiteDirectory = null,
        private ?SitemapUrl $sitemapUrlModel = null
    ) {
    }

    /**
     * Full crawl for CLI/tests: begin + advance until completed.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function crawl(array $options): array
    {
        $job = $this->begin($options);
        while (($job['status'] ?? '') === 'running') {
            $remaining = \max(1, \count($job['urls'] ?? []) - (int)($job['cursor'] ?? 0));
            $job = $this->advance($job, $remaining);
        }

        return $this->toReport($job);
    }

    /**
     * Discover sitemap URLs and return a JSON-serializable job.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function begin(array $options): array
    {
        $this->sitemapMessages = [];
        $this->resourceHeadCache = [];
        $this->relaxedTlsHosts = [];

        $startedAt = \gmdate('c');
        $mode = \strtolower(\trim((string)($options['mode'] ?? 'sitemap')));
        if ($mode !== 'page') {
            $mode = 'sitemap';
        }
        $timeout = $this->timeout($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $baseUrl = $this->resolveBaseUrl($options);
        $origin = $this->origin($baseUrl);
        $issues = [];

        if ($mode === 'page') {
            return $this->beginPageAudit($options, $origin, $baseUrl, $timeout, $startedAt);
        }

        $limit = $this->limit($options['limit'] ?? self::DEFAULT_LIMIT);
        @\set_time_limit(\max(30, ($limit * $timeout) + 15));
        $sitemapUrl = $this->resolveSitemapUrl($options, $baseUrl);

        $sitemapResult = $this->collectSitemapUrls($sitemapUrl, $origin, self::DISCOVERY_LIMIT);
        $urls = $sitemapResult['urls'];
        $sitemapUrl = $sitemapResult['sitemapUrl'] ?: $sitemapUrl;

        if ($urls === []) {
            $urls = $this->databaseSitemapUrls($origin, self::DISCOVERY_LIMIT);
            if ($urls !== []) {
                $this->addIssue(
                    $issues,
                    'sitemap_network_unavailable_using_database',
                    'warning',
                    'P2',
                    'Sitemap Quality',
                    'Sitemap XML 不可用，已回退到 Weline SEO URL 数据',
                    '',
                    ['source' => 'database', 'messages' => $this->sitemapMessages],
                    '搜索引擎优先读取 sitemap XML。XML 不可访问会影响全站发现速度。',
                    '修复 /sitemap.xml 或手动输入正确 sitemap；保留 Weline SEO URL 数据作为兜底。',
                    5
                );
            } else {
                $this->addIssue(
                    $issues,
                    'sitemap_unavailable',
                    'error',
                    'P1',
                    'Sitemap Quality',
                    '未找到可审计的 sitemap URL',
                    '',
                    ['sitemapUrl' => $sitemapUrl, 'messages' => $this->sitemapMessages],
                    '没有 sitemap 时，批量 SEO 审计无法覆盖站点 URL，搜索引擎也更难发现深层页面。',
                    '在 robots.txt 声明 Sitemap:（Google/Bing 官方主路径），保证 /sitemap.xml 可访问，或在 SEO 模块中生成 sitemap URL 数据；HTML <link rel="sitemap"> 仅为可选便利。',
                    12
                );
            }
        }

        $discovered = $this->uniqueSameOriginUrls($urls, $origin);
        $sample = (new SitemapAuditUrlSampler())->sample($discovered);
        $urls = \array_slice($sample['urls'], 0, $limit);
        $job = [
            'status' => 'running',
            'mode' => 'sitemap',
            'urls' => \array_values($urls),
            'cursor' => 0,
            'pages' => [],
            'issues' => $issues,
            'failed' => [],
            'factsByUrl' => [],
            'titleMap' => [],
            'descriptionMap' => [],
            'canonicalMap' => [],
            'resourceHeadBudget' => self::RESOURCE_HEAD_BUDGET,
            'sitemapMessages' => \array_values($this->sitemapMessages),
            'relaxedTlsHosts' => $this->relaxedTlsHosts,
            'resourceHeadCache' => $this->resourceHeadCache,
            'sitemapUrl' => $sitemapUrl,
            'origin' => $origin,
            'limit' => $limit,
            'timeout' => $timeout,
            'startedAt' => $startedAt,
            'contractVersion' => self::CONTRACT_VERSION,
            'command' => self::COMMAND,
            'sampling' => [
                'discovered' => (int)$sample['discovered'],
                'sampled' => (int)$sample['sampled'],
                'collapsed' => (int)$sample['collapsed'],
                'audited' => \count($urls),
                'structures' => $sample['structures'],
            ],
        ];

        if ($job['urls'] === []) {
            return $this->finalizeJob($job);
        }

        return $job;
    }

    /**
     * Audit a single page URL with the same HTML rules as sitemap crawl (no sitemap discovery).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function beginPageAudit(array $options, string $origin, string $baseUrl, int $timeout, string $startedAt): array
    {
        @\set_time_limit(\max(30, $timeout + 15));
        $issues = [];
        $pageUrl = $this->normalizeUrl(
            (string)($options['pageUrl'] ?? $options['url'] ?? $options['startUrl'] ?? $baseUrl),
            $origin
        );
        if ($pageUrl === '' || !$this->sameOrigin($pageUrl, $origin)) {
            $this->addIssue(
                $issues,
                'page_audit_url_invalid',
                'error',
                'P0',
                'Indexability',
                '当前 URL 无效或非同源，无法审计',
                (string)($options['pageUrl'] ?? ''),
                ['origin' => $origin, 'pageUrl' => $options['pageUrl'] ?? null],
                '服务端审计只允许检测当前站点同源页面，避免变成任意 URL 抓取器。',
                '在目标站点页面打开 Weline 面板后再点「检测当前 URL」。',
                12
            );
            $pageUrl = '';
        } elseif ($this->isNonHtmlAssetUrl($pageUrl)) {
            $this->addIssue(
                $issues,
                'page_audit_non_html',
                'error',
                'P1',
                'Indexability',
                '当前 URL 是静态资源，不是可审计 HTML 页面',
                $pageUrl,
                [],
                '图片/CSS/JS 等资源不应作为页面 SEO 审计目标。',
                '打开商品/博客/分类等 HTML 页面后再检测。',
                8
            );
            $pageUrl = '';
        }

        $urls = $pageUrl !== '' ? [$pageUrl] : [];
        $job = [
            'status' => 'running',
            'mode' => 'page',
            'urls' => $urls,
            'cursor' => 0,
            'pages' => [],
            'issues' => $issues,
            'failed' => [],
            'factsByUrl' => [],
            'titleMap' => [],
            'descriptionMap' => [],
            'canonicalMap' => [],
            'resourceHeadBudget' => self::RESOURCE_HEAD_BUDGET,
            'sitemapMessages' => [],
            'relaxedTlsHosts' => $this->relaxedTlsHosts,
            'resourceHeadCache' => $this->resourceHeadCache,
            'sitemapUrl' => '',
            'origin' => $origin,
            'limit' => 1,
            'timeout' => $timeout,
            'startedAt' => $startedAt,
            'contractVersion' => self::CONTRACT_VERSION,
            'command' => 'weline-seo-page-audit-report',
            'sampling' => [
                'discovered' => \count($urls),
                'sampled' => \count($urls),
                'collapsed' => 0,
                'audited' => \count($urls),
                'structures' => [],
                'mode' => 'page',
                'pageUrl' => $pageUrl,
            ],
        ];

        if ($job['urls'] === []) {
            return $this->finalizeJob($job);
        }

        return $job;
    }

    /**
     * Audit the next batch of URLs; finalize when the cursor is exhausted.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function advance(array $job, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        if (($job['status'] ?? '') === 'completed') {
            return $job;
        }

        $this->restoreCachesFromJob($job);
        $batchSize = \max(1, $batchSize);
        $urls = \array_values($job['urls'] ?? []);
        $cursor = \max(0, (int)($job['cursor'] ?? 0));
        $timeout = $this->timeout($job['timeout'] ?? self::DEFAULT_TIMEOUT);
        $origin = (string)($job['origin'] ?? '');
        @\set_time_limit(\max(30, ($batchSize * $timeout) + 15));

        if ($cursor >= \count($urls)) {
            return $this->finalizeJob($job);
        }

        $end = \min(\count($urls), $cursor + $batchSize);
        $issues = \is_array($job['issues'] ?? null) ? $job['issues'] : [];
        $pages = \is_array($job['pages'] ?? null) ? $job['pages'] : [];
        $failed = \is_array($job['failed'] ?? null) ? $job['failed'] : [];
        $factsByUrl = \is_array($job['factsByUrl'] ?? null) ? $job['factsByUrl'] : [];
        $titleMap = \is_array($job['titleMap'] ?? null) ? $job['titleMap'] : [];
        $descriptionMap = \is_array($job['descriptionMap'] ?? null) ? $job['descriptionMap'] : [];
        $canonicalMap = \is_array($job['canonicalMap'] ?? null) ? $job['canonicalMap'] : [];
        $resourceHeadBudget = (int)($job['resourceHeadBudget'] ?? self::RESOURCE_HEAD_BUDGET);

        for ($i = $cursor; $i < $end; $i++) {
            $url = (string)$urls[$i];
            $fetch = $this->fetchUrl($url, $timeout, 'GET');
            $pageIssueIds = [];
            $facts = $this->auditPage($url, $fetch, $origin, $issues, $pageIssueIds, $resourceHeadBudget);
            $factsByUrl[$facts['canonicalUrl'] ?: $facts['url']] = $facts;
            $factsByUrl[$facts['url']] = $facts;
            $this->appendMap($titleMap, $facts['titleKey'], $facts['url']);
            $this->appendMap($descriptionMap, $facts['descriptionKey'], $facts['url']);
            $this->appendMap($canonicalMap, $facts['canonicalUrl'], $facts['url']);

            if (!$fetch['ok']) {
                $failed[] = [
                    'url' => $url,
                    'status' => $fetch['status'],
                    'error' => $fetch['error'],
                ];
            }

            $pageIssueIds = \array_values(\array_unique(\array_filter($pageIssueIds)));
            $pages[] = [
                'url' => $facts['url'],
                'status' => $fetch['status'],
                'title' => $facts['title'],
                'canonical' => $facts['canonicalUrl'],
                'language' => $facts['htmlLang'],
                'seoType' => $facts['seoType'],
                'score' => $this->pageScore($pageIssueIds, $issues),
                'issueIds' => $pageIssueIds,
                'jsonLd' => $this->jsonLdReportPayload(
                    \is_array($facts['jsonLd'] ?? null) ? $facts['jsonLd'] : []
                ),
            ];
        }

        $job['cursor'] = $end;
        $job['pages'] = $pages;
        $job['issues'] = $issues;
        $job['failed'] = $failed;
        $job['factsByUrl'] = $factsByUrl;
        $job['titleMap'] = $titleMap;
        $job['descriptionMap'] = $descriptionMap;
        $job['canonicalMap'] = $canonicalMap;
        $job['resourceHeadBudget'] = $resourceHeadBudget;
        $job['sitemapMessages'] = \array_values($this->sitemapMessages);
        $job['relaxedTlsHosts'] = $this->relaxedTlsHosts;
        $job['resourceHeadCache'] = $this->resourceHeadCache;
        $job['timeout'] = $timeout;
        $job['status'] = 'running';

        if ($job['cursor'] >= \count($urls)) {
            return $this->finalizeJob($job);
        }

        return $job;
    }

    /**
     * Public progress/final report shape (matches historical crawl() return).
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function toReport(array $job): array
    {
        $status = (string)($job['status'] ?? 'running');
        $urls = \array_values($job['urls'] ?? []);
        $pages = \is_array($job['pages'] ?? null) ? $job['pages'] : [];
        $failed = \is_array($job['failed'] ?? null) ? $job['failed'] : [];
        $issues = \is_array($job['issues'] ?? null) ? $job['issues'] : [];
        $startedAt = (string)($job['startedAt'] ?? \gmdate('c'));
        $finishedAt = (string)($job['finishedAt'] ?? ($status === 'completed' ? \gmdate('c') : $startedAt));
        $limit = (int)($job['limit'] ?? self::DEFAULT_LIMIT);
        $timeout = (int)($job['timeout'] ?? self::DEFAULT_TIMEOUT);
        $origin = (string)($job['origin'] ?? '');
        $sitemapUrl = (string)($job['sitemapUrl'] ?? '');
        $tlsRelaxedHosts = \array_keys(\is_array($job['relaxedTlsHosts'] ?? null) ? $job['relaxedTlsHosts'] : []);
        $sampling = \is_array($job['sampling'] ?? null) ? $job['sampling'] : [];
        $assumptions = \is_array($job['assumptions'] ?? null)
            ? $job['assumptions']
            : $this->defaultAssumptions($tlsRelaxedHosts, $sampling);
        $health = \is_array($job['health'] ?? null)
            ? $job['health']
            : [
                'score' => 0,
                'errors' => 0,
                'warnings' => 0,
                'notices' => 0,
                'status' => $status === 'completed' ? 'unknown' : 'running',
            ];
        $mode = \strtolower((string)($job['mode'] ?? ($sampling['mode'] ?? 'sitemap')));
        if ($mode !== 'page') {
            $mode = 'sitemap';
        }

        return [
            'contractVersion' => (string)($job['contractVersion'] ?? self::CONTRACT_VERSION),
            'command' => (string)($job['command'] ?? self::COMMAND),
            'generatedAt' => $finishedAt,
            'health' => $health,
            'crawl' => [
                'status' => $status,
                'mode' => $mode,
                'pageUrl' => (string)($sampling['pageUrl'] ?? (($mode === 'page' && $urls !== []) ? $urls[0] : '')),
                'sitemapUrl' => $sitemapUrl,
                'totalUrls' => \count($urls),
                'scanned' => \count($pages),
                'failed' => \count($failed),
                'limit' => $limit,
                'timeoutSeconds' => $timeout,
                'sameOrigin' => $origin,
                'tlsVerification' => $tlsRelaxedHosts === [] ? 'strict' : 'relaxed_for_local_development',
                'tlsRelaxedHosts' => \array_values($tlsRelaxedHosts),
                'sampling' => $sampling,
                'startedAt' => $startedAt,
                'finishedAt' => $status === 'completed' ? $finishedAt : null,
            ],
            'issues' => $issues,
            'pages' => $pages,
            'failedUrls' => $failed,
            'assumptions' => $assumptions,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function finalizeJob(array $job): array
    {
        $this->restoreCachesFromJob($job);
        $issues = \is_array($job['issues'] ?? null) ? $job['issues'] : [];
        $titleMap = \is_array($job['titleMap'] ?? null) ? $job['titleMap'] : [];
        $descriptionMap = \is_array($job['descriptionMap'] ?? null) ? $job['descriptionMap'] : [];
        $canonicalMap = \is_array($job['canonicalMap'] ?? null) ? $job['canonicalMap'] : [];
        $factsByUrl = \is_array($job['factsByUrl'] ?? null) ? $job['factsByUrl'] : [];
        $sitemapUrl = (string)($job['sitemapUrl'] ?? '');

        $this->auditDuplicateMeta($titleMap, $issues, 'duplicate_title', 'Title 重复', 'Meta', 6);
        $this->auditDuplicateMeta($descriptionMap, $issues, 'duplicate_description', 'Description 重复', 'Meta', 5);
        $this->auditCanonicalDuplicates($canonicalMap, $issues);
        $this->auditHreflangReciprocal($factsByUrl, $issues);
        $this->auditSitemapMessages($this->sitemapMessages, $sitemapUrl, $issues);

        $issues = $this->finalizeIssues($issues);
        $health = $this->health($issues);
        $finishedAt = \gmdate('c');
        $tlsRelaxedHosts = \array_keys($this->relaxedTlsHosts);

        $job['issues'] = $issues;
        $job['health'] = $health;
        $job['assumptions'] = $this->defaultAssumptions($tlsRelaxedHosts, \is_array($job['sampling'] ?? null) ? $job['sampling'] : []);
        $job['finishedAt'] = $finishedAt;
        $job['sitemapMessages'] = \array_values($this->sitemapMessages);
        $job['relaxedTlsHosts'] = $this->relaxedTlsHosts;
        $job['resourceHeadCache'] = $this->resourceHeadCache;
        $job['status'] = 'completed';

        return $job;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function restoreCachesFromJob(array $job): void
    {
        $messages = $job['sitemapMessages'] ?? [];
        $this->sitemapMessages = \is_array($messages) ? \array_values(\array_map('strval', $messages)) : [];

        $hosts = $job['relaxedTlsHosts'] ?? [];
        $this->relaxedTlsHosts = [];
        if (\is_array($hosts)) {
            foreach ($hosts as $host => $flag) {
                if (\is_string($host) && $host !== '' && $flag) {
                    $this->relaxedTlsHosts[$host] = true;
                }
            }
        }

        $cache = $job['resourceHeadCache'] ?? [];
        $this->resourceHeadCache = \is_array($cache) ? $cache : [];
    }

    /**
     * @param list<string> $tlsRelaxedHosts
     * @param array<string, mixed> $sampling
     * @return list<string>
     */
    private function defaultAssumptions(array $tlsRelaxedHosts, array $sampling = []): array
    {
        $assumptions = [
            'HTML 抓取型审计，不运行 Lighthouse、CrUX 或完整浏览器渲染。',
            '只扫描同源 URL，避免把面板变成任意 URL 抓取器。',
            '未压缩静态资源使用响应头和资源命名启发式判断。',
        ];
        $mode = \strtolower((string)($sampling['mode'] ?? 'sitemap'));
        if ($mode === 'page') {
            $pageUrl = (string)($sampling['pageUrl'] ?? '');
            $assumptions[] = '当前为单页审计模式：只检测指定 URL'
                . ($pageUrl !== '' ? ('（' . $pageUrl . '）') : '')
                . '，不读取 sitemap、不做结构抽样。';
        } else {
            $assumptions[] = '同结构 URL（product/blog/category/faq/promotion/page）每种只抽 1 个代表；其余单例 URL 全部审查。';
            $assumptions[] = 'sitemap 的 image:loc / 静态媒体 URL 不作为独立 HTML 页面抓取；图片问题挂在发现该问题的页面下。';
        }
        $collapsed = (int)($sampling['collapsed'] ?? 0);
        $discovered = (int)($sampling['discovered'] ?? 0);
        $sampled = (int)($sampling['sampled'] ?? 0);
        if ($discovered > 0) {
            $assumptions[] = 'Sitemap 发现 ' . $discovered . ' 个 URL，结构抽样后 ' . $sampled . ' 个进入审计'
                . ($collapsed > 0 ? ('（合并跳过 ' . $collapsed . ' 个同结构地址）') : '') . '。';
        }
        if ($tlsRelaxedHosts !== []) {
            $assumptions[] = '本地/开发 HTTPS 主机使用自签或本地 CA 证书时，服务端抓取会放宽 TLS 校验；生产证书有效性仍需使用严格 TLS 或外部工具验证。';
        }

        return $assumptions;
    }

    private function limit(mixed $value): int
    {
        $limit = (int)$value;
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        return \min(self::MAX_LIMIT, \max(1, $limit));
    }

    private function timeout(mixed $value): int
    {
        $timeout = (int)$value;

        return \min(15, \max(2, $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveBaseUrl(array $options): string
    {
        $startUrl = $this->normalizeUrl((string)($options['startUrl'] ?? ''), '');
        if ($startUrl !== '') {
            return $this->origin($startUrl);
        }

        $current = (string)($_SERVER['WELINE_FULL_REQUEST_URI'] ?? '');
        if ($current !== '' && \preg_match('/^https?:\/\//i', $current)) {
            return $this->origin($current);
        }

        $base = $this->websiteDirectory()->currentBaseUrl();
        if ($base !== '') {
            return $this->origin($base);
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $scheme = (!empty($_SERVER['HTTPS']) && \strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';

        return $host !== '' ? $scheme . '://' . $host : '';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveSitemapUrl(array $options, string $baseUrl): string
    {
        foreach (['sitemapUrl', 'sitemap', 'sitemap_url'] as $key) {
            $candidate = $this->normalizeUrl((string)($options[$key] ?? ''), $baseUrl);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $baseUrl !== '' ? \rtrim($baseUrl, '/') . '/sitemap.xml' : '';
    }

    /**
     * @return array{sitemapUrl: string, urls: list<string>}
     */
    private function collectSitemapUrls(string $sitemapUrl, string $origin, int $limit): array
    {
        $urls = [];
        $visited = [];
        $this->crawlSitemap($sitemapUrl, $origin, $limit, 0, $visited, $urls);

        return [
            'sitemapUrl' => $sitemapUrl,
            'urls' => \array_values(\array_unique($urls)),
        ];
    }

    /**
     * @param array<string, bool> $visited
     * @param list<string> $urls
     */
    private function crawlSitemap(string $sitemapUrl, string $origin, int $limit, int $depth, array &$visited, array &$urls): void
    {
        $sitemapUrl = $this->normalizeUrl($sitemapUrl, $origin);
        if ($sitemapUrl === '') {
            $this->sitemapMessages[] = 'sitemap URL 为空。';
            return;
        }
        if (!$this->sameOrigin($sitemapUrl, $origin)) {
            $this->sitemapMessages[] = '跳过非同源 sitemap: ' . $sitemapUrl;
            return;
        }
        if ($depth > self::MAX_SITEMAP_DEPTH) {
            $this->sitemapMessages[] = 'sitemap index 递归深度超过 ' . self::MAX_SITEMAP_DEPTH . ': ' . $sitemapUrl;
            return;
        }
        if (isset($visited[$sitemapUrl]) || \count($urls) >= $limit) {
            return;
        }
        $visited[$sitemapUrl] = true;

        $fetch = $this->fetchUrl($sitemapUrl, self::DEFAULT_TIMEOUT, 'GET');
        if (!$fetch['ok'] || !\is_string($fetch['body']) || \trim($fetch['body']) === '') {
            $this->sitemapMessages[] = '无法读取 sitemap: ' . $sitemapUrl . ' ' . ($fetch['error'] ?: ('HTTP ' . $fetch['status']));
            return;
        }

        $xml = $this->parseXml($fetch['body']);
        if (!$xml instanceof \SimpleXMLElement) {
            $this->sitemapMessages[] = 'sitemap XML 解析失败: ' . $sitemapUrl;
            return;
        }

        $rootName = \strtolower($xml->getName());
        if ($rootName === 'sitemapindex') {
            $locs = $this->xmlSitemapIndexLocs($xml);
            if ($locs === []) {
                $this->sitemapMessages[] = 'sitemap index 没有 sitemap/loc 节点: ' . $sitemapUrl;
                return;
            }
            foreach ($locs as $loc) {
                $this->crawlSitemap($loc, $origin, $limit, $depth + 1, $visited, $urls);
                if (\count($urls) >= $limit) {
                    break;
                }
            }
            return;
        }

        $entries = $this->xmlUrlsetEntries($xml);
        if ($entries === []) {
            $this->sitemapMessages[] = 'sitemap 没有 url/loc 节点: ' . $sitemapUrl;
            return;
        }

        $seenInFile = [];
        $skippedAssets = 0;
        foreach ($entries as $entry) {
            $url = $this->normalizeUrl((string)($entry['loc'] ?? ''), $origin);
            if ($url === '') {
                continue;
            }
            if ($this->isNonHtmlAssetUrl($url)) {
                $skippedAssets++;
                continue;
            }
            if (isset($seenInFile[$url])) {
                $this->sitemapMessages[] = 'sitemap 内存在重复 URL: ' . $url;
                continue;
            }
            $seenInFile[$url] = true;
            if (!$this->sameOrigin($url, $origin)) {
                $this->sitemapMessages[] = '跳过非同源 URL: ' . $url;
                continue;
            }
            $urls[] = $url;
            if (\count($urls) >= $limit) {
                break;
            }
        }
        if ($skippedAssets > 0) {
            $this->sitemapMessages[] = '已忽略 sitemap 中的 ' . $skippedAssets . ' 个非 HTML 资源 loc（如图片 image:loc），它们会挂在发现页面下审查，不作为独立页面抓取。';
        }
    }

    private function parseXml(string $xml): ?\SimpleXMLElement
    {
        $previous = \libxml_use_internal_errors(true);
        \libxml_clear_errors();
        $parsed = \simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        return $parsed instanceof \SimpleXMLElement ? $parsed : null;
    }

    /**
     * @return list<string>
     */
    private function xmlSitemapIndexLocs(\SimpleXMLElement $xml): array
    {
        $locs = $xml->xpath('/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]/*[local-name()="loc"]') ?: [];
        $result = [];
        foreach ($locs as $loc) {
            $value = \trim((string)$loc);
            if ($value !== '') {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Page entries only: url/loc. Nested image:loc are not crawl targets.
     *
     * @return list<array{loc: string, images: list<string>}>
     */
    private function xmlUrlsetEntries(\SimpleXMLElement $xml): array
    {
        $nodes = $xml->xpath('/*[local-name()="urlset"]/*[local-name()="url"]') ?: [];
        $entries = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \SimpleXMLElement) {
                continue;
            }
            $locNodes = $node->xpath('./*[local-name()="loc"]') ?: [];
            $loc = '';
            foreach ($locNodes as $locNode) {
                $candidate = \trim((string)$locNode);
                if ($candidate !== '') {
                    $loc = $candidate;
                    break;
                }
            }
            if ($loc === '') {
                continue;
            }
            $images = [];
            $imageLocs = $node->xpath('./*[local-name()="image"]/*[local-name()="loc"]') ?: [];
            foreach ($imageLocs as $imageLoc) {
                $imageUrl = \trim((string)$imageLoc);
                if ($imageUrl !== '') {
                    $images[] = $imageUrl;
                }
            }
            $entries[] = [
                'loc' => $loc,
                'images' => \array_values(\array_unique($images)),
            ];
        }

        return $entries;
    }

    private function isNonHtmlAssetUrl(string $url): bool
    {
        $path = \strtolower((string)(\parse_url($url, PHP_URL_PATH) ?: ''));
        if ($path === '') {
            return false;
        }
        if (\str_contains($path, '/pub/media/') || \str_contains($path, '/media/')) {
            return true;
        }

        return (bool)\preg_match(
            '/\.(?:webp|avif|jpe?g|png|gif|svg|ico|bmp|tiff?|css|js|mjs|map|woff2?|ttf|eot|otf|mp4|webm|mp3|wav|pdf|zip|gz|xml)$/D',
            $path
        );
    }

    /**
     * @return list<string>
     */
    private function xmlLocs(\SimpleXMLElement $xml): array
    {
        // Legacy helper: prefer page url/loc; fall back to any loc for unusual feeds.
        $entries = $this->xmlUrlsetEntries($xml);
        if ($entries !== []) {
            return \array_values(\array_map(static fn(array $e): string => $e['loc'], $entries));
        }

        return $this->xmlSitemapIndexLocs($xml);
    }

    /**
     * @return list<string>
     */
    private function databaseSitemapUrls(string $origin, int $limit): array
    {
        try {
            $website = $this->websiteDirectory()->matchWebsiteByUrl($origin) ?? $this->websiteDirectory()->currentWebsite();
            $websiteId = (int)($website['website_id'] ?? 0);
            if ($websiteId <= 0) {
                return [];
            }
            $rows = $this->sitemapUrlModel()->getActiveUrls($websiteId);
        } catch (\Throwable) {
            return [];
        }

        $urls = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $url = $this->normalizeUrl((string)($row[SitemapUrl::schema_fields_URL] ?? $row['url'] ?? ''), $origin);
            if ($url !== '' && $this->sameOrigin($url, $origin) && !$this->isNonHtmlAssetUrl($url)) {
                $urls[] = $url;
            }
            if (\count($urls) >= $limit) {
                break;
            }
        }

        return \array_values(\array_unique($urls));
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function uniqueSameOriginUrls(array $urls, string $origin): array
    {
        $seen = [];
        $result = [];
        foreach ($urls as $url) {
            $normalized = $this->normalizeUrl($url, $origin);
            if ($normalized === '' || !$this->sameOrigin($normalized, $origin) || isset($seen[$normalized])) {
                continue;
            }
            if ($this->isNonHtmlAssetUrl($normalized)) {
                continue;
            }
            $seen[$normalized] = true;
            $result[] = $normalized;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $fetch
     * @param array<string, array<string, mixed>> $issues
     * @param list<string> $pageIssueIds
     * @return array<string, mixed>
     */
    private function auditPage(
        string $url,
        array $fetch,
        string $origin,
        array &$issues,
        array &$pageIssueIds,
        int &$resourceHeadBudget
    ): array {
        $body = \is_string($fetch['body'] ?? null) ? (string)$fetch['body'] : '';
        $facts = $this->extractHtmlFacts($body, $url, $origin);
        $facts['url'] = $url;

        $status = (int)($fetch['status'] ?? 0);
        if (!$fetch['ok']) {
            $fetchError = (string)($fetch['error'] ?? '');
            $isTlsError = $this->isTlsVerificationError($fetchError);
            $issueId = $isTlsError
                ? 'https_certificate_untrusted'
                : ($status >= 500 ? 'http_5xx' : ($status >= 400 ? 'http_4xx' : 'page_fetch_failed'));
            $title = $isTlsError
                ? 'HTTPS 证书不被审计器信任'
                : ($status >= 500 ? '页面返回 5xx' : ($status >= 400 ? '页面返回 4xx' : '页面抓取失败'));
            $evidence = ['status' => $status, 'error' => $fetchError];
            if (isset($fetch['tlsVerification'])) {
                $evidence['tlsVerification'] = $fetch['tlsVerification'];
            }
            $this->addIssue(
                $issues,
                $issueId,
                'error',
                $status >= 500 || $status === 0 ? 'P0' : 'P1',
                'Crawlability',
                $title,
                $url,
                $evidence,
                $isTlsError ? '搜索引擎和服务端抓取器需要能验证 HTTPS 证书链，否则页面会被视为不可抓取。' : 'sitemap 中的 URL 必须稳定可访问。错误状态会浪费抓取预算，并阻止索引。',
                $isTlsError ? '为站点配置受信任的 HTTPS 证书链；本地开发域名（*.test.weline.com / *.test / localhost）可继续使用 Weline 本地证书豁免。' : '修复页面路由、服务错误或从 sitemap 中移除不可访问 URL。',
                $status >= 500 || $status === 0 ? 16 : 12,
                $pageIssueIds
            );
            return $facts;
        }

        if ($status >= 300 && $status < 400) {
            $this->addIssue(
                $issues,
                'sitemap_redirect_url',
                'warning',
                'P2',
                'Crawlability',
                'sitemap URL 存在重定向',
                $url,
                ['finalUrl' => $fetch['finalUrl'] ?? ''],
                'sitemap 应直接指向最终规范 URL，重定向会消耗抓取预算并制造 canonical 噪音。',
                '把 sitemap 中的 loc 改成最终 200 的 canonical URL。',
                5,
                $pageIssueIds
            );
        }

        $this->auditIndexability($facts, $origin, $issues, $pageIssueIds);
        $this->auditMeta($facts, $issues, $pageIssueIds);
        $this->auditInternational($facts, $origin, $issues, $pageIssueIds);
        $this->auditContent($facts, $issues, $pageIssueIds);
        $this->auditStructuredData($facts, $issues, $pageIssueIds);
        $this->auditSecurity($facts, $origin, $issues, $pageIssueIds);
        $this->auditStaticPerformance($facts, $origin, $issues, $pageIssueIds, $resourceHeadBudget);
        $this->auditMediaAndLinks($facts, $origin, $issues, $pageIssueIds);

        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractHtmlFacts(string $html, string $url, string $origin): array
    {
        $doc = new \DOMDocument();
        $previous = \libxml_use_internal_errors(true);
        \libxml_clear_errors();
        $loaded = false;
        if ($html !== '') {
            $loaded = @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        }
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        $xpath = $loaded ? new \DOMXPath($doc) : null;
        $title = $xpath ? \trim($this->text($xpath, '//title')) : '';
        $description = $xpath ? $this->meta($xpath, 'description') : '';
        $robots = $xpath ? \strtolower($this->meta($xpath, 'robots')) : '';
        $canonicalRaw = $xpath ? $this->attr($xpath, '//link[contains(concat(" ", normalize-space(@rel), " "), " canonical ")]', 'href') : '';
        $canonicalUrl = $this->normalizeUrl($canonicalRaw, $origin);
        $htmlLang = $loaded && $doc->documentElement instanceof \DOMElement
            ? \trim((string)$doc->documentElement->getAttribute('lang'))
            : '';
        $ogType = $xpath ? $this->metaProperty($xpath, 'og:type') : '';
        $pageTypeMeta = $xpath ? ($this->meta($xpath, 'page-type') ?: $this->meta($xpath, 'seo:type')) : '';
        $headings = $xpath ? $this->headings($xpath) : [];
        $images = $xpath ? $this->images($xpath, $origin) : [];
        $resources = $xpath ? $this->resources($xpath, $origin) : [];
        $links = $xpath ? $this->links($xpath, $origin) : [];
        $forms = $xpath ? $this->forms($xpath, $origin) : [];
        $hreflang = $xpath ? $this->hreflang($xpath, $origin) : [];
        $jsonLd = $xpath ? $this->jsonLd($xpath) : ['types' => [], 'nodes' => [], 'errors' => []];
        $text = $loaded ? $this->visibleText($doc) : \trim(\strip_tags($html));
        $seoType = $this->inferSeoType($pageTypeMeta, $ogType, $url, $jsonLd['types']);

        return [
            'url' => $url,
            'title' => $title,
            'titleKey' => $this->compareKey($title),
            'description' => $description,
            'descriptionKey' => $this->compareKey($description),
            'robots' => $robots,
            'canonicalRaw' => $canonicalRaw,
            'canonicalUrl' => $canonicalUrl,
            'htmlLang' => $htmlLang,
            'seoType' => $seoType,
            'ogType' => $ogType,
            'headings' => $headings,
            'images' => $images,
            'resources' => $resources,
            'links' => $links,
            'forms' => $forms,
            'hreflang' => $hreflang,
            'jsonLd' => $jsonLd,
            'textChars' => \mb_strlen($text),
            'wordCount' => $this->wordCount($text),
        ];
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, array<string, mixed>> $issues
     * @param list<string> $pageIssueIds
     */
    private function auditIndexability(array $facts, string $origin, array &$issues, array &$pageIssueIds): void
    {
        $url = (string)$facts['url'];
        $robots = (string)$facts['robots'];
        if (\str_contains($robots, 'noindex')) {
            $this->addIssue($issues, 'noindex_in_sitemap', 'error', 'P0', 'Indexability', 'sitemap 页面包含 noindex', $url, ['robots' => $robots], 'sitemap 是给搜索引擎索引的清单，noindex 页面出现在 sitemap 会产生强烈冲突。', '从 sitemap 移除 noindex 页面，或移除 noindex 并保证 canonical 正确。', 16, $pageIssueIds);
        }
        if ((string)$facts['canonicalRaw'] === '') {
            $this->addIssue($issues, 'canonical_missing', 'warning', 'P1', 'Indexability', '缺少 canonical', $url, [], 'canonical 帮助搜索引擎合并重复 URL 并理解规范版本。', '为每个可索引页面输出绝对 canonical URL。', 7, $pageIssueIds);
        } elseif ((string)$facts['canonicalUrl'] === '') {
            $this->addIssue($issues, 'canonical_invalid', 'error', 'P1', 'Indexability', 'canonical URL 无效', $url, ['canonical' => $facts['canonicalRaw']], '无效 canonical 会导致搜索引擎忽略规范信号。', '改成完整、可解析、同源的绝对 URL。', 8, $pageIssueIds);
        } else {
            if (!$this->sameOrigin((string)$facts['canonicalUrl'], $origin)) {
                $this->addIssue($issues, 'canonical_cross_origin', 'error', 'P0', 'Indexability', 'canonical 指向非同源 URL', $url, ['canonical' => $facts['canonicalUrl']], 'canonical 跨域会把当前页面权重交给其他站点或错误域名。', '确认站点域名配置，把 canonical 指向当前站点规范 URL。', 12, $pageIssueIds);
            }
            if (\str_starts_with($origin, 'https://') && \str_starts_with((string)$facts['canonicalUrl'], 'http://')) {
                $this->addIssue($issues, 'canonical_http_on_https', 'error', 'P0', 'Security/HTTPS', 'HTTPS 页面 canonical 指向 HTTP', $url, ['canonical' => $facts['canonicalUrl']], 'HTTPS 页面使用 HTTP canonical 会削弱安全信号并可能触发混合协议收录。', '把 canonical 改为 https:// 版本。', 12, $pageIssueIds);
            }
        }
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, array<string, mixed>> $issues
     * @param list<string> $pageIssueIds
     */
    private function auditMeta(array $facts, array &$issues, array &$pageIssueIds): void
    {
        $url = (string)$facts['url'];
        $title = (string)$facts['title'];
        $description = (string)$facts['description'];
        $titleLength = \mb_strlen($title);
        $descriptionLength = \mb_strlen($description);
        if ($title === '') {
            $this->addIssue($issues, 'title_missing', 'error', 'P0', 'Meta', '缺少 title', $url, [], 'Title 是搜索结果标题和页面主题的核心信号。', '为页面输出唯一、可读、包含主意图的 title。', 12, $pageIssueIds);
        } elseif ($titleLength < 30 || $titleLength > 65) {
            $this->addIssue($issues, 'title_length', 'warning', 'P2', 'Meta', 'title 长度不理想', $url, ['length' => $titleLength, 'title' => $title], '过短缺少上下文，过长可能在 SERP 截断。', '把 title 控制在 30-65 字符，并保持唯一。', 4, $pageIssueIds);
        }
        if ($description === '') {
            $this->addIssue(
                $issues,
                'description_missing',
                'warning',
                'P1',
                'Meta',
                '缺少 meta description',
                $url,
                [],
                'Description 常被用作搜索摘要候选，影响可读性和点击率；Google 也可能改写摘要。',
                '补充清晰、与页面内容匹配的摘要即可。Google 未规定强制字数；过短可酌情补充价值点，关键信息靠前写。',
                7,
                $pageIssueIds
            );
        } elseif ($descriptionLength < 50) {
            // Google 无强制字数；仅对明显过短给 notice，且不扣分。
            $this->addIssue(
                $issues,
                'description_length',
                'notice',
                'P3',
                'Meta',
                'description 偏短（可选优化）',
                $url,
                ['length' => $descriptionLength],
                'Google 未规定 description 字数下限；极短摘要可能错失向用户说明页面价值的机会。',
                '可酌情补充页面卖点或行动引导；不要求凑到固定区间。SERP 截断按像素宽度，而非固定字符数。',
                0,
                $pageIssueIds
            );
        } elseif ($descriptionLength > 320) {
            $this->addIssue(
                $issues,
                'description_length',
                'notice',
                'P3',
                'Meta',
                'description 偏长（可选优化）',
                $url,
                ['length' => $descriptionLength],
                'Google 无强制上限，但搜索结果摘要通常按设备宽度截断。',
                '把最重要的信息写在前部即可；无需为工具分数硬裁到固定字数。',
                0,
                $pageIssueIds
            );
        }
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, array<string, mixed>> $issues
     * @param list<string> $pageIssueIds
     */
    private function auditInternational(array $facts, string $origin, array &$issues, array &$pageIssueIds): void
    {
        $url = (string)$facts['url'];
        $lang = (string)$facts['htmlLang'];
        $hreflang = \is_array($facts['hreflang'] ?? null) ? $facts['hreflang'] : [];
        if ($lang === '') {
            $this->addIssue($issues, 'html_lang_missing', 'error', 'P1', 'International SEO', '缺少 html lang', $url, [], '语言标记帮助搜索引擎和辅助技术理解页面语言。', '输出有效 BCP47 语言码，例如 zh-Hans-CN、en-US。', 8, $pageIssueIds);
        } elseif (!$this->validLanguageCode($lang)) {
            $this->addIssue($issues, 'html_lang_invalid', 'error', 'P1', 'International SEO', 'html lang 格式错误', $url, ['htmlLang' => $lang], '错误语言码会影响本地化匹配和 hreflang 解释。', '使用 BCP47 格式，避免下划线和空值。', 8, $pageIssueIds);
        }

        $codes = [];
        $hasSelf = false;
        $xDefault = 0;
        foreach ($hreflang as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $code = (string)($item['code'] ?? '');
            $href = (string)($item['href'] ?? '');
            $normalizedCode = \strtolower(\str_replace('_', '-', $code));
            if ($normalizedCode === 'x-default') {
                $xDefault++;
            } elseif (!$this->validLanguageCode($code)) {
                $this->addIssue($issues, 'hreflang_invalid_code', 'error', 'P1', 'International SEO', 'hreflang 语言码格式错误', $url, ['code' => $code, 'href' => $href], '无效 hreflang 会被搜索引擎忽略，甚至导致整组信号不可信。', '使用 language-region 形式，例如 en-US；x-default 只用于兜底。', 7, $pageIssueIds);
            }
            if (($codes[$normalizedCode] ?? false) === true) {
                $this->addIssue($issues, 'hreflang_duplicate_code', 'error', 'P1', 'International SEO', 'hreflang 语言码重复', $url, ['code' => $code], '同一页面同一语言只能有一个 alternate URL。', '每个 hreflang code 只保留一个 URL。', 6, $pageIssueIds);
            }
            $codes[$normalizedCode] = true;
            if (!\preg_match('/^https?:\/\//i', $href)) {
                $this->addIssue($issues, 'hreflang_non_absolute', 'error', 'P1', 'International SEO', 'hreflang 不是绝对 URL', $url, ['code' => $code, 'href' => $href], '搜索引擎要求 hreflang href 使用完整 URL。', '输出包含协议和域名的完整 URL。', 6, $pageIssueIds);
            }
            if ($href !== '' && !$this->sameOrigin($href, $origin)) {
                $this->addIssue($issues, 'hreflang_cross_origin', 'warning', 'P2', 'International SEO', 'hreflang 指向非同源 URL', $url, ['code' => $code, 'href' => $href], '跨站 hreflang 需要所有站点都互链，否则容易失效。', '确保跨站语言站点互相声明，或统一到当前站点语言 URL。', 4, $pageIssueIds);
            }
            if ($lang !== '' && $normalizedCode === \strtolower(\str_replace('_', '-', $lang))) {
                $hasSelf = true;
            }
        }
        if ($hreflang !== [] && !$hasSelf && $lang !== '') {
            $this->addIssue($issues, 'hreflang_self_missing', 'warning', 'P1', 'International SEO', '缺少当前语言 hreflang self', $url, ['htmlLang' => $lang], 'hreflang 组应包含当前页面自身语言版本，便于搜索引擎闭环理解。', '为当前 canonical URL 添加对应语言的 alternate hreflang。', 5, $pageIssueIds);
        }
        if ($xDefault > 1) {
            $this->addIssue($issues, 'hreflang_x_default_duplicate', 'warning', 'P2', 'International SEO', 'x-default 出现多次', $url, ['count' => $xDefault], 'x-default 应只有一个兜底 URL。', '只保留一个 x-default fallback。', 3, $pageIssueIds);
        }
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, array<string, mixed>> $issues
     * @param list<string> $pageIssueIds
     */
    private function auditContent(array $facts, array &$issues, array &$pageIssueIds): void
    {
        $url = (string)$facts['url'];
        $headings = \is_array($facts['headings'] ?? null) ? $facts['headings'] : [];
        $h1 = \array_values(\array_filter($headings, static fn($item) => \is_array($item) && ($item['tag'] ?? '') === 'h1'));
        if (\count($h1) === 0) {
            $this->addIssue($issues, 'h1_missing', 'error', 'P1', 'Content', '缺少 H1', $url, [], 'H1 是页面主主题的可见语义入口。', '为页面主体添加一个唯一 H1。', 7, $pageIssueIds);
        } elseif (\count($h1) > 1) {
            $this->addIssue($issues, 'h1_multiple', 'warning', 'P2', 'Content', '存在多个 H1', $url, ['count' => \count($h1)], '多个 H1 会削弱页面主主题层级。', '保留一个主 H1，其余改为 H2/H3。', 4, $pageIssueIds);
        }
        if ((int)$facts['wordCount'] < 250) {
            $this->addIssue($issues, 'thin_content', 'warning', 'P2', 'Content', '页面正文内容偏薄', $url, ['words' => $facts['wordCount']], '内容过薄的页面更难覆盖搜索意图，也不利于 AI 摘要提取。', '补充页面专属说明、FAQ、步骤、规格、案例或内部链接模块。', 5, $pageIssueIds);
        }
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, array<string, mixed>> $issues
     * @param list<string> $pageIssueIds
     */
    private function auditStructuredData(array $facts, array &$issues, array &$pageIssueIds): void
    {
        $url = (string)$facts['url'];
        $jsonLd = \is_array($facts['jsonLd'] ?? null) ? $facts['jsonLd'] : ['types' => [], 'nodes' => [], 'errors' => []];
        $errors = \is_array($jsonLd['errors'] ?? null) ? $jsonLd['errors'] : [];
        if ($errors !== []) {
            $this->addIssue($issues, 'jsonld_invalid', 'error', 'P1', 'Structured Data', 'JSON-LD 解析失败', $url, ['errors' => \array_slice($errors, 0, 3)], '无效 JSON-LD 会被搜索引擎忽略，并可能影响富结果资格。', '修复 JSON 格式，确保 type=application/ld+json 中是合法 JSON。', 9, $pageIssueIds);
            return;
        }

        $rules = $this->structuredDataRule((string)$facts['seoType'], (string)$facts['url']);
        $jsonLdNodes = \is_array($jsonLd['nodes'] ?? null) ? $jsonLd['nodes'] : [];
        if ($rules === []) {
            $this->auditEeatStrictStructuredSignals($facts, $jsonLd, [], $issues, $pageIssueIds);
            return;
        }
        $types = \array_map('strtolower', \is_array($jsonLd['types'] ?? null) ? $jsonLd['types'] : []);
        $expectedTypes = \array_map('strtolower', (array)$rules['types']);
        $hasType = \count(\array_intersect($types, $expectedTypes)) > 0;
        if (!$hasType) {
            $this->addIssue($issues, 'jsonld_page_type_missing', 'warning', 'P1', 'Structured Data', '页面类型缺少对应 JSON-LD', $url, ['expected' => $rules['types'], 'found' => $jsonLd['types'] ?? []], '页面类型和结构化数据不匹配会错失富结果和实体理解信号。', '按页面类型输出对应 schema，例如新闻用 NewsArticle，博客用 BlogPosting，产品用 Product。', 8, $pageIssueIds);
            $this->auditEeatStrictStructuredSignals($facts, $jsonLd, [], $issues, $pageIssueIds);
            return;
        }

        $primary = $this->firstJsonLdNodeOfTypes($jsonLdNodes, (array)$rules['types']);
        if ($primary === []) {
            $this->auditEeatStrictStructuredSignals($facts, $jsonLd, [], $issues, $pageIssueIds);
            return;
        }
        foreach ((array)$rules['required'] as $field) {
            if (!$this->jsonLdHasField($primary, (string)$field)) {
                $this->addIssue($issues, 'jsonld_missing_' . $this->slug((string)$field), 'warning', 'P1', 'Structured Data', 'JSON-LD 缺少必要字段 ' . $field, $url, ['type' => $primary['@type'] ?? '', 'field' => $field], '必要字段缺失会让页面不符合对应富结果结构。', '补充真实可靠的 ' . $field . ' 字段，不要编造不可验证数据。', 4, $pageIssueIds);
            }
        }
        // ProductGroup may expose offers via hasVariant; require either offers or hasVariant.
        $primaryTypes = \array_map('strtolower', \array_map('strval', (array)($primary['@type'] ?? [])));
        if (\in_array('productgroup', $primaryTypes, true)
            && !$this->jsonLdHasField($primary, 'offers')
            && !$this->jsonLdHasField($primary, 'hasVariant')
        ) {
            $this->addIssue($issues, 'jsonld_missing_offers_or_variants', 'warning', 'P1', 'Structured Data', 'JSON-LD ProductGroup 缺少 offers/hasVariant', $url, ['type' => $primary['@type'] ?? ''], '变体商品需要 offers 或 hasVariant，搜索引擎才能理解可售卖实体。', '为 ProductGroup 输出 hasVariant，或提供汇总 offers。', 4, $pageIssueIds);
        } elseif (\in_array('product', $primaryTypes, true) && !$this->jsonLdHasField($primary, 'offers')) {
            $this->addIssue($issues, 'jsonld_missing_offers', 'warning', 'P1', 'Structured Data', 'JSON-LD 缺少必要字段 offers', $url, ['type' => $primary['@type'] ?? '', 'field' => 'offers'], '必要字段缺失会让页面不符合对应富结果结构。', '补充真实可靠的 offers 字段，不要编造不可验证数据。', 4, $pageIssueIds);
        }

        $this->auditEeatStrictStructuredSignals($facts, $jsonLd, $primary, $issues, $pageIssueIds);
    }

    /**
     * Soft Helpful Content Who/Trust signals aligned with inspector EEAT_STRICT_RULES (non-blocking).
     *
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $jsonLd
     * @param array<string, mixed> $primary
     * @param array<string, array<string, mixed>> $issues
     * @param list<string> $pageIssueIds
     */
    private function auditEeatStrictStructuredSignals(array $facts, array $jsonLd, array $primary, array &$issues, array &$pageIssueIds): void
    {
        $url = (string)$facts['url'];
        $seoType = strtolower((string)$facts['seoType']);
        $nodes = \is_array($jsonLd['nodes'] ?? null) ? $jsonLd['nodes'] : [];
        $org = $this->firstJsonLdNodeOfTypes($nodes, ['Organization', 'LocalBusiness', 'OnlineStore', 'OnlineBusiness']);

        if ($org !== [] || $seoType === 'home') {
            $sameAs = $org['sameAs'] ?? null;
            $hasSameAs = false;
            if (\is_string($sameAs) && str_starts_with(strtolower(trim($sameAs)), 'http')) {
                $hasSameAs = true;
            } elseif (\is_array($sameAs)) {
                foreach ($sameAs as $entry) {
                    if (\is_string($entry) && str_starts_with(strtolower(trim($entry)), 'http')) {
                        $hasSameAs = true;
                        break;
                    }
                }
            }
            if (!$hasSameAs) {
                $this->addIssue(
                    $issues,
                    'eeat_org_sameas',
                    'warning',
                    'P2',
                    'Helpful Content',
                    'Organization 缺少 sameAs（Trust / 实体示例字段）',
                    $url,
                    ['rule' => 'eeat_org_sameas', 'who_id' => 'trust_org_sameas'],
                    '对照 Google Helpful Content 自测：sameAs 帮助关联可核查实体；非排名门槛。',
                    '在站点 Organization 配置中补充可验证的社交/档案 https URL。',
                    2,
                    $pageIssueIds
                );
            }
        }

        $articleTypes = ['Article', 'BlogPosting', 'NewsArticle'];
        $isArticlePrimary = false;
        foreach ((array)($primary['@type'] ?? []) as $type) {
            if (\in_array((string)$type, $articleTypes, true)) {
                $isArticlePrimary = true;
                break;
            }
        }
        if (!$isArticlePrimary && \in_array($seoType, ['blog', 'news', 'article'], true)) {
            $isArticlePrimary = true;
        }
        if (!$isArticlePrimary) {
            return;
        }

        $author = $primary['author'] ?? null;
        $authors = \is_array($author) && \array_is_list($author) ? $author : ($author !== null ? [$author] : []);
        $hasPerson = false;
        $orgOnly = false;
        $hasIdentityLink = false;
        foreach ($authors as $entry) {
            if (\is_string($entry) && trim($entry) !== '') {
                $hasPerson = true;
                continue;
            }
            if (!\is_array($entry)) {
                continue;
            }
            $types = \array_map('strtolower', \array_map('strval', (array)($entry['@type'] ?? ['Person'])));
            $name = trim((string)($entry['name'] ?? ''));
            if (\in_array('person', $types, true) && $name !== '') {
                $hasPerson = true;
                $personUrl = trim((string)($entry['url'] ?? ''));
                if ($personUrl !== '' && str_starts_with(strtolower($personUrl), 'http')) {
                    $hasIdentityLink = true;
                }
                $sameAs = $entry['sameAs'] ?? null;
                if (\is_string($sameAs) && str_starts_with(strtolower(trim($sameAs)), 'http')) {
                    $hasIdentityLink = true;
                } elseif (\is_array($sameAs)) {
                    foreach ($sameAs as $sameAsUrl) {
                        if (\is_string($sameAsUrl) && str_starts_with(strtolower(trim($sameAsUrl)), 'http')) {
                            $hasIdentityLink = true;
                            break;
                        }
                    }
                }
            }
            $id = (string)($entry['@id'] ?? '');
            if ($id !== '' && $name === '' && !\in_array('person', $types, true)) {
                $orgOnly = true;
            }
            if (\in_array('organization', $types, true) && $name === '') {
                $orgOnly = true;
            }
        }
        if (!$hasPerson) {
            $this->addIssue(
                $issues,
                'eeat_article_author_missing',
                'warning',
                'P2',
                'Helpful Content',
                '文章作者仅为 Organization @id 回退或缺失 Person',
                $url,
                ['rule' => 'eeat_article_author_missing', 'who_id' => 'who_person_author', 'orgOnly' => $orgOnly],
                '对照 Google Helpful Content Who：读者应能识别谁写了内容；非排名门槛。',
                '为文章提供 Person.name，并补充 url 或 sameAs 以便延伸作者背景。',
                2,
                $pageIssueIds
            );
        } elseif (!$hasIdentityLink) {
            $this->addIssue(
                $issues,
                'eeat_article_author_shallow',
                'info',
                'P3',
                'Helpful Content',
                'Person 作者缺 url 或 sameAs（Who 身份链）',
                $url,
                ['rule' => 'eeat_article_author_shallow', 'who_id' => 'who_person_url_or_sameas'],
                    '官方鼓励署名可链到作者背景；jobTitle alone 不足以消歧。内部自测 · 非排名门槛。',
                '为 Person 补充绝对 url 或 sameAs 档案链接。',
                1,
                $pageIssueIds
            );
        }
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, array<string, mixed>> $issues
     * @param list<string> $pageIssueIds
     */
    private function auditSecurity(array $facts, string $origin, array &$issues, array &$pageIssueIds): void
    {
        if (!\str_starts_with($origin, 'https://')) {
            return;
        }
        $url = (string)$facts['url'];
        $mixed = [];
        foreach (['resources', 'images', 'forms'] as $bucket) {
            foreach ((array)($facts[$bucket] ?? []) as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $raw = (string)($item['raw'] ?? '');
                if ($raw !== '' && \str_starts_with($raw, 'http://') && !$this->isIgnoredSeoAuditResource($raw)) {
                    $mixed[] = $raw;
                }
            }
        }
        if ($mixed !== []) {
            $this->addIssue($issues, 'mixed_content', 'error', 'P0', 'Security/HTTPS', 'HTTPS 页面存在 HTTP 资源', $url, ['resources' => \array_slice(\array_values(\array_unique($mixed)), 0, 6)], '混合内容会触发浏览器安全降级，也会损害搜索引擎对页面质量的判断。', '把所有图片、CSS、JS、iframe、表单 action 改为 https 或相对 URL。', 12, $pageIssueIds);
        }

        $insecureInternal = [];
        foreach ((array)($facts['links'] ?? []) as $link) {
            if (!\is_array($link)) {
                continue;
            }
            $href = (string)($link['href'] ?? '');
            if (\str_starts_with($href, 'http://') && $this->sameHost($href, $origin)) {
                $insecureInternal[] = $href;
            }
        }
        if ($insecureInternal !== []) {
            $this->addIssue($issues, 'insecure_internal_links', 'warning', 'P1', 'Security/HTTPS', 'HTTPS 页面存在 HTTP 内链', $url, ['links' => \array_slice(\array_values(\array_unique($insecureInternal)), 0, 6)], 'HTTP 内链会制造协议重复 URL，并可能让爬虫发现错误规范版本。', '把同站内链统一为 https 或相对路径。', 6, $pageIssueIds);
        }
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, array<string, mixed>> $issues
     * @param list<string> $pageIssueIds
     */
    private function auditStaticPerformance(array $facts, string $origin, array &$issues, array &$pageIssueIds, int &$resourceHeadBudget): void
    {
        $url = (string)$facts['url'];
        $unminified = [];
        foreach ((array)($facts['resources'] ?? []) as $resource) {
            if (!\is_array($resource)) {
                continue;
            }
            $type = (string)($resource['type'] ?? '');
            $href = (string)($resource['href'] ?? '');
            if ($this->isIgnoredSeoAuditResource($href)) {
                continue;
            }
            $path = (string)\parse_url($href, PHP_URL_PATH);
            if (($type === 'script' || $type === 'style') && $this->sameOrigin($href, $origin) && !$this->looksMinified($path)) {
                $unminified[] = $href;
            }
            if ($resourceHeadBudget > 0 && ($type === 'script' || $type === 'style') && $this->sameOrigin($href, $origin)) {
                $resourceHeadBudget--;
                $head = $this->fetchResourceHead($href);
                $encoding = \strtolower((string)($head['headers']['content-encoding'] ?? ''));
                $length = (int)($head['headers']['content-length'] ?? 0);
                if (($head['ok'] ?? false) && $encoding === '' && $length > 10240) {
                    $this->addIssue($issues, 'static_compression_missing', 'warning', 'P2', 'Performance Static', '静态资源可能未启用 gzip/brotli', $url, ['resource' => $href, 'bytes' => $length], 'CSS/JS 未压缩会增加传输体积，影响抓取和首屏体验。', '为 JS/CSS 开启 gzip 或 Brotli，并用响应头验证 Content-Encoding。', 5, $pageIssueIds);
                }
            }
        }
        if ($unminified !== []) {
            // DEV 原样静态属预期；生产 !DEV 下 deploy:upgrade / setup:upgrade 会 minify，故不扣分。
            $this->addIssue(
                $issues,
                'unminified_static_assets',
                'notice',
                'P3',
                'Performance Static',
                '检测到未 minify 的 CSS/JS（开发环境常见）',
                $url,
                ['assets' => \array_slice(\array_values(\array_unique($unminified)), 0, 8)],
                '本机/DEV 站通常不压缩静态资源以便调试；这不等于生产未压缩。',
                '无需在开发环境强制 minify。生产（!DEV）执行 deploy:upgrade 或 setup:upgrade 时，Theme 会自动压缩写入 pub/static 的 css/js/mjs（见 Theme/doc/theme-static-minify.md）。',
                0,
                $pageIssueIds,
            );
        }
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, array<string, mixed>> $issues
     * @param list<string> $pageIssueIds
     */
    private function auditMediaAndLinks(array $facts, string $origin, array &$issues, array &$pageIssueIds): void
    {
        $url = (string)$facts['url'];
        $missingAlt = [];
        $missingSize = [];
        foreach ((array)($facts['images'] ?? []) as $image) {
            if (!\is_array($image)) {
                continue;
            }
            $src = (string)($image['src'] ?? '');
            if ($src === '' || $this->isIgnoredSeoAuditResource($src) || $this->isDecorativeOrUiImage($src)) {
                continue;
            }
            if (!(bool)($image['hasAlt'] ?? false)) {
                $missingAlt[] = $src;
            }
            if (!(bool)($image['hasSize'] ?? false)) {
                $missingSize[] = $src;
            }
        }
        if ($missingAlt !== []) {
            $this->addIssue($issues, 'image_alt_missing', 'warning', 'P2', 'Media/Social', '图片缺少 alt', $url, ['images' => \array_slice($missingAlt, 0, 8)], 'alt 帮助图片搜索、可访问性和内容理解。', '为内容图片添加描述性 alt；纯装饰图可显式 alt=""。', 4, $pageIssueIds);
        }
        if ($missingSize !== []) {
            $this->addIssue($issues, 'image_dimensions_missing', 'notice', 'P3', 'Performance Static', '图片缺少 width/height', $url, ['images' => \array_slice($missingSize, 0, 8)], '缺少尺寸会增加布局抖动风险。', '为内容图片输出 width 和 height，或使用稳定 aspect-ratio 容器。', 2, $pageIssueIds);
        }

        $internal = 0;
        $blankWithoutRel = [];
        foreach ((array)($facts['links'] ?? []) as $link) {
            if (!\is_array($link)) {
                continue;
            }
            $href = (string)($link['href'] ?? '');
            if ($href !== '' && $this->sameOrigin($href, $origin)) {
                $internal++;
            }
            if (($link['target'] ?? '') === '_blank' && !\str_contains((string)($link['rel'] ?? ''), 'noopener')) {
                $blankWithoutRel[] = $href;
            }
        }
        if ($internal < 2) {
            $this->addIssue($issues, 'internal_links_low', 'notice', 'P3', 'Links', '内链数量偏少', $url, ['internalLinks' => $internal], '内链帮助搜索引擎发现相关页面，并传递主题上下文。', '添加指向栏目、相关内容、帮助文档或转化路径的描述性内链。', 2, $pageIssueIds);
        }
        if ($blankWithoutRel !== []) {
            $this->addIssue($issues, 'external_blank_noopener_missing', 'notice', 'P3', 'Links', 'target=_blank 链接缺少 noopener', $url, ['links' => \array_slice($blankWithoutRel, 0, 8)], '缺少 noopener 是安全与性能小风险。', '给新窗口链接添加 rel="noopener noreferrer"。', 1, $pageIssueIds);
        }
    }

    /**
     * @param array<string, list<string>> $map
     * @param array<string, array<string, mixed>> $issues
     */
    private function auditDuplicateMeta(array $map, array &$issues, string $id, string $title, string $category, int $deduction): void
    {
        foreach ($map as $value => $urls) {
            $urls = \array_values(\array_unique($urls));
            if ($value === '' || \count($urls) < 2) {
                continue;
            }
            foreach ($urls as $url) {
                $this->addIssue($issues, $id, 'warning', 'P2', $category, $title, $url, ['value' => $value, 'duplicates' => \array_slice($urls, 0, 6)], '重复元数据会让搜索引擎难以区分页面意图，降低 SERP 摘要质量。', '为每个 URL 输出唯一 title/description，贴合页面真实主题。', $deduction);
            }
        }
    }

    /**
     * @param array<string, list<string>> $canonicalMap
     * @param array<string, array<string, mixed>> $issues
     */
    private function auditCanonicalDuplicates(array $canonicalMap, array &$issues): void
    {
        foreach ($canonicalMap as $canonical => $urls) {
            $urls = \array_values(\array_unique($urls));
            if ($canonical === '' || \count($urls) < 2) {
                continue;
            }
            foreach ($urls as $url) {
                if ($url === $canonical) {
                    continue;
                }
                $this->addIssue($issues, 'canonical_shared_by_multiple_urls', 'warning', 'P1', 'Indexability', '多个 sitemap URL 指向同一个 canonical', $url, ['canonical' => $canonical, 'urls' => \array_slice($urls, 0, 8)], '多个可抓取 URL 指向同一 canonical 可能说明 sitemap 中包含重复页面。', '确认这些 URL 是否应合并、301 到 canonical，或从 sitemap 移除重复项。', 6);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $factsByUrl
     * @param array<string, array<string, mixed>> $issues
     */
    private function auditHreflangReciprocal(array $factsByUrl, array &$issues): void
    {
        foreach ($factsByUrl as $url => $facts) {
            $sourceLang = \strtolower(\str_replace('_', '-', (string)($facts['htmlLang'] ?? '')));
            if ($sourceLang === '') {
                continue;
            }
            foreach ((array)($facts['hreflang'] ?? []) as $alternate) {
                if (!\is_array($alternate)) {
                    continue;
                }
                $targetUrl = (string)($alternate['href'] ?? '');
                $targetFacts = $factsByUrl[$targetUrl] ?? null;
                if (!\is_array($targetFacts)) {
                    continue;
                }
                $hasBack = false;
                foreach ((array)($targetFacts['hreflang'] ?? []) as $back) {
                    if (!\is_array($back)) {
                        continue;
                    }
                    $backCode = \strtolower(\str_replace('_', '-', (string)($back['code'] ?? '')));
                    $backHref = (string)($back['href'] ?? '');
                    if ($backCode === $sourceLang && $this->sameUrl($backHref, (string)($facts['canonicalUrl'] ?: $url))) {
                        $hasBack = true;
                        break;
                    }
                }
                if (!$hasBack) {
                    $this->addIssue($issues, 'hreflang_reciprocal_missing', 'warning', 'P1', 'International SEO', 'hreflang 缺少互链返回', (string)$targetFacts['url'], ['source' => $url, 'target' => $targetUrl, 'expectedCode' => $sourceLang], 'hreflang 需要互相声明，单向 alternate 常被搜索引擎忽略。', '让每个语言版本都输出完整 hreflang 组，并包含返回源页面的语言链接。', 7);
                }
            }
        }
    }

    /**
     * @param list<string> $messages
     * @param array<string, array<string, mixed>> $issues
     */
    private function auditSitemapMessages(array $messages, string $sitemapUrl, array &$issues): void
    {
        foreach ($messages as $message) {
            if (\str_contains($message, '重复 URL')) {
                $this->addIssue($issues, 'sitemap_duplicate_urls', 'warning', 'P2', 'Sitemap Quality', 'sitemap 存在重复 URL', '', ['message' => $message], '重复 URL 会浪费抓取预算，也让审计样本重复。', '清理 sitemap 生成逻辑中的重复 URL。', 4);
            } elseif (\str_contains($message, '非同源')) {
                $this->addIssue($issues, 'sitemap_cross_origin_urls', 'warning', 'P2', 'Sitemap Quality', 'sitemap 包含非同源 URL', '', ['message' => $message, 'sitemapUrl' => $sitemapUrl], '当前审计只扫描同源 URL；跨源 URL 也可能不是本站 sitemap 应包含的内容。', '拆分不同域名的 sitemap，或确认跨域 canonical/hreflang 策略。', 4);
            } elseif (\str_contains($message, 'XML 解析失败')) {
                $this->addIssue($issues, 'sitemap_xml_invalid', 'error', 'P1', 'Sitemap Quality', 'sitemap XML 格式错误', '', ['message' => $message, 'sitemapUrl' => $sitemapUrl], 'XML 解析失败会让搜索引擎无法读取 sitemap。', '修复 XML 转义、命名空间和响应内容类型。', 10);
            } elseif ($this->isTlsVerificationError($message)) {
                $this->addIssue($issues, 'sitemap_https_certificate_untrusted', 'error', 'P0', 'Crawlability', 'sitemap HTTPS 证书不被审计器信任', '', ['message' => $message, 'sitemapUrl' => $sitemapUrl], '搜索引擎和服务端抓取器需要能验证 sitemap 的 HTTPS 证书链，否则 sitemap 会被视为不可读取。', '为站点配置受信任的 HTTPS 证书链；本地开发域名（*.test.weline.com / *.test / localhost）可继续使用 Weline 本地证书豁免。', 16);
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, array<string, mixed>> $issues
     * @param list<string>|null $pageIssueIds
     */
    private function addIssue(
        array &$issues,
        string $id,
        string $severity,
        string $priority,
        string $category,
        string $title,
        string $url,
        array $evidence,
        string $whyItMatters,
        string $howToFix,
        int $deduction,
        ?array &$pageIssueIds = null
    ): void {
        if (!isset($issues[$id])) {
            $issues[$id] = [
                'id' => $id,
                'severity' => $severity,
                'priority' => $priority,
                'category' => $category,
                'title' => $title,
                'affectedCount' => 0,
                'affectedUrls' => [],
                'evidence' => [],
                'whyItMatters' => $whyItMatters,
                'howToFix' => $howToFix,
                'deduction' => $deduction,
                '_affectedMap' => [],
                '_imagesByPage' => [],
            ];
        }
        if ($url !== '' && !isset($issues[$id]['_affectedMap'][$url])) {
            $issues[$id]['_affectedMap'][$url] = true;
            $issues[$id]['affectedCount']++;
            // Keep enough URLs for the panel to list every audited hit (discovery ≤ DISCOVERY_LIMIT).
            if (\count($issues[$id]['affectedUrls']) < self::DISCOVERY_LIMIT) {
                $issues[$id]['affectedUrls'][] = $url;
            }
        }
        if ($url !== '') {
            foreach ($this->extractEvidenceImages($evidence) as $imageUrl) {
                $issues[$id]['_imagesByPage'][$url][$imageUrl] = true;
            }
        }
        if ($evidence !== [] && \count($issues[$id]['evidence']) < 12) {
            $issues[$id]['evidence'][] = $evidence + ($url !== '' ? ['url' => $url] : []);
        }
        if ($pageIssueIds !== null) {
            $pageIssueIds[] = $id;
        }
    }

    /**
     * @param array<string, mixed> $evidence
     * @return list<string>
     */
    private function extractEvidenceImages(array $evidence): array
    {
        $images = [];
        foreach (['images', 'assets'] as $key) {
            if (!\is_array($evidence[$key] ?? null)) {
                continue;
            }
            foreach ($evidence[$key] as $item) {
                if (\is_string($item) && $item !== '' && $this->looksLikeImageUrl($item)) {
                    $images[] = $item;
                }
            }
        }
        foreach (['resource', 'image', 'src'] as $key) {
            $value = \trim((string)($evidence[$key] ?? ''));
            if ($value !== '' && $this->looksLikeImageUrl($value)) {
                $images[] = $value;
            }
        }

        return \array_values(\array_unique($images));
    }

    private function looksLikeImageUrl(string $url): bool
    {
        $path = \strtolower((string)(\parse_url($url, PHP_URL_PATH) ?: $url));
        if (\str_contains($path, '/pub/media/') || \str_contains($path, '/media/')) {
            return true;
        }

        return (bool)\preg_match('/\.(?:webp|avif|jpe?g|png|gif|svg|ico|bmp|tiff?)$/D', $path);
    }

    /**
     * @param array<string, array<string, mixed>> $issues
     * @return list<array<string, mixed>>
     */
    private function finalizeIssues(array $issues): array
    {
        $list = \array_values($issues);
        foreach ($list as &$issue) {
            $imagesByPage = [];
            if (\is_array($issue['_imagesByPage'] ?? null)) {
                foreach ($issue['_imagesByPage'] as $pageUrl => $imageMap) {
                    if (!\is_string($pageUrl) || $pageUrl === '' || !\is_array($imageMap)) {
                        continue;
                    }
                    $imagesByPage[$pageUrl] = \array_keys($imageMap);
                }
            }
            unset($issue['_affectedMap'], $issue['_imagesByPage']);
            $issue['affectedCount'] = (int)($issue['affectedCount'] ?? \count($issue['affectedUrls'] ?? []));
            if ($issue['affectedCount'] === 0 && ($issue['evidence'] ?? []) !== []) {
                $issue['affectedCount'] = 1;
            }
            $issue['affectedGroups'] = $this->buildDiscoveryGroups($issue, $imagesByPage);
        }
        unset($issue);

        \usort($list, function (array $a, array $b): int {
            $severity = ['error' => 0, 'warning' => 1, 'notice' => 2];
            $priority = ['P0' => 0, 'P1' => 1, 'P2' => 2, 'P3' => 3];
            $left = ($severity[$a['severity'] ?? 'notice'] ?? 9) <=> ($severity[$b['severity'] ?? 'notice'] ?? 9);
            if ($left !== 0) {
                return $left;
            }
            $left = ($priority[$a['priority'] ?? 'P3'] ?? 9) <=> ($priority[$b['priority'] ?? 'P3'] ?? 9);
            if ($left !== 0) {
                return $left;
            }

            return (int)($b['deduction'] ?? 0) <=> (int)($a['deduction'] ?? 0);
        });

        return $list;
    }

    /**
     * Group issue hits by the page URL where they were discovered; attach related images under that page.
     *
     * @param array<string, mixed> $issue
     * @param array<string, list<string>> $imagesByPage
     * @return list<array<string, mixed>>
     */
    private function buildDiscoveryGroups(array $issue, array $imagesByPage = []): array
    {
        $sampler = new SitemapAuditUrlSampler();
        if ($imagesByPage === []) {
            foreach (\is_array($issue['evidence'] ?? null) ? $issue['evidence'] : [] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $pageUrl = \trim((string)($row['url'] ?? ''));
                if ($pageUrl === '') {
                    continue;
                }
                foreach ($this->extractEvidenceImages($row) as $image) {
                    $imagesByPage[$pageUrl][] = $image;
                }
            }
            foreach ($imagesByPage as $pageUrl => $images) {
                $imagesByPage[$pageUrl] = \array_values(\array_unique($images));
            }
        }

        $pageUrls = \array_values(\array_filter(
            \is_array($issue['affectedUrls'] ?? null) ? $issue['affectedUrls'] : [],
            static fn($u): bool => \is_string($u) && $u !== ''
        ));
        foreach (\array_keys($imagesByPage) as $pageUrl) {
            if (!\in_array($pageUrl, $pageUrls, true)) {
                $pageUrls[] = $pageUrl;
            }
        }

        $groups = [];
        foreach ($pageUrls as $pageUrl) {
            if ($this->isNonHtmlAssetUrl($pageUrl)) {
                continue;
            }
            $meta = $sampler->classify($pageUrl);
            $images = \array_values($imagesByPage[$pageUrl] ?? []);
            $path = (string)(\parse_url($pageUrl, PHP_URL_PATH) ?: '/');
            $groups[] = [
                'key' => 'page:' . $pageUrl,
                'label' => (string)$meta['label'],
                'parentPath' => $path,
                'parentUrl' => $pageUrl,
                'kind' => 'page',
                'structure' => (string)($meta['kind'] === 'structure' ? $meta['parentPath'] . '/*' : $meta['parentPath']),
                'count' => 1,
                'imageCount' => \count($images),
                'urls' => [$pageUrl],
                'images' => $images,
            ];
        }

        \usort($groups, static function (array $a, array $b): int {
            $byImages = (int)($b['imageCount'] ?? 0) <=> (int)($a['imageCount'] ?? 0);
            if ($byImages !== 0) {
                return $byImages;
            }

            return \strcmp((string)($a['parentPath'] ?? ''), (string)($b['parentPath'] ?? ''));
        });

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function health(array $issues): array
    {
        $deductions = [];
        $errors = 0;
        $warnings = 0;
        $notices = 0;
        $totalDeduction = 0;
        foreach ($issues as $issue) {
            $severity = (string)($issue['severity'] ?? 'notice');
            if ($severity === 'error') {
                $errors++;
            } elseif ($severity === 'warning') {
                $warnings++;
            } else {
                $notices++;
            }
            $points = \min(24, \max(0, (int)($issue['deduction'] ?? 0) + \max(0, (int)($issue['affectedCount'] ?? 1) - 1)));
            if ($points > 0) {
                $totalDeduction += $points;
                $affectedUrls = \array_values(\array_filter(
                    \is_array($issue['affectedUrls'] ?? null) ? $issue['affectedUrls'] : [],
                    static fn($u): bool => \is_string($u) && $u !== ''
                ));
                $affectedGroups = \is_array($issue['affectedGroups'] ?? null)
                    ? $issue['affectedGroups']
                    : $this->buildDiscoveryGroups($issue);
                $deductions[] = [
                    'issueId' => $issue['id'],
                    'points' => $points,
                    'severity' => $severity,
                    'title' => $issue['title'],
                    'affectedCount' => $issue['affectedCount'],
                    'affectedGroups' => $affectedGroups,
                    'affectedUrls' => $affectedUrls,
                ];
            }
        }

        return [
            'score' => \max(0, 100 - \min(100, $totalDeduction)),
            'errors' => $errors,
            'warnings' => $warnings,
            'notices' => $notices,
            'deductions' => $deductions,
        ];
    }

    /**
     * @param list<string> $pageIssueIds
     * @param array<string, array<string, mixed>> $issues
     */
    private function pageScore(array $pageIssueIds, array $issues): int
    {
        $points = 0;
        foreach (\array_unique($pageIssueIds) as $id) {
            $points += (int)($issues[$id]['deduction'] ?? 0);
        }

        return \max(0, 100 - \min(100, $points));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUrl(string $url, int $timeout, string $method): array
    {
        if (!\preg_match('/^https?:\/\//i', $url)) {
            return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'error' => 'invalid url', 'finalUrl' => $url, 'durationMs' => 0];
        }

        return \function_exists('curl_init')
            ? $this->fetchByCurl($url, $timeout, $method)
            : $this->fetchByStream($url, $timeout, $method);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchByCurl(string $url, int $timeout, string $method): array
    {
        $started = \microtime(true);
        $ch = \curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'error' => 'curl init failed', 'finalUrl' => $url, 'durationMs' => 0];
        }
        $relaxTls = $this->shouldRelaxTlsVerification($url);
        $headers = [];
        \curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'WelineSeoAudit/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.5'],
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$headers): int {
                $length = \strlen($line);
                $line = \trim($line);
                if ($line !== '' && \str_contains($line, ':')) {
                    [$name, $value] = \explode(':', $line, 2);
                    $headers[\strtolower(\trim($name))] = \trim($value);
                }

                return $length;
            },
        ]);
        if ($relaxTls) {
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $this->rememberRelaxedTlsHost($url);
        }
        if (\strtoupper($method) === 'HEAD') {
            \curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        $body = \curl_exec($ch);
        $status = (int)\curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)\curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = \curl_error($ch);
        \curl_close($ch);

        return [
            'ok' => $error === '' && $status >= 200 && $status < 400,
            'status' => $status,
            'headers' => $headers,
            'body' => \is_string($body) ? $body : '',
            'error' => $error,
            'finalUrl' => $finalUrl !== '' ? $finalUrl : $url,
            'durationMs' => (int)\round((\microtime(true) - $started) * 1000),
            'tlsVerification' => $relaxTls ? 'relaxed_for_local_development' : 'strict',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchByStream(string $url, int $timeout, string $method): array
    {
        $started = \microtime(true);
        $relaxTls = $this->shouldRelaxTlsVerification($url);
        if ($relaxTls) {
            $this->rememberRelaxedTlsHost($url);
        }
        $context = \stream_context_create([
            'http' => [
                'method' => \strtoupper($method) === 'HEAD' ? 'HEAD' : 'GET',
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 5,
                'ignore_errors' => true,
                'header' => "User-Agent: WelineSeoAudit/1.0\r\nAccept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.5\r\n",
            ],
            'ssl' => [
                'verify_peer' => !$relaxTls,
                'verify_peer_name' => !$relaxTls,
                'allow_self_signed' => $relaxTls,
            ],
        ]);
        $body = @\file_get_contents($url, false, $context);
        $rawHeaders = $http_response_header ?? [];
        $headers = [];
        $status = 0;
        foreach ($rawHeaders as $line) {
            if (\preg_match('/^HTTP\/\S+\s+(\d+)/i', $line, $match)) {
                $status = (int)$match[1];
                continue;
            }
            if (\str_contains($line, ':')) {
                [$name, $value] = \explode(':', $line, 2);
                $headers[\strtolower(\trim($name))] = \trim($value);
            }
        }

        return [
            'ok' => \is_string($body) && $status >= 200 && $status < 400,
            'status' => $status,
            'headers' => $headers,
            'body' => \is_string($body) ? $body : '',
            'error' => \is_string($body) ? '' : 'failed to fetch',
            'finalUrl' => $url,
            'durationMs' => (int)\round((\microtime(true) - $started) * 1000),
            'tlsVerification' => $relaxTls ? 'relaxed_for_local_development' : 'strict',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchResourceHead(string $url): array
    {
        if (isset($this->resourceHeadCache[$url])) {
            return $this->resourceHeadCache[$url];
        }
        $this->resourceHeadCache[$url] = $this->fetchUrl($url, 3, 'HEAD');

        return $this->resourceHeadCache[$url];
    }

    private function shouldRelaxTlsVerification(string $url): bool
    {
        return LocalDevelopmentHostPolicy::shouldRelaxHttpsTls($url);
    }

    private function rememberRelaxedTlsHost(string $url): void
    {
        $host = \strtolower(\trim((string)\parse_url($url, PHP_URL_HOST), '[]'));
        if ($host !== '') {
            $this->relaxedTlsHosts[$host] = true;
        }
    }

    private function isTlsVerificationError(string $error): bool
    {
        $error = \strtolower($error);
        if ($error === '') {
            return false;
        }

        return \str_contains($error, 'ssl certificate')
            || \str_contains($error, 'self-signed certificate')
            || \str_contains($error, 'certificate verify')
            || \str_contains($error, 'unable to get local issuer certificate')
            || \str_contains($error, 'unable to verify the first certificate');
    }

    private function text(\DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)?->item(0);

        return $node ? \trim((string)$node->textContent) : '';
    }

    private function attr(\DOMXPath $xpath, string $query, string $attr): string
    {
        $node = $xpath->query($query)?->item(0);
        if (!$node instanceof \DOMElement) {
            return '';
        }

        return \trim((string)$node->getAttribute($attr));
    }

    private function meta(\DOMXPath $xpath, string $name): string
    {
        return $this->attr($xpath, '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . \strtolower($name) . '"]', 'content');
    }

    private function metaProperty(\DOMXPath $xpath, string $property): string
    {
        return $this->attr($xpath, '//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . \strtolower($property) . '"]', 'content');
    }

    /**
     * @return list<array{tag: string, text: string}>
     */
    private function headings(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
        $result = [];
        foreach ($nodes ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $result[] = [
                'tag' => \strtolower($node->tagName),
                'text' => \trim(\preg_replace('/\s+/', ' ', (string)$node->textContent) ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function images(\DOMXPath $xpath, string $origin): array
    {
        $result = [];
        foreach ($xpath->query('//img') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $raw = \trim($node->getAttribute('src'));
            $result[] = [
                'raw' => $raw,
                'src' => $this->normalizeUrl($raw, $origin),
                'hasAlt' => $node->hasAttribute('alt'),
                'hasSize' => $node->getAttribute('width') !== '' && $node->getAttribute('height') !== '',
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    private function resources(\DOMXPath $xpath, string $origin): array
    {
        $result = [];
        foreach ($xpath->query('//script[@src]') ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $raw = \trim($node->getAttribute('src'));
                $result[] = ['type' => 'script', 'raw' => $raw, 'href' => $this->normalizeUrl($raw, $origin)];
            }
        }
        foreach ($xpath->query('//link[contains(concat(" ", normalize-space(@rel), " "), " stylesheet ")][@href]') ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $raw = \trim($node->getAttribute('href'));
                $result[] = ['type' => 'style', 'raw' => $raw, 'href' => $this->normalizeUrl($raw, $origin)];
            }
        }

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    private function links(\DOMXPath $xpath, string $origin): array
    {
        $result = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $raw = \trim($node->getAttribute('href'));
            if ($raw === '' || \str_starts_with($raw, '#') || \str_starts_with(\strtolower($raw), 'javascript:')) {
                continue;
            }
            $result[] = [
                'raw' => $raw,
                'href' => $this->normalizeUrl($raw, $origin),
                'text' => \trim(\preg_replace('/\s+/', ' ', (string)$node->textContent) ?? ''),
                'target' => \strtolower($node->getAttribute('target')),
                'rel' => \strtolower($node->getAttribute('rel')),
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    private function forms(\DOMXPath $xpath, string $origin): array
    {
        $result = [];
        foreach ($xpath->query('//form[@action]') ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $raw = \trim($node->getAttribute('action'));
                $result[] = ['raw' => $raw, 'action' => $this->normalizeUrl($raw, $origin)];
            }
        }

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    private function hreflang(\DOMXPath $xpath, string $origin): array
    {
        $result = [];
        foreach ($xpath->query('//link[contains(concat(" ", normalize-space(@rel), " "), " alternate ")][@hreflang]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $raw = \trim($node->getAttribute('href'));
            $result[] = [
                'code' => \trim($node->getAttribute('hreflang')),
                'raw' => $raw,
                'href' => $this->normalizeUrl($raw, $origin),
            ];
        }

        return $result;
    }

    /**
     * Compact JSON-LD payload for panel local rich-result testing (no full HTML).
     *
     * @param array<string, mixed> $jsonLd
     * @return array{types: list<string>, errors: list<string>, nodes: list<array<string, mixed>>}
     */
    private function jsonLdReportPayload(array $jsonLd): array
    {
        $nodes = [];
        foreach (\array_values(\is_array($jsonLd['nodes'] ?? null) ? $jsonLd['nodes'] : []) as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $nodes[] = $node;
            if (\count($nodes) >= 24) {
                break;
            }
        }

        return [
            'types' => \array_values(\array_unique(\array_map(
                static fn($type): string => \trim((string)$type),
                \is_array($jsonLd['types'] ?? null) ? $jsonLd['types'] : []
            ))),
            'errors' => \array_values(\array_map(
                static fn($error): string => (string)$error,
                \array_slice(\is_array($jsonLd['errors'] ?? null) ? $jsonLd['errors'] : [], 0, 8)
            )),
            'nodes' => $nodes,
        ];
    }

    /**
     * @return array{types: list<string>, nodes: list<array<string, mixed>>, errors: list<string>}
     */
    private function jsonLd(\DOMXPath $xpath): array
    {
        $nodes = [];
        $types = [];
        $errors = [];
        foreach ($xpath->query('//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ld+json")]') ?: [] as $script) {
            $raw = \trim((string)$script->textContent);
            if ($raw === '') {
                continue;
            }
            $decoded = \json_decode($raw, true);
            if (!\is_array($decoded)) {
                $errors[] = \json_last_error_msg();
                continue;
            }
            foreach ($this->flattenJsonLdNodes($decoded) as $node) {
                $nodes[] = $node;
                foreach ((array)($node['@type'] ?? []) as $type) {
                    if (\is_scalar($type) && \trim((string)$type) !== '') {
                        $types[] = \trim((string)$type);
                    }
                }
            }
        }

        return [
            'types' => \array_values(\array_unique($types)),
            'nodes' => $nodes,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private function flattenJsonLdNodes(array $decoded): array
    {
        $result = [];
        $items = isset($decoded[0]) && \is_array($decoded[0]) ? $decoded : [$decoded];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            if (isset($item['@graph']) && \is_array($item['@graph'])) {
                foreach ($item['@graph'] as $graphNode) {
                    if (\is_array($graphNode)) {
                        $result[] = $graphNode;
                    }
                }
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }

    private function visibleText(\DOMDocument $doc): string
    {
        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//script|//style|//noscript|//template') ?: [] as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }

        return \trim(\preg_replace('/\s+/', ' ', (string)$doc->textContent) ?? '');
    }

    private function wordCount(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        if (\preg_match_all('/[\p{Han}]|[A-Za-z0-9]+(?:[-\'][A-Za-z0-9]+)?/u', $text, $matches) === false) {
            return \str_word_count($text);
        }

        return \count($matches[0]);
    }

    /**
     * @param list<string> $types
     */
    private function inferSeoType(string $pageTypeMeta, string $ogType, string $url, array $types): string
    {
        $value = \strtolower(\trim($pageTypeMeta));
        if ($value !== '') {
            return $value;
        }
        $ogType = \strtolower($ogType);
        if (\str_contains($ogType, 'article')) {
            return 'article';
        }
        foreach ($types as $type) {
            $type = \strtolower((string)$type);
            if (\str_contains($type, 'newsarticle')) {
                return 'news';
            }
            if (\str_contains($type, 'blogposting')) {
                return 'blog';
            }
            if (\str_contains($type, 'product')) {
                return 'product';
            }
            if (\str_contains($type, 'faqpage')) {
                return 'faq';
            }
        }
        $path = \strtolower((string)\parse_url($url, PHP_URL_PATH));
        if ($path === '' || $path === '/') {
            return 'home';
        }
        if (\str_contains($path, 'news')) {
            return 'news';
        }
        if (\str_contains($path, 'blog')) {
            return 'blog';
        }
        if (\str_contains($path, 'product')) {
            return 'product';
        }

        return 'page';
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredDataRule(string $seoType, string $url): array
    {
        $seoType = \strtolower($seoType);
        $isHome = ((string)\parse_url($url, PHP_URL_PATH) === '' || (string)\parse_url($url, PHP_URL_PATH) === '/');
        $rules = [
            'news' => ['types' => ['NewsArticle'], 'required' => ['headline', 'datePublished', 'author', 'publisher', 'image', 'mainEntityOfPage']],
            'blog' => ['types' => ['BlogPosting'], 'required' => ['headline', 'datePublished', 'author', 'publisher', 'image', 'mainEntityOfPage']],
            'article' => ['types' => ['Article', 'BlogPosting', 'NewsArticle'], 'required' => ['headline', 'datePublished', 'author', 'publisher', 'mainEntityOfPage']],
            'product' => ['types' => ['Product', 'ProductGroup'], 'required' => ['name', 'image', 'description']],
            'faq' => ['types' => ['FAQPage'], 'required' => ['mainEntity']],
            'home' => ['types' => ['WebSite', 'Organization'], 'required' => ['name', 'url']],
        ];

        return $rules[$seoType] ?? ($isHome ? $rules['home'] : []);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<string> $types
     * @return array<string, mixed>
     */
    private function firstJsonLdNodeOfTypes(array $nodes, array $types): array
    {
        $wanted = \array_map('strtolower', $types);
        foreach ($nodes as $node) {
            $nodeTypes = \array_map('strtolower', \array_map('strval', (array)($node['@type'] ?? [])));
            if (\array_intersect($nodeTypes, $wanted) !== []) {
                return $node;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function jsonLdHasField(array $node, string $field): bool
    {
        if (!\array_key_exists($field, $node)) {
            return false;
        }
        $value = $node[$field];
        if (\is_array($value)) {
            return $value !== [];
        }
        if (\is_scalar($value)) {
            return \trim((string)$value) !== '';
        }

        return $value !== null;
    }

    private function normalizeUrl(string $url, string $base): string
    {
        $url = \trim(\html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if ($url === '') {
            return '';
        }
        if (\str_starts_with($url, '//')) {
            $scheme = \parse_url($base, PHP_URL_SCHEME) ?: 'https';
            $url = $scheme . ':' . $url;
        } elseif (\str_starts_with($url, '/')) {
            $url = \rtrim($this->origin($base), '/') . $url;
        } elseif (!\preg_match('/^https?:\/\//i', $url)) {
            $origin = $this->origin($base);
            $path = (string)\parse_url($base, PHP_URL_PATH);
            $dir = $path !== '' ? \rtrim(\dirname($path), '/\\') : '';
            $url = \rtrim($origin, '/') . ($dir !== '' && $dir !== '.' ? '/' . \trim($dir, '/') : '') . '/' . $url;
        }
        if (!\preg_match('/^https?:\/\//i', $url)) {
            return '';
        }

        $parts = \parse_url($url);
        if (!\is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = \strtolower((string)($parts['scheme'] ?? 'http'));
        $host = \strtolower((string)$parts['host']);
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = (string)($parts['path'] ?? '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $this->normalizePath($path) . $query;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (\explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                \array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . \implode('/', $segments);
    }

    private function origin(string $url): string
    {
        $parts = \parse_url($url);
        if (!\is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = \strtolower((string)($parts['scheme'] ?? 'http'));
        $host = \strtolower((string)$parts['host']);
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private function sameOrigin(string $url, string $origin): bool
    {
        return $this->origin($url) !== '' && \strcasecmp($this->origin($url), $this->origin($origin)) === 0;
    }

    private function sameHost(string $url, string $origin): bool
    {
        $left = (string)\parse_url($url, PHP_URL_HOST);
        $right = (string)\parse_url($origin, PHP_URL_HOST);

        return $left !== '' && $right !== '' && \strcasecmp($left, $right) === 0;
    }

    private function sameUrl(string $left, string $right): bool
    {
        return \rtrim($this->normalizeUrl($left, $right), '/') === \rtrim($this->normalizeUrl($right, $left), '/');
    }

    private function validLanguageCode(string $code): bool
    {
        $code = \trim($code);
        if ($code === '') {
            return false;
        }
        if (\strtolower($code) === 'x-default') {
            return true;
        }
        if (\str_contains($code, '_')) {
            return false;
        }

        return \preg_match('/^[a-z]{2}(?:-[a-z]{4})?(?:-(?:[A-Z]{2}|\d{3}|[a-z0-9]{5,8}))*$/i', $code) === 1;
    }

    private function isIgnoredSeoAuditResource(string $url): bool
    {
        $value = \strtolower($url);
        foreach ([
            '/dev-tool-panel',
            '/seo-inspector/',
            '/weline-panel',
            '/panel-token',
            '/dev/tool/',
            'browser_pass=',
            'codex',
            'hot-update',
            // Platform runtime assets (module statics) are not merchant page SEO debt.
            '/weline/frontend/',
            '/weline/framework/',
            '/weline/theme/',
            '/weline/seo/',
            '/weline/developerworkspace/',
            '/weline/i18n/',
            '/assets/seo-inspector/',
        ] as $ignored) {
            if (\str_contains($value, $ignored)) {
                return true;
            }
        }

        return false;
    }

    private function isDecorativeOrUiImage(string $url): bool
    {
        $path = \strtolower((string)(\parse_url($url, PHP_URL_PATH) ?: $url));
        foreach ([
            '/icon',
            '/icons/',
            '/sprite',
            '/logo',
            '/favicon',
            '/emoji',
            '/avatar',
            '/badge',
            'data:image/',
            '.svg',
        ] as $token) {
            if (\str_contains($path, $token)) {
                return true;
            }
        }

        // Prefer flagging merchant media; skip generic theme/framework chrome images.
        if (\str_contains($path, '/weline/') && !\str_contains($path, '/pub/media/')) {
            return true;
        }

        return false;
    }

    private function looksMinified(string $path): bool
    {
        $path = \strtolower($path);
        if (!\preg_match('/\.(js|css)$/', $path)) {
            return true;
        }
        if (\str_contains($path, '.min.') || \preg_match('/[-.][a-f0-9]{8,}\.(js|css)$/', $path) === 1) {
            return true;
        }
        // Versioned module static URLs are shipped by the platform pipeline.
        if (\str_contains($path, '?v=') || \preg_match('/[?&]v=\d/', $path) === 1) {
            return true;
        }
        if ($this->isIgnoredSeoAuditResource($path) || \str_contains($path, '/debug/')) {
            return true;
        }

        return false;
    }

    private function compareKey(string $value): string
    {
        return \mb_strtolower(\trim(\preg_replace('/\s+/', ' ', $value) ?? ''));
    }

    /**
     * @param array<string, list<string>> $map
     */
    private function appendMap(array &$map, string $key, string $url): void
    {
        if ($key === '') {
            return;
        }
        $map[$key] ??= [];
        $map[$key][] = $url;
    }

    private function slug(string $value): string
    {
        $slug = \preg_replace('/[^a-z0-9]+/i', '_', $value);

        return \trim((string)$slug, '_') ?: 'field';
    }

    private function websiteDirectory(): SeoWebsiteDirectory
    {
        if (!$this->websiteDirectory) {
            $this->websiteDirectory = ObjectManager::getInstance(SeoWebsiteDirectory::class);
        }

        return $this->websiteDirectory;
    }

    private function sitemapUrlModel(): SitemapUrl
    {
        if (!$this->sitemapUrlModel) {
            $this->sitemapUrlModel = ObjectManager::getInstance(SitemapUrl::class);
        }

        return $this->sitemapUrlModel;
    }
}
