<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use DateTimeImmutable;
use DateTimeZone;
use Weline\I18n\Api\Localization\LocaleCatalogInterface;
use Weline\I18n\Api\Localization\LocaleRepositoryInterface;
use Weline\Seo\Api\Sitemap\Data\Website;
use Weline\Seo\Api\Sitemap\FrontendHomeOwnerInterface;
use Weline\Seo\Api\Sitemap\WebsiteDirectoryInterface;
use Weline\Seo\Interface\SitemapUrlProviderInterface;
use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Service\Database\SeoTransactionRunner;
use Weline\Seo\Service\Sitemap\SitemapOperationLock;

final class SitemapUrlSyncService
{
    private const CHANGEFREQ = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    /** Framework fallback homepage provider identity. */
    private const FALLBACK_HOME_MODULE = 'Weline_Index';
    private const FALLBACK_HOME_SCOPE = 'frontend';

    public function __construct(
        private readonly SitemapRegistryService $registryService,
        private readonly SitemapUrl $sitemapUrl,
        private readonly WebsiteDirectoryInterface $websiteDirectory,
        private readonly LocaleCatalogInterface $localeCatalog,
        private readonly LocaleRepositoryInterface $localeRepository,
        private readonly SeoTransactionRunner $transactions,
        private readonly SitemapOperationLock $operationLock,
    ) {
    }

    /** @return array<string,mixed> */
    public function syncAll(bool $forceReload = false, string $filterModule = ''): array
    {
        $stats = $this->emptyStats();
        $stats['providers'] = [];
        foreach ($this->registryService->getUrlProviders($forceReload) as $provider) {
            if ($filterModule !== '' && $provider->getModule() !== $filterModule) {
                continue;
            }
            $providerStats = $this->syncProvider($provider);
            $stats['providers'][] = $providerStats;
            $this->mergeStats($stats, $providerStats);
        }
        return $this->finalizeStats($stats);
    }

    /** @return array<string,mixed> */
    public function syncModuleWebsite(string $module, int $websiteId, bool $forceReload = false): array
    {
        $module = trim($module);
        $stats = $this->emptyStats();
        $stats['module'] = $module;
        $stats['website_id'] = $websiteId;
        $stats['providers'] = [];
        if ($module === '' || $websiteId < 0) {
            $stats['errors'] = 1;
            $stats['error_messages'][] = __('缺少 sitemap 定向同步 module，或 website_id 非法');
            return $stats;
        }

        foreach ($this->registryService->getUrlProviders($forceReload) as $provider) {
            if ($provider->getModule() !== $module) {
                continue;
            }
            $providerStats = $this->emptyStats();
            $providerStats['module'] = $provider->getModule();
            $providerStats['scope'] = $provider->getScope();
            $providerStats['description'] = $provider->getDescription();
            $providerStats['website_id'] = $websiteId;
            $providerStats['enabled'] = $provider->isEnabled();
            $providerWebsiteIds = array_values(array_unique(array_map('intval', $provider->getWebsiteIds())));
            if ($providerWebsiteIds !== [] && !in_array($websiteId, $providerWebsiteIds, true)) {
                $providerStats['skipped'] = true;
                $providerStats['reason'] = 'website_not_owned_by_provider';
            } else {
                try {
                    $providerStats = array_replace($providerStats, $this->syncProviderWebsite($provider, $websiteId));
                } catch (\Throwable $exception) {
                    $providerStats['errors'] = 1;
                    $providerStats['error_messages'] = [$exception->getMessage()];
                }
            }
            $stats['providers'][] = $providerStats;
            $this->mergeStats($stats, $providerStats);
        }
        return $this->finalizeStats($stats);
    }

    /** @return array<string,mixed> */
    public function syncProvider(SitemapUrlProviderInterface $provider): array
    {
        $stats = $this->emptyStats();
        $stats['module'] = $provider->getModule();
        $stats['scope'] = $provider->getScope();
        $stats['description'] = $provider->getDescription();
        $stats['enabled'] = $provider->isEnabled();
        $stats['websites'] = [];

        $websiteIds = array_values(array_unique(array_map('intval', $provider->getWebsiteIds())));
        foreach ($websiteIds as $websiteId) {
            if ($websiteId < 0) {
                $stats['errors']++;
                $stats['error_messages'][] = __('Provider %{1} 返回了负数 website_id', $provider->getModule());
                continue;
            }
            try {
                $websiteStats = $this->syncProviderWebsite($provider, $websiteId);
            } catch (\Throwable $exception) {
                $websiteStats = $this->emptyStats();
                $websiteStats['website_id'] = $websiteId;
                $websiteStats['errors'] = 1;
                $websiteStats['error_messages'] = [$exception->getMessage()];
            }
            $stats['websites'][] = $websiteStats;
            $this->mergeStats($stats, $websiteStats);
        }
        return $this->finalizeStats($stats);
    }

    /** @return array<string,mixed> */
    public function syncProviderWebsite(SitemapUrlProviderInterface $provider, int $websiteId): array
    {
        $scope = trim($provider->getScope());
        $module = trim($provider->getModule());
        $this->assertIdentityField($module, 100, 'module');
        $this->assertIdentityField($scope, 50, 'scope');
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('website_id 不能为负数'));
        }

        try {
            $locked = $this->operationLock->run('sync', [$module, $scope, $websiteId], function () use ($provider, $websiteId, $scope, $module): array {
                $website = $this->websiteDirectory->get($websiteId);
                if (!$website instanceof Website) {
                    throw new \InvalidArgumentException((string)__('Sitemap Provider 目标站点不存在：%{1}', $websiteId));
                }
                $rawUrls = $provider->isEnabled() ? $provider->getUrlsForWebsite($websiteId) : [];
                $validated = $this->validateUrls($rawUrls, $website, $scope, $module);
                if ($validated['errors'] !== []) {
                    $result = $this->emptyStats();
                    $result['invalid'] = count($validated['errors']);
                    $result['errors'] = count($validated['errors']);
                    $result['error_messages'] = $validated['errors'];
                    $result['owner_url_count'] = count($rawUrls);
                    return $result;
                }

                return $this->transactions->run(
                    $this->sitemapUrl->getConnection(),
                    function () use ($websiteId, $scope, $module, $validated, $rawUrls): array {
                        $existing = $this->getExistingUrls($websiteId, $scope, $module);
                        $result = $this->performIncrementalUpdate(
                            $websiteId,
                            $scope,
                            $module,
                            $validated['urls'],
                            $existing['rows'],
                        );
                        $result['manual_cleanup'] = $existing['manual_cleanup'];
                        $result['owner_url_count'] = count($rawUrls);
                        return $result;
                    },
                );
            });

            if (!$locked['acquired']) {
                $result = $this->emptyStats();
                $result['errors'] = 1;
                $result['retryable'] = true;
                $result['reason'] = 'sync_in_progress';
                $result['error_messages'][] = __('相同 Provider 与站点正在同步，请稍后重试');
            } else {
                $result = is_array($locked['result']) ? $locked['result'] : $this->emptyStats();
            }
        } catch (\Throwable $exception) {
            // Keep claim-based Index cleanup reachable even when owner persistence fails
            // (e.g. transitional schema). InvalidArgument for identity/website still throws above.
            $result = $this->emptyStats();
            $result['errors'] = 1;
            $result['error_messages'] = [$exception->getMessage()];
            $result['owner_url_count'] = 0;
        }

        $result['website_id'] = $websiteId;
        $result['module'] = $module;
        $result['scope'] = $scope;
        if ($this->hasChanges($result)) {
            $result['generation_pending'] = true;
            $result['changed_websites'][] = $websiteId;
            $result['changed_modules'][] = $this->moduleKey($module, $scope);
        }

        // When a dedicated provider claims `/` and successfully persists a non-empty
        // snapshot, clear stale Weline_Index homepage rows for the same website.
        // Do not wipe the fallback homepage when the owner sync failed or returned
        // an empty snapshot — that left default sites with zero Sitemap URLs.
        if (
            ($result['reason'] ?? '') !== 'sync_in_progress'
            && (int)($result['errors'] ?? 0) === 0
            && (int)($result['invalid'] ?? 0) === 0
            && (int)($result['owner_url_count'] ?? 0) > 0
            && $provider instanceof FrontendHomeOwnerInterface
            && $this->providerClaimsFrontendHome($provider, $websiteId)
            && !($module === self::FALLBACK_HOME_MODULE && $scope === self::FALLBACK_HOME_SCOPE)
        ) {
            $cleanup = $this->syncFallbackFrontendHome($websiteId, $provider);
            if (is_array($cleanup)) {
                $result['fallback_home_cleanup'] = [
                    'module' => self::FALLBACK_HOME_MODULE,
                    'scope' => self::FALLBACK_HOME_SCOPE,
                    'disabled' => (int)($cleanup['disabled'] ?? 0),
                    'updated' => (int)($cleanup['updated'] ?? 0),
                    'inserted' => (int)($cleanup['inserted'] ?? 0),
                    'errors' => (int)($cleanup['errors'] ?? 0),
                    'error_messages' => is_array($cleanup['error_messages'] ?? null)
                        ? $cleanup['error_messages']
                        : [],
                ];
                if ($this->hasChanges($cleanup)) {
                    $result['generation_pending'] = true;
                    $result['changed_websites'][] = $websiteId;
                    $result['changed_modules'][] = $this->moduleKey(
                        self::FALLBACK_HOME_MODULE,
                        self::FALLBACK_HOME_SCOPE,
                    );
                }
            }
        }

        return $this->finalizeStats($result);
    }

    private function providerClaimsFrontendHome(FrontendHomeOwnerInterface $provider, int $websiteId): bool
    {
        foreach ($provider->getFrontendHomeWebsiteIds() as $ownedWebsiteId) {
            if ((int)$ownedWebsiteId === $websiteId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Re-sync the framework fallback homepage provider so claimed websites drop
     * stale Weline_Index `/` rows.
     *
     * @return array<string,mixed>|null
     */
    private function syncFallbackFrontendHome(int $websiteId, SitemapUrlProviderInterface $owner): ?array
    {
        foreach ($this->registryService->getUrlProviders() as $provider) {
            if ($provider === $owner) {
                continue;
            }
            if (
                trim($provider->getModule()) !== self::FALLBACK_HOME_MODULE
                || trim($provider->getScope()) !== self::FALLBACK_HOME_SCOPE
            ) {
                continue;
            }
            try {
                return $this->syncProviderWebsite($provider, $websiteId);
            } catch (\Throwable $exception) {
                $failed = $this->emptyStats();
                $failed['errors'] = 1;
                $failed['error_messages'] = [$exception->getMessage()];
                $failed['website_id'] = $websiteId;
                $failed['module'] = self::FALLBACK_HOME_MODULE;
                $failed['scope'] = self::FALLBACK_HOME_SCOPE;

                return $failed;
            }
        }

        return null;
    }

    /**
     * @param array<int,mixed> $urls
     * @return array{urls:array<string,array<string,mixed>>,errors:list<string>}
     */
    private function validateUrls(array $urls, Website $website, string $scope, string $module): array
    {
        $validated = [];
        $errors = [];
        $seenLocs = [];
        $activeLocales = $this->activeLocaleMap();
        foreach ($urls as $index => $url) {
            if (!is_array($url)) {
                $errors[] = __('第 %{1} 条 URL 数据不是数组', $index);
                continue;
            }
            try {
                $urlKey = trim((string)($url['url_key'] ?? $url['key'] ?? ''));
                $this->assertIdentityField($urlKey, 191, 'url_key');
                $locale = $this->normalizeLocale((string)($url['locale'] ?? ''), $activeLocales);
                $loc = $this->normalizeLoc((string)($url['loc'] ?? $url['url'] ?? ''), $website);
                $identity = $this->identity($website->id, $scope, $module, $urlKey, $locale);
                $locIdentity = hash('sha256', json_encode([$locale, $loc], JSON_THROW_ON_ERROR));
                if (isset($validated[$identity])) {
                    throw new \InvalidArgumentException((string)__('重复的 Sitemap 五元组身份'));
                }
                if (isset($seenLocs[$locIdentity])) {
                    throw new \InvalidArgumentException((string)__('同一语言存在重复 canonical URL：%{1}', $loc));
                }
                [$entityType, $entityId] = $this->entityFromUrlKey($urlKey, $url);
                $validated[$identity] = [
                    'url_key' => $urlKey,
                    'locale' => $locale,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'url' => $loc,
                    'lastmod' => $this->normalizeLastmod($url['lastmod'] ?? null),
                    'changefreq' => $this->normalizeChangefreq($url['changefreq'] ?? 'weekly'),
                    'priority' => $this->normalizePriority($url['priority'] ?? '0.5'),
                    'metadata' => $this->normalizeSitemapMetadata($url),
                ];
                $seenLocs[$locIdentity] = true;
            } catch (\Throwable $exception) {
                $errors[] = __('第 %{1} 条 URL 无效：%{2}', [$index, $exception->getMessage()]);
            }
        }
        return ['urls' => $validated, 'errors' => $errors];
    }

    /** @return array<string,string> */
    private function activeLocaleMap(): array
    {
        $map = [];
        foreach ($this->localeCatalog->installed('zh_Hans_CN') as $record) {
            $code = trim((string)($record['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $map[strtolower(str_replace('-', '_', $code))] = $code;
        }
        return $map;
    }

    /** @param array<string,string> $activeLocales */
    private function normalizeLocale(string $locale, array $activeLocales): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return '';
        }
        $this->assertIdentityField($locale, 32, 'locale');
        $directKey = strtolower(str_replace('-', '_', $locale));
        if (isset($activeLocales[$directKey])) {
            return $activeLocales[$directKey];
        }
        $resolved = trim($this->localeRepository->resolveCode($locale, $locale));
        $key = strtolower(str_replace('-', '_', $resolved));
        if (!isset($activeLocales[$key])) {
            throw new \InvalidArgumentException((string)__('locale 未安装或未启用：%{1}', $locale));
        }
        return $activeLocales[$key];
    }

    private function normalizeLoc(string $loc, Website $website): string
    {
        $loc = trim($loc);
        if ($loc === '' || $this->hasControlCharacters($loc)) {
            throw new \InvalidArgumentException((string)__('loc 为空或包含控制字符'));
        }
        if (str_starts_with($loc, '//')) {
            throw new \InvalidArgumentException((string)__('loc 不允许省略协议'));
        }
        $base = rtrim(trim($website->url), '/');
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            throw new \InvalidArgumentException((string)__('站点基础 URL 无效'));
        }
        if (!preg_match('#^https?://#i', $loc)) {
            throw new \InvalidArgumentException((string)__('loc 必须是包含协议的完整 HTTP(S) URL'));
        }
        $parts = parse_url($loc);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException((string)__('loc 不是有效 HTTP(S) URL'));
        }
        if (!in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException((string)__('loc 仅支持 HTTP(S)'));
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException((string)__('loc 不允许 credentials 或 fragment'));
        }
        if (!$this->sameOrigin($baseParts, $parts) && $this->isLoopbackHost((string)$parts['host'])) {
            $path = (string)($parts['path'] ?? '/');
            if ($path === '') {
                $path = '/';
            }
            $loc = $base . ($path === '/' ? '/' : $path);
            $parts = parse_url($loc);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                throw new \InvalidArgumentException((string)__('loc 不是有效 HTTP(S) URL'));
            }
        }
        if (!$this->sameOrigin($baseParts, $parts)) {
            throw new \InvalidArgumentException((string)__('loc 必须与站点同源'));
        }
        if (strlen($loc) >= 2048) {
            throw new \InvalidArgumentException((string)__('loc 长度必须小于 2048 字节'));
        }
        return $loc;
    }

    private function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host));
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || $host === '[::1]'
            || str_ends_with($host, '.localhost');
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameOrigin(array $left, array $right): bool
    {
        $schemeLeft = strtolower((string)($left['scheme'] ?? ''));
        $schemeRight = strtolower((string)($right['scheme'] ?? ''));
        $portLeft = (int)($left['port'] ?? ($schemeLeft === 'https' ? 443 : 80));
        $portRight = (int)($right['port'] ?? ($schemeRight === 'https' ? 443 : 80));
        return $schemeLeft === $schemeRight
            && strtolower((string)($left['host'] ?? '')) === strtolower((string)($right['host'] ?? ''))
            && $portLeft === $portRight;
    }

    private function normalizeLastmod(mixed $value): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $value = trim((string)$value);
        if ($this->hasControlCharacters($value) || preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ][0-9:.+-]+Z?)?$/', $value) !== 1) {
            throw new \InvalidArgumentException((string)__('lastmod 必须是 W3C 日期或时间'));
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new \InvalidArgumentException((string)__('lastmod 无法解析'));
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function normalizeChangefreq(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        if (!in_array($value, self::CHANGEFREQ, true)) {
            throw new \InvalidArgumentException((string)__('changefreq 不在 Sitemap 枚举中'));
        }
        return $value;
    }

    private function normalizePriority(mixed $value): string
    {
        $raw = trim((string)$value);
        if ($raw === '' || !is_numeric($raw)) {
            throw new \InvalidArgumentException((string)__('priority 必须是数字'));
        }
        $priority = (float)$raw;
        if ($priority < 0.0 || $priority > 1.0) {
            throw new \InvalidArgumentException((string)__('priority 必须在 0.0 到 1.0 之间'));
        }
        $normalized = rtrim(rtrim(sprintf('%.6F', $priority), '0'), '.');
        return $normalized === '' ? '0' : $normalized;
    }

    /** @param array<string,mixed> $url @return array{0:string,1:int} */
    private function entityFromUrlKey(string $urlKey, array $url): array
    {
        $entityType = trim((string)($url['entity_type'] ?? ''));
        $entityId = (int)($url['entity_id'] ?? 0);
        if ($entityType === '' && preg_match('/^([a-z0-9_]+)/i', $urlKey, $matches)) {
            $entityType = strtolower($matches[1]);
        }
        $entityType = $entityType !== '' ? $entityType : 'url';
        $this->assertIdentityField($entityType, 50, 'entity_type');
        if ($entityId <= 0 && preg_match('/^[a-z0-9_]+-(\d+)$/i', $urlKey, $matches)) {
            $entityId = (int)$matches[1];
        }
        if ($entityId <= 0) {
            $entityId = ((int)sprintf('%u', crc32($urlKey)) % 2147483646) + 1;
        }
        return [$entityType, $entityId];
    }

    /** @return array{rows:array<string,array<string,mixed>>,manual_cleanup:int} */
    private function getExistingUrls(int $websiteId, string $scope, string $module): array
    {
        $rows = $this->sitemapUrl->reset()
            ->where(SitemapUrl::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapUrl::schema_fields_SCOPE, $scope)
            ->where(SitemapUrl::schema_fields_MODULE, $module)
            ->select()->fetchArray();
        $existing = [];
        $manualCleanup = 0;
        foreach ($rows as $row) {
            $urlKey = trim((string)($row[SitemapUrl::schema_fields_URL_KEY] ?? ''));
            if ($urlKey === '') {
                $manualCleanup++;
                continue;
            }
            $locale = trim((string)($row[SitemapUrl::schema_fields_LOCALE] ?? ''));
            $existing[$this->identity($websiteId, $scope, $module, $urlKey, $locale)] = $row;
        }
        return ['rows' => $existing, 'manual_cleanup' => $manualCleanup];
    }

    /** @param array<string,array<string,mixed>> $newUrls @param array<string,array<string,mixed>> $existingUrls */
    private function performIncrementalUpdate(int $websiteId, string $scope, string $module, array $newUrls, array $existingUrls): array
    {
        $stats = $this->emptyStats();
        $stats['total'] = count($newUrls);
        foreach ($newUrls as $identity => $urlData) {
            if (isset($existingUrls[$identity])) {
                $row = $existingUrls[$identity];
                if ($this->needsUpdate($urlData, $row)) {
                    $this->updateUrl((int)$row[SitemapUrl::schema_fields_ID], $urlData);
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            } else {
                $this->insertUrl($websiteId, $scope, $module, $urlData);
                $stats['inserted']++;
            }
        }
        foreach ($existingUrls as $identity => $row) {
            if (!isset($newUrls[$identity]) && (int)($row[SitemapUrl::schema_fields_STATUS] ?? 1) !== 0) {
                $this->disableUrl((int)$row[SitemapUrl::schema_fields_ID]);
                $stats['disabled']++;
            }
        }
        return $stats;
    }

    /** @param array<string,mixed> $newData @param array<string,mixed> $existingRow */
    private function needsUpdate(array $newData, array $existingRow): bool
    {
        $comparisons = [
            'url_key' => SitemapUrl::schema_fields_URL_KEY,
            'locale' => SitemapUrl::schema_fields_LOCALE,
            'entity_type' => SitemapUrl::schema_fields_ENTITY_TYPE,
            'entity_id' => SitemapUrl::schema_fields_ENTITY_ID,
            'url' => SitemapUrl::schema_fields_URL,
            'lastmod' => SitemapUrl::schema_fields_LASTMOD,
            'changefreq' => SitemapUrl::schema_fields_CHANGEFREQ,
            'priority' => SitemapUrl::schema_fields_PRIORITY,
            'metadata' => SitemapUrl::schema_fields_METADATA,
        ];
        foreach ($comparisons as $newField => $dbField) {
            if ((string)($newData[$newField] ?? '') !== (string)($existingRow[$dbField] ?? '')) {
                return true;
            }
        }
        return (int)($existingRow[SitemapUrl::schema_fields_STATUS] ?? 1) !== 1;
    }

    /** @param array<string,mixed> $urlData */
    private function insertUrl(int $websiteId, string $scope, string $module, array $urlData): void
    {
        $model = clone $this->sitemapUrl;
        $model->reset()->setData($this->persistenceData($websiteId, $scope, $module, $urlData))->save();
    }

    /** @param array<string,mixed> $urlData */
    private function updateUrl(int $id, array $urlData): void
    {
        $model = clone $this->sitemapUrl;
        $data = $this->persistenceData(null, null, null, $urlData);
        $data[SitemapUrl::schema_fields_UPDATED_AT] = date('Y-m-d H:i:s');
        $model->reset()
            ->where(SitemapUrl::schema_fields_ID, $id)
            ->update($data, SitemapUrl::schema_fields_ID)
            ->fetch();
    }

    private function disableUrl(int $id): void
    {
        $model = clone $this->sitemapUrl;
        $model->reset()->load($id);
        $model->setData(SitemapUrl::schema_fields_STATUS, 0)->save();
    }

    /** @param array<string,mixed> $urlData @return array<string,mixed> */
    private function persistenceData(?int $websiteId, ?string $scope, ?string $module, array $urlData): array
    {
        $data = [
            SitemapUrl::schema_fields_URL_KEY => $urlData['url_key'],
            SitemapUrl::schema_fields_LOCALE => $urlData['locale'],
            SitemapUrl::schema_fields_ENTITY_TYPE => $urlData['entity_type'],
            SitemapUrl::schema_fields_ENTITY_ID => $urlData['entity_id'],
            SitemapUrl::schema_fields_URL => $urlData['url'],
            SitemapUrl::schema_fields_LASTMOD => $urlData['lastmod'],
            SitemapUrl::schema_fields_CHANGEFREQ => $urlData['changefreq'],
            SitemapUrl::schema_fields_PRIORITY => $urlData['priority'],
            SitemapUrl::schema_fields_METADATA => $urlData['metadata'],
            SitemapUrl::schema_fields_STATUS => 1,
        ];
        if ($websiteId !== null) {
            $data[SitemapUrl::schema_fields_WEBSITE_ID] = $websiteId;
            $data[SitemapUrl::schema_fields_SCOPE] = $scope;
            $data[SitemapUrl::schema_fields_MODULE] = $module;
        }
        return $data;
    }

    /** @param array<string,mixed> $url */
    private function normalizeSitemapMetadata(array $url): string
    {
        $metadata = [];
        if (isset($url['metadata']) && is_array($url['metadata'])) {
            $metadata = $url['metadata'];
        } elseif (isset($url['metadata']) && is_string($url['metadata']) && trim($url['metadata']) !== '') {
            $decoded = json_decode($url['metadata'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException((string)__('metadata 必须是 JSON 对象或数组'));
            }
            $metadata = $decoded;
        }
        if (isset($url['sitemap']) && is_array($url['sitemap'])) {
            $metadata = array_replace_recursive($metadata, $url['sitemap']);
        }
        foreach (['images', 'image', 'videos', 'video', 'news', 'alternates', 'hreflang'] as $key) {
            if (array_key_exists($key, $url)) {
                $metadata[$key] = $url[$key];
            }
        }
        return $metadata === [] ? '' : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function assertIdentityField(string $value, int $maxBytes, string $field): void
    {
        if ($value === '' || strlen($value) > $maxBytes || $this->hasControlCharacters($value)) {
            throw new \InvalidArgumentException((string)__('%{1} 为空、过长或包含控制字符', $field));
        }
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException((string)__('%{1} 不是有效 UTF-8', $field));
        }
    }

    private function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private function identity(int $websiteId, string $scope, string $module, string $urlKey, string $locale): string
    {
        return hash('sha256', json_encode([$websiteId, $scope, $module, $urlKey, $locale], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function emptyStats(): array
    {
        return [
            'inserted' => 0, 'updated' => 0, 'disabled' => 0, 'unchanged' => 0, 'total' => 0,
            'invalid' => 0, 'errors' => 0, 'manual_cleanup' => 0, 'error_messages' => [],
            'changed_websites' => [], 'changed_modules' => [], 'retryable' => false,
            'generation_pending' => false,
        ];
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $source */
    private function mergeStats(array &$target, array $source): void
    {
        foreach (['inserted', 'updated', 'disabled', 'unchanged', 'total', 'invalid', 'errors', 'manual_cleanup'] as $key) {
            $target[$key] = (int)($target[$key] ?? 0) + (int)($source[$key] ?? 0);
        }
        $target['retryable'] = !empty($target['retryable']) || !empty($source['retryable']);
        $target['generation_pending'] = !empty($target['generation_pending']) || !empty($source['generation_pending']);
        $target['error_messages'] = array_merge((array)($target['error_messages'] ?? []), (array)($source['error_messages'] ?? []));
        $target['changed_websites'] = array_merge((array)($target['changed_websites'] ?? []), (array)($source['changed_websites'] ?? []));
        $target['changed_modules'] = array_merge((array)($target['changed_modules'] ?? []), (array)($source['changed_modules'] ?? []));
    }

    /** @param array<string,mixed> $stats @return array<string,mixed> */
    private function finalizeStats(array $stats): array
    {
        $stats['changed_websites'] = array_values(array_unique(array_map('intval', (array)($stats['changed_websites'] ?? []))));
        $stats['changed_modules'] = array_values(array_unique(array_map('strval', (array)($stats['changed_modules'] ?? []))));
        $stats['error_messages'] = array_values(array_unique(array_filter(array_map('strval', (array)($stats['error_messages'] ?? [])))));
        return $stats;
    }

    /** @param array<string,mixed> $stats */
    private function hasChanges(array $stats): bool
    {
        return (int)($stats['inserted'] ?? 0) + (int)($stats['updated'] ?? 0) + (int)($stats['disabled'] ?? 0) > 0;
    }

    private function moduleKey(string $module, string $scope): string
    {
        return $scope === '' ? $module : $module . '_' . $scope;
    }
}
