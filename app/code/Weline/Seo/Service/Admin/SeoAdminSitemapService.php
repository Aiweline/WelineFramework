<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Admin;

use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Service\SeoWebsiteDirectory;
use Weline\Seo\Service\Sitemap\AtomicSitemapPublisher;
use Weline\Seo\Service\SitemapRegistryService;
use Weline\Seo\Service\SitemapUrlSyncService;
use Weline\Seo\Service\WebSitemapData;

final class SeoAdminSitemapService
{
    private const URL_CHANGEFREQ_OPTIONS = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    public function __construct(
        private readonly SeoWebsiteDirectory $websiteDirectory,
        private readonly SitemapRegistryService $registry,
        private readonly SitemapUrlSyncService $sync,
        private readonly WebSitemapData $sitemaps,
        private readonly SitemapUrl $sitemapUrl,
    ) {
    }

    /**
     * Paginated sitemap URL read model for the backend URL manager.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function listSitemapUrls(array $params): array
    {
        if (!array_key_exists('website_id', $params)) {
            return ['success' => false, 'message' => (string)__('缺少 website_id 参数')];
        }
        $websiteId = (int)$params['website_id'];
        if ($websiteId < 0) {
            return ['success' => false, 'message' => (string)__('website_id 非法')];
        }

        $module = trim((string)($params['module'] ?? ''));
        $locale = trim((string)($params['locale'] ?? ''));
        $statusFilter = trim((string)($params['status'] ?? ''));
        $keyword = trim((string)($params['keyword'] ?? ''));
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 20)));

        $query = $this->sitemapUrl->reset()
            ->where(SitemapUrl::schema_fields_WEBSITE_ID, $websiteId);
        if ($module !== '') {
            $query->where(SitemapUrl::schema_fields_MODULE, $module);
        }
        if ($locale !== '') {
            $query->where(SitemapUrl::schema_fields_LOCALE, $locale === '__default__' ? '' : $locale);
        }
        if ($statusFilter === '0' || $statusFilter === '1') {
            $query->where(SitemapUrl::schema_fields_STATUS, (int)$statusFilter);
        }
        if ($keyword !== '') {
            $query->where(SitemapUrl::schema_fields_URL, 'like', '%' . $keyword . '%');
        }
        $query->order(SitemapUrl::schema_fields_MODULE, 'ASC')
            ->order(SitemapUrl::schema_fields_LOCALE, 'ASC')
            ->order(SitemapUrl::schema_fields_ID, 'ASC')
            ->pagination($page, $pageSize)
            ->select();
        $rows = $query->fetchArray();
        $pagination = $query->pagination;
        $total = (int)($pagination['totalSize'] ?? count($rows));

        $items = array_map(static fn (array $row): array => [
            'url_id' => (int)($row[SitemapUrl::schema_fields_ID] ?? 0),
            'module' => (string)($row[SitemapUrl::schema_fields_MODULE] ?? ''),
            'scope' => (string)($row[SitemapUrl::schema_fields_SCOPE] ?? ''),
            'locale' => (string)($row[SitemapUrl::schema_fields_LOCALE] ?? ''),
            'url' => (string)($row[SitemapUrl::schema_fields_URL] ?? ''),
            'changefreq' => (string)($row[SitemapUrl::schema_fields_CHANGEFREQ] ?? 'weekly'),
            'priority' => (string)($row[SitemapUrl::schema_fields_PRIORITY] ?? '0.5'),
            'lastmod' => (string)($row[SitemapUrl::schema_fields_LASTMOD] ?? ''),
            'status' => (int)($row[SitemapUrl::schema_fields_STATUS] ?? 0),
            'entity_type' => (string)($row[SitemapUrl::schema_fields_ENTITY_TYPE] ?? ''),
            'updated_at' => (string)($row[SitemapUrl::schema_fields_UPDATED_AT] ?? ''),
        ], $rows);

        $website = $this->websiteDirectory->getWebsiteById($websiteId) ?? [];
        $publicBaseUrl = rtrim($this->websiteDirectory->effectivePublicBaseUrl($website), '/');
        if ($publicBaseUrl !== '') {
            foreach ($items as &$item) {
                $item['url'] = $this->websiteDirectory->rewriteLoopbackPublicUrl(
                    (string)($item['url'] ?? ''),
                    $publicBaseUrl,
                );
            }
            unset($item);
        }

        // Distinct module/locale values for filter dropdowns (site-scoped).
        $facetRows = $this->sitemapUrl->reset()
            ->fields(implode(',', [SitemapUrl::schema_fields_MODULE, SitemapUrl::schema_fields_LOCALE]))
            ->where(SitemapUrl::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetchArray();
        $modules = [];
        $locales = [];
        foreach ($facetRows as $row) {
            $rowModule = trim((string)($row[SitemapUrl::schema_fields_MODULE] ?? ''));
            if ($rowModule !== '') {
                $modules[$rowModule] = true;
            }
            $locales[trim((string)($row[SitemapUrl::schema_fields_LOCALE] ?? ''))] = true;
        }
        ksort($modules);
        ksort($locales);

        return [
            'success' => true,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'modules' => array_keys($modules),
            'locales' => array_keys($locales),
            'changefreq_options' => self::URL_CHANGEFREQ_OPTIONS,
        ];
    }

    /**
     * Update editable fields (status/changefreq/priority) of one sitemap URL.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function updateSitemapUrl(array $params): array
    {
        $urlId = (int)($params['url_id'] ?? 0);
        if ($urlId <= 0) {
            return ['success' => false, 'message' => (string)__('缺少 url_id 参数')];
        }
        $row = $this->sitemapUrl->reset()->load($urlId);
        if ((int)$row->getData(SitemapUrl::schema_fields_ID) !== $urlId) {
            return ['success' => false, 'message' => (string)__('Sitemap URL 不存在：%{1}', $urlId)];
        }

        $changed = false;
        if (array_key_exists('status', $params)) {
            $status = (int)$params['status'];
            if (!in_array($status, [0, 1], true)) {
                return ['success' => false, 'message' => (string)__('status 仅支持 0 或 1')];
            }
            $row->setData(SitemapUrl::schema_fields_STATUS, $status);
            $changed = true;
        }
        if (array_key_exists('changefreq', $params)) {
            $changefreq = strtolower(trim((string)$params['changefreq']));
            if (!in_array($changefreq, self::URL_CHANGEFREQ_OPTIONS, true)) {
                return ['success' => false, 'message' => (string)__('changefreq 取值无效：%{1}', $changefreq)];
            }
            $row->setData(SitemapUrl::schema_fields_CHANGEFREQ, $changefreq);
            $changed = true;
        }
        if (array_key_exists('priority', $params)) {
            $priority = (float)$params['priority'];
            if ($priority < 0.0 || $priority > 1.0) {
                return ['success' => false, 'message' => (string)__('priority 必须在 0.0 与 1.0 之间')];
            }
            $row->setData(SitemapUrl::schema_fields_PRIORITY, rtrim(rtrim(number_format($priority, 1, '.', ''), '0'), '.') ?: '0');
            $changed = true;
        }
        if (!$changed) {
            return ['success' => false, 'message' => (string)__('没有需要更新的字段')];
        }
        $row->save();

        return [
            'success' => true,
            'message' => (string)__('Sitemap URL 已更新；重新生成 canonical 后生效'),
        ];
    }

    /**
     * Delete sitemap URLs by id. Provider-synced URLs will be re-created on the
     * next sync; use status=0 for a durable exclusion.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function deleteSitemapUrls(array $params): array
    {
        $urlIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): int => (int)$value,
            (array)($params['url_ids'] ?? []),
        ), static fn (int $id): bool => $id > 0)));
        if ($urlIds === []) {
            return ['success' => false, 'message' => (string)__('缺少 url_ids 参数')];
        }
        if (count($urlIds) > 200) {
            return ['success' => false, 'message' => (string)__('单次最多删除 200 条 URL')];
        }
        $deleted = 0;
        foreach ($urlIds as $urlId) {
            $row = $this->sitemapUrl->reset()->load($urlId);
            if ((int)$row->getData(SitemapUrl::schema_fields_ID) !== $urlId) {
                continue;
            }
            $this->sitemapUrl->reset()->where(SitemapUrl::schema_fields_ID, $urlId)->delete();
            $deleted++;
        }

        return [
            'success' => true,
            'deleted' => $deleted,
            'message' => (string)__('已删除 %{1} 条 URL；Provider 同步会重建其提供的 URL，长期排除请改用停用', $deleted),
        ];
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        $providers = [];
        foreach ($this->registry->getUrlProviders(true) as $provider) {
            $providers[] = [
                'scope' => $provider->getScope(),
                'module' => $provider->getModule(),
                'description' => $provider->getDescription(),
                'enabled' => $provider->isEnabled(),
                'class' => $provider::class,
            ];
        }
        $statsByWebsite = [];
        foreach ($this->sitemaps->getAllWebsiteStats() as $stats) {
            $statsByWebsite[(int)($stats['website_id'] ?? -1)] = $stats;
        }
        $websites = [];
        $summary = [
            'website_count' => 0,
            'active_urls' => 0,
            'locale_buckets' => 0,
            'generated_websites' => 0,
            'submission_ready_websites' => 0,
        ];
        foreach ($this->websiteDirectory->listWebsites() as $website) {
            if (!is_array($website) || (!array_key_exists('website_id', $website) && !array_key_exists('id', $website))) {
                continue;
            }
            $websiteId = (int)($website['website_id'] ?? $website['id']);
            if ($websiteId < 0) {
                continue;
            }
            $files = $this->sitemaps->getSitemapFileList($websiteId);
            $stats = $statsByWebsite[$websiteId] ?? [
                'website_id' => $websiteId,
                'url_count' => 0,
                'scope_stats' => [],
                'platform_count' => 0,
            ];
            $localeBuckets = 0;
            foreach ((array)($stats['scope_stats'] ?? []) as $scopeStats) {
                $localeBuckets += count((array)($scopeStats['locales'] ?? []));
            }
            $canonical = is_array($files['canonical'] ?? null) ? $files['canonical'] : null;
            $fileTree = $this->buildSitemapFileTree(
                $website,
                (array)($stats['scope_stats'] ?? []),
                $files,
                max(0, (int)($stats['url_count'] ?? 0)),
            );
            $websites[] = $website + [
                'stats' => $stats,
                'sitemap_files' => $files,
                'canonical' => $canonical,
                'canonical_language_files' => $this->buildCanonicalLanguageFiles(
                    (array)($stats['scope_stats'] ?? []),
                    $canonical,
                ),
                'sitemap_file_tree' => $fileTree,
                'locale_bucket_count' => $localeBuckets,
                'submission_ready' => (int)($stats['platform_count'] ?? 0) > 0,
            ];
            $summary['website_count']++;
            $summary['active_urls'] += (int)($stats['url_count'] ?? 0);
            $summary['locale_buckets'] += $localeBuckets;
            $summary['generated_websites'] += $canonical !== null ? 1 : 0;
            $summary['submission_ready_websites'] += (int)($stats['platform_count'] ?? 0) > 0 ? 1 : 0;
        }
        return ['websites' => $websites, 'providers' => $providers, 'summary' => $summary];
    }

    /**
     * Build the public entry -> target index -> locale bucket -> shard tree.
     * Every business identity comes from the publisher manifest; filenames are
     * display-only and are never parsed to infer locale or provider ownership.
     *
     * @param array<string,mixed> $website
     * @param list<array<string,mixed>> $scopeStats
     * @param array<string,mixed> $files
     * @return array<string,mixed>
     */
    private function buildSitemapFileTree(
        array $website,
        array $scopeStats,
        array $files,
        int $activeUrlCount,
    ): array
    {
        $publicBaseUrl = rtrim($this->websiteDirectory->effectivePublicBaseUrl($website), '/');
        $targetManifests = [];
        foreach ((array)($files['targets'] ?? []) as $target => $manifest) {
            if (!is_array($manifest)) {
                continue;
            }
            $targetCode = trim((string)($manifest['target'] ?? $manifest['platform_code'] ?? $target));
            if ($targetCode !== '') {
                $targetManifests[$targetCode] = $this->rewriteManifestPublicUrls($manifest, $publicBaseUrl);
            }
        }
        if (!isset($targetManifests['canonical'])) {
            $canonical = is_array($files['canonical'] ?? null)
                ? $this->rewriteManifestPublicUrls($files['canonical'], $publicBaseUrl)
                : ['target' => 'canonical', 'platform_code' => 'canonical'];
            $targetManifests['canonical'] = $canonical;
        }
        uksort($targetManifests, static function (string $left, string $right): int {
            if ($left === 'canonical') {
                return -1;
            }
            if ($right === 'canonical') {
                return 1;
            }
            return strcmp($left, $right);
        });

        $targets = [];
        $generatedTargetCount = 0;
        $canonicalTotalUrls = null;
        $canonicalShardCount = 0;
        $canonicalGenerated = false;
        foreach ($targetManifests as $targetCode => $manifest) {
            $isCanonical = $targetCode === 'canonical';
            $groups = $this->buildCanonicalLanguageFiles($isCanonical ? $scopeStats : [], $manifest);
            $index = is_array($manifest['index'] ?? null) ? $manifest['index'] : [];
            $generated = trim((string)($index['url'] ?? '')) !== '';
            $shardCount = array_sum(array_map(
                static fn (array $group): int => (int)($group['file_count'] ?? 0),
                $groups,
            ));
            $groupUrlCount = array_sum(array_map(
                static fn (array $group): int => max(0, (int)($group['url_count'] ?? 0)),
                $groups,
            ));
            $totalUrls = array_key_exists('total_urls', $manifest)
                ? max(0, (int)$manifest['total_urls'])
                : $groupUrlCount;
            if ($isCanonical) {
                $canonicalTotalUrls = array_key_exists('total_urls', $manifest)
                    ? $totalUrls
                    : $activeUrlCount;
                $canonicalShardCount = $shardCount;
                $canonicalGenerated = $generated;
            }
            if ($generated) {
                $generatedTargetCount++;
            }
            $targets[] = [
                'target' => $targetCode,
                'platform_name' => trim((string)($manifest['platform_name'] ?? '')),
                'platform_color' => trim((string)($manifest['platform_color'] ?? '')),
                'generated' => $generated,
                'generated_at' => trim((string)($manifest['generated_at'] ?? '')),
                'index' => $index,
                'total_urls' => $totalUrls,
                'bucket_count' => count($groups),
                'shard_count' => $shardCount,
                'total_files' => $shardCount + ($generated ? 1 : 0),
                'groups' => $groups,
            ];
        }

        $publicOrigins = $this->websiteDirectory->listPublicOrigins($website);
        $publicUrls = [];
        foreach ($publicOrigins as $origin) {
            if (!is_array($origin)) {
                continue;
            }
            $sitemapUrl = trim((string)($origin['sitemap_url'] ?? ''));
            if ($sitemapUrl === '') {
                continue;
            }
            $publicUrls[] = [
                'url' => $sitemapUrl,
                'base_url' => (string)($origin['base_url'] ?? ''),
                'domain' => (string)($origin['domain'] ?? ''),
                'sub_path' => (string)($origin['sub_path'] ?? ''),
                'is_primary' => !empty($origin['is_primary']),
                'is_canonical' => !empty($origin['is_canonical']),
                'source' => (string)($origin['source'] ?? ''),
            ];
        }
        $publicUrl = $this->siteSitemapUrl($website);
        if ($publicUrl === '' && $publicUrls !== []) {
            $publicUrl = (string)$publicUrls[0]['url'];
        }

        return [
            'public_url' => $publicUrl,
            'public_urls' => $publicUrls,
            'total_urls' => $canonicalTotalUrls ?? $activeUrlCount,
            'child_sitemap_count' => $canonicalShardCount,
            'target_count' => count($targets),
            'generated_target_count' => $generatedTargetCount,
            'generated' => $canonicalGenerated,
            'limits' => [
                'max_urls' => AtomicSitemapPublisher::STANDARD_MAX_URLS,
                'max_bytes' => AtomicSitemapPublisher::STANDARD_MAX_BYTES,
            ],
            'targets' => $targets,
        ];
    }

    /** @param array<string,mixed> $website */
    private function siteSitemapUrl(array $website): string
    {
        $baseUrl = rtrim($this->websiteDirectory->effectivePublicBaseUrl($website), '/');
        if ($baseUrl === '') {
            return '';
        }
        $parts = parse_url($baseUrl);
        if (
            !is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string)($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return '';
        }
        return $baseUrl . '/sitemap.xml';
    }

    /**
     * Rewrite loopback origins baked into an older manifest so the admin UI
     * shows the live project entry for the default website placeholder.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function rewriteManifestPublicUrls(array $manifest, string $publicBaseUrl): array
    {
        if ($publicBaseUrl === '') {
            return $manifest;
        }

        if (is_array($manifest['index'] ?? null)) {
            $manifest['index']['url'] = $this->websiteDirectory->rewriteLoopbackPublicUrl(
                (string)($manifest['index']['url'] ?? ''),
                $publicBaseUrl,
            );
        }

        foreach (['shards', 'files'] as $listKey) {
            if (!is_array($manifest[$listKey] ?? null)) {
                continue;
            }
            foreach ($manifest[$listKey] as $offset => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $manifest[$listKey][$offset]['url'] = $this->websiteDirectory->rewriteLoopbackPublicUrl(
                    (string)($row['url'] ?? ''),
                    $publicBaseUrl,
                );
            }
        }

        return $manifest;
    }

    /**
     * Build a manifest-backed read model for the backend. Database buckets are
     * included first so a locale remains visible while its files are pending.
     *
     * @param list<array<string,mixed>> $scopeStats
     * @param array<string,mixed>|null $canonical
     * @return list<array<string,mixed>>
     */
    private function buildCanonicalLanguageFiles(array $scopeStats, ?array $canonical): array
    {
        $groups = [];
        $activeModuleScopes = [];
        foreach ($scopeStats as $scopeStat) {
            if (!is_array($scopeStat)) {
                continue;
            }
            $module = trim((string)($scopeStat['module'] ?? ''));
            $scope = trim((string)($scopeStat['scope'] ?? ''));
            if ($module !== '' && $scope !== '') {
                $activeModuleScopes[$module . "\0" . $scope] = true;
            }
            foreach ((array)($scopeStat['locales'] ?? []) as $locale) {
                $locale = trim((string)$locale);
                $this->ensureLanguageFileGroup(
                    $groups,
                    $module,
                    $scope,
                    $locale === 'default' ? '' : $locale,
                );
            }
        }

        foreach ((array)($canonical['buckets'] ?? []) as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            $module = trim((string)($bucket['module'] ?? ''));
            $scope = trim((string)($bucket['scope'] ?? ''));
            // Drop stale manifest buckets whose provider no longer has active URL rows
            // (e.g. Weline_Index homepage after FrontendHomeOwner claim).
            if ($activeModuleScopes !== [] && !isset($activeModuleScopes[$module . "\0" . $scope])) {
                continue;
            }
            $key = $this->ensureLanguageFileGroup(
                $groups,
                $module,
                $scope,
                trim((string)($bucket['locale'] ?? '')),
            );
            $groups[$key]['url_count'] = max(0, (int)($bucket['url_count'] ?? 0));
        }

        foreach ((array)($canonical['shards'] ?? []) as $shard) {
            if (!is_array($shard)) {
                continue;
            }
            $filename = trim((string)($shard['filename'] ?? ''));
            if ($filename === '') {
                continue;
            }
            $module = trim((string)($shard['module'] ?? ''));
            $scope = trim((string)($shard['scope'] ?? ''));
            if ($activeModuleScopes !== [] && !isset($activeModuleScopes[$module . "\0" . $scope])) {
                continue;
            }
            $key = $this->ensureLanguageFileGroup(
                $groups,
                $module,
                $scope,
                trim((string)($shard['locale'] ?? '')),
            );
            $groups[$key]['files'][] = [
                'filename' => $filename,
                'url' => trim((string)($shard['url'] ?? '')),
                'count' => max(0, (int)($shard['count'] ?? 0)),
                'size' => max(0, (int)($shard['size'] ?? 0)),
                'lastmod' => trim((string)($shard['lastmod'] ?? '')),
                'hash' => trim((string)($shard['hash'] ?? '')),
            ];
        }

        foreach ($groups as &$group) {
            usort($group['files'], static fn (array $left, array $right): int => strcmp(
                (string)($left['filename'] ?? ''),
                (string)($right['filename'] ?? ''),
            ));
            $group['generated'] = $group['files'] !== [];
            $group['file_count'] = count($group['files']);
        }
        unset($group);

        $groups = array_values($groups);
        usort($groups, static function (array $left, array $right): int {
            $leftLocale = (string)($left['locale'] ?? '');
            $rightLocale = (string)($right['locale'] ?? '');
            $leftSort = ($leftLocale === '' ? "\0" : $leftLocale)
                . "\0" . (string)($left['module'] ?? '') . "\0" . (string)($left['scope'] ?? '');
            $rightSort = ($rightLocale === '' ? "\0" : $rightLocale)
                . "\0" . (string)($right['module'] ?? '') . "\0" . (string)($right['scope'] ?? '');
            return strcmp($leftSort, $rightSort);
        });
        return $groups;
    }

    /** @param array<string,array<string,mixed>> $groups */
    private function ensureLanguageFileGroup(
        array &$groups,
        string $module,
        string $scope,
        string $locale,
    ): string {
        $key = hash('sha256', $module . "\0" . $scope . "\0" . $locale);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'module' => $module,
                'scope' => $scope,
                'locale' => $locale,
                'url_count' => null,
                'generated' => false,
                'file_count' => 0,
                'files' => [],
            ];
        }
        return $key;
    }

    public function syncSitemapUrls(array $params): array
    {
        $websiteIds = $this->resolveWebsiteIds($params);
        $module = trim((string)($params['module'] ?? $params['provider_module'] ?? ''));
        $modules = $module !== '' ? [$module] : $this->providerModules();
        $results = [];
        $summary = ['inserted' => 0, 'updated' => 0, 'disabled' => 0, 'unchanged' => 0, 'errors' => 0];
        $retryable = false;
        $generationPending = false;
        foreach ($websiteIds as $websiteId) {
            foreach ($modules as $providerModule) {
                $result = $this->sync->syncModuleWebsite($providerModule, $websiteId, true);
                $results[] = $result;
                foreach (array_keys($summary) as $key) {
                    $summary[$key] += (int)($result[$key] ?? 0);
                }
                $retryable = $retryable || !empty($result['retryable']);
                $generationPending = $generationPending || !empty($result['generation_pending']);
            }
        }
        $hasErrors = $summary['errors'] > 0;
        $summaryMessage = __('Sitemap URL 同步完成：新增 %{1}、更新 %{2}、停用 %{3}、未变化 %{4}', [
            $summary['inserted'], $summary['updated'], $summary['disabled'], $summary['unchanged'],
        ]);
        $errorDetails = [];
        foreach ($results as $result) {
            foreach ((array)($result['error_messages'] ?? []) as $errorMessage) {
                $text = trim((string)$errorMessage);
                if ($text !== '') {
                    $errorDetails[] = $text;
                }
            }
        }
        $errorDetails = array_values(array_unique($errorDetails));
        return $this->success(
            $hasErrors
                ? __('Sitemap URL 同步失败：%{1}', $errorDetails[0] ?? __('部分 Provider 同步失败，请查看明细'))
                : $summaryMessage,
            ['summary' => $summary, 'results' => $results],
            $hasErrors,
            $retryable,
            $generationPending,
            $hasErrors
                ? ($errorDetails !== [] ? $errorDetails : [(string)__('部分 Provider 同步失败，请查看明细')])
                : [],
        );
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function generateSitemaps(array $params): array
    {
        $results = [];
        $errors = [];
        foreach ($this->resolveWebsiteIds($params) as $websiteId) {
            try {
                $result = $this->sitemaps->generateSitemapFiles($websiteId);
                $results[] = ['website_id' => $websiteId] + $result;
                if (empty($result['success'])) {
                    $errors[] = (string)($result['message'] ?? __('生成失败'));
                }
            } catch (\Throwable $exception) {
                $errors[] = __('站点 %{1} 生成失败：%{2}', [$websiteId, $exception->getMessage()]);
                $results[] = ['website_id' => $websiteId, 'success' => false, 'message' => $exception->getMessage()];
            }
        }
        return [
            'success' => $errors === [],
            'message' => $errors === [] ? __('Canonical Sitemap 生成完成') : __('部分 Sitemap 生成失败'),
            'data' => ['results' => $results],
            'errors' => $errors,
            'retryable' => (bool)array_filter($results, static fn (array $result): bool => !empty($result['retryable'])),
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function submitSitemaps(array $params): array
    {
        $results = [];
        $errors = [];
        foreach ($this->resolveWebsiteIds($params) as $websiteId) {
            try {
                $siteResults = $this->sitemaps->submitSitemaps($websiteId);
                $results[] = ['website_id' => $websiteId, 'platforms' => $siteResults];
                foreach ($siteResults as $platform => $result) {
                    if (empty($result['success'])) {
                        $errors[] = __('站点 %{1} 的 %{2} 提交失败：%{3}', [$websiteId, $platform, $result['message'] ?? __('未知错误')]);
                    }
                }
            } catch (\Throwable $exception) {
                $errors[] = __('站点 %{1} 提交失败：%{2}', [$websiteId, $exception->getMessage()]);
            }
        }
        return [
            'success' => $errors === [],
            'message' => $errors === [] ? __('Sitemap 提交完成') : __('部分 Sitemap 提交失败'),
            'data' => ['results' => $results],
            'errors' => $errors,
            'retryable' => false,
        ];
    }

    /** @param array<string,mixed> $params @return list<int> */
    private function resolveWebsiteIds(array $params): array
    {
        $ids = [];
        if ($this->toBool($params['all_sites'] ?? false)) {
            foreach ($this->websiteDirectory->listWebsites() as $website) {
                if (is_array($website) && (array_key_exists('website_id', $website) || array_key_exists('id', $website))) {
                    $id = (int)($website['website_id'] ?? $website['id']);
                    if ($id >= 0) {
                        $ids[$id] = $id;
                    }
                }
            }
        } else {
            $rawIds = $params['website_ids'] ?? [];
            if (!is_array($rawIds)) {
                throw new \InvalidArgumentException((string)__('website_ids 必须是数组'));
            }
            if (array_key_exists('website_id', $params)) {
                $rawIds[] = $params['website_id'];
            }
            foreach ($rawIds as $rawId) {
                if (!(is_int($rawId) || (is_string($rawId) && preg_match('/^\d+$/D', $rawId) === 1))) {
                    throw new \InvalidArgumentException((string)__('website_id 必须是非负整数'));
                }
                $id = (int)$rawId;
                if ($id < 0 || $this->websiteDirectory->getWebsiteById($id) === null) {
                    throw new \InvalidArgumentException((string)__('站点不存在：%{1}', $id));
                }
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            throw new \InvalidArgumentException((string)__('必须选择至少一个站点或启用全部站点'));
        }
        return array_values($ids);
    }

    /** @return list<string> */
    private function providerModules(): array
    {
        $modules = [];
        foreach ($this->registry->getUrlProviders(true) as $provider) {
            $module = trim($provider->getModule());
            if ($module !== '') {
                $modules[$module] = $module;
            }
        }
        return array_values($modules);
    }

    /**
     * @param list<string> $errors
     * @return array<string,mixed>
     */
    private function success(
        string $message,
        array $data,
        bool $hasErrors = false,
        bool $retryable = false,
        bool $generationPending = false,
        array $errors = [],
    ): array
    {
        return [
            'success' => !$hasErrors,
            'message' => $message,
            'data' => $data,
            'errors' => $hasErrors
                ? ($errors !== [] ? array_values($errors) : [(string)__('部分 Provider 同步失败，请查看明细')])
                : [],
            'retryable' => $retryable,
            'generation_pending' => $generationPending,
        ];
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
