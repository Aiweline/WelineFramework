<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Interface\SitemapUrlProviderInterface;
use Weline\Seo\Model\SitemapUrl;

class SitemapUrlSyncService
{
    public function __construct(
        private readonly SitemapRegistryService $registryService,
        private readonly SitemapUrl $sitemapUrl
    ) {
    }

    /**
     * @return array<string, mixed>
     */
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

        $stats['changed_websites'] = array_values(array_unique($stats['changed_websites']));
        $stats['changed_modules'] = array_values(array_unique($stats['changed_modules']));

        return $stats;
    }

    /**
     * Sync a module's sitemap URLs for one website only.
     *
     * @return array<string, mixed>
     */
    public function syncModuleWebsite(string $module, int $websiteId, bool $forceReload = false): array
    {
        $module = trim($module);
        $stats = $this->emptyStats();
        $stats['module'] = $module;
        $stats['website_id'] = $websiteId;
        $stats['providers'] = [];

        if ($module === '' || $websiteId <= 0) {
            $stats['errors'] = 1;
            $stats['error_messages'][] = __('缺少 sitemap 定向同步 module 或 website_id');
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

            if (!$provider->isEnabled()) {
                $stats['providers'][] = $providerStats;
                continue;
            }

            $providerWebsiteIds = array_map('intval', $provider->getWebsiteIds());
            if ($providerWebsiteIds !== [] && !in_array($websiteId, $providerWebsiteIds, true)) {
                $providerStats['skipped'] = true;
                $providerStats['reason'] = 'website_not_owned_by_provider';
                $stats['providers'][] = $providerStats;
                continue;
            }

            try {
                $providerStats = array_replace($providerStats, $this->syncProviderWebsite($provider, $websiteId));
            } catch (\Throwable $e) {
                $providerStats['errors'] = 1;
                $providerStats['error_messages'] = [$e->getMessage()];
            }

            $stats['providers'][] = $providerStats;
            $this->mergeStats($stats, $providerStats);
        }

        $stats['changed_websites'] = array_values(array_unique($stats['changed_websites']));
        $stats['changed_modules'] = array_values(array_unique($stats['changed_modules']));

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    public function syncProvider(SitemapUrlProviderInterface $provider): array
    {
        $stats = $this->emptyStats();
        $stats['module'] = $provider->getModule();
        $stats['scope'] = $provider->getScope();
        $stats['description'] = $provider->getDescription();
        $stats['enabled'] = $provider->isEnabled();
        $stats['websites'] = [];

        if (!$provider->isEnabled()) {
            return $stats;
        }

        foreach ($provider->getWebsiteIds() as $websiteId) {
            $websiteId = (int)$websiteId;
            if ($websiteId <= 0) {
                continue;
            }

            try {
                $websiteStats = $this->syncProviderWebsite($provider, $websiteId);
            } catch (\Throwable $e) {
                $websiteStats = $this->emptyStats();
                $websiteStats['website_id'] = $websiteId;
                $websiteStats['errors'] = 1;
                $websiteStats['error_messages'] = [$e->getMessage()];
            }

            $stats['websites'][] = $websiteStats;
            $this->mergeStats($stats, $websiteStats);
        }

        $stats['changed_websites'] = array_values(array_unique($stats['changed_websites']));
        $stats['changed_modules'] = array_values(array_unique($stats['changed_modules']));

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    public function syncProviderWebsite(SitemapUrlProviderInterface $provider, int $websiteId): array
    {
        $scope = trim($provider->getScope());
        $module = trim($provider->getModule());
        if ($scope === '' || $module === '') {
            throw new \InvalidArgumentException(__('SitemapUrlProvider 必须返回非空 scope 和 module'));
        }

        $rawUrls = $provider->getUrlsForWebsite($websiteId);
        $validated = $this->validateUrls($rawUrls, $scope);
        $existing = $this->getExistingUrls($websiteId, $scope, $module);
        $result = $this->performIncrementalUpdate($websiteId, $scope, $module, $validated['urls'], $existing);

        $result['website_id'] = $websiteId;
        $result['module'] = $module;
        $result['scope'] = $scope;
        $result['invalid'] = $validated['invalid'];
        $result['error_messages'] = $validated['errors'];
        $result['errors'] = count($validated['errors']);
        if ($this->hasChanges($result)) {
            $result['changed_websites'][] = $websiteId;
            $result['changed_modules'][] = $this->moduleKey($module, $scope);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStats(): array
    {
        return [
            'inserted' => 0,
            'updated' => 0,
            'disabled' => 0,
            'unchanged' => 0,
            'total' => 0,
            'invalid' => 0,
            'errors' => 0,
            'error_messages' => [],
            'changed_websites' => [],
            'changed_modules' => [],
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private function mergeStats(array &$target, array $source): void
    {
        foreach (['inserted', 'updated', 'disabled', 'unchanged', 'total', 'invalid', 'errors'] as $key) {
            $target[$key] = (int)($target[$key] ?? 0) + (int)($source[$key] ?? 0);
        }
        foreach ((array)($source['error_messages'] ?? []) as $message) {
            if ($message !== '') {
                $target['error_messages'][] = (string)$message;
            }
        }
        foreach ((array)($source['changed_websites'] ?? []) as $websiteId) {
            $target['changed_websites'][] = (int)$websiteId;
        }
        foreach ((array)($source['changed_modules'] ?? []) as $moduleKey) {
            $target['changed_modules'][] = (string)$moduleKey;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $urls
     * @return array{urls: array<string, array<string, mixed>>, invalid: int, errors: list<string>}
     */
    private function validateUrls(array $urls, string $scope): array
    {
        $validated = [];
        $invalid = 0;
        $errors = [];

        foreach ($urls as $index => $url) {
            if (!is_array($url)) {
                $invalid++;
                $errors[] = __('第 %{1} 条 URL 数据不是数组', $index);
                continue;
            }

            $urlKey = trim((string)($url['url_key'] ?? $url['key'] ?? ''));
            $loc = trim((string)($url['loc'] ?? $url['url'] ?? ''));
            if ($urlKey === '' || $loc === '') {
                $invalid++;
                $errors[] = __('第 %{1} 条 URL 缺少 url_key 或 loc', $index);
                continue;
            }

            if (isset($validated[$urlKey])) {
                $invalid++;
                $errors[] = __('重复的 sitemap url_key：%{1}', $urlKey);
                continue;
            }

            [$entityType, $entityId] = $this->entityFromUrlKey($urlKey, $url);

            $validated[$urlKey] = [
                'url_key' => $urlKey,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'url' => $loc,
                'lastmod' => $url['lastmod'] ?? date('Y-m-d'),
                'changefreq' => $url['changefreq'] ?? 'weekly',
                'priority' => isset($url['priority']) ? (string)$url['priority'] : '0.5',
                'metadata' => $this->normalizeSitemapMetadata($url),
            ];
        }

        return [
            'urls' => $validated,
            'invalid' => $invalid,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $url
     * @return array{0:string,1:int}
     */
    private function entityFromUrlKey(string $urlKey, array $url): array
    {
        $entityType = trim((string)($url['entity_type'] ?? ''));
        $entityId = (int)($url['entity_id'] ?? 0);

        if ($entityType !== '' && $entityId > 0) {
            return [$entityType, $entityId];
        }

        if (preg_match('/^([a-z0-9_]+)-(\d+)$/i', $urlKey, $matches)) {
            return [strtolower($matches[1]), (int)$matches[2]];
        }

        if ($entityType === '') {
            if (preg_match('/^([a-z0-9_]+)/i', $urlKey, $matches)) {
                $entityType = strtolower($matches[1]);
            } else {
                $entityType = 'url';
            }
        }

        if ($entityId <= 0) {
            $entityId = ((int)sprintf('%u', crc32($urlKey)) % 2147483646) + 1;
        }

        return [substr($entityType, 0, 50), $entityId];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getExistingUrls(int $websiteId, string $scope, string $module): array
    {
        $rows = $this->sitemapUrl->reset()
            ->where(SitemapUrl::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapUrl::schema_fields_SCOPE, $scope)
            ->where(SitemapUrl::schema_fields_MODULE, $module)
            ->select()
            ->fetchArray();

        $existing = [];
        foreach ($rows as $row) {
            $urlKey = trim((string)($row[SitemapUrl::schema_fields_URL_KEY] ?? ''));
            if ($urlKey === '') {
                $entityType = trim((string)($row[SitemapUrl::schema_fields_ENTITY_TYPE] ?? ''));
                $entityId = (int)($row[SitemapUrl::schema_fields_ENTITY_ID] ?? 0);
                $urlKey = $entityType !== '' && $entityId > 0 ? $entityType . '-' . $entityId : sha1((string)($row[SitemapUrl::schema_fields_URL] ?? ''));
            }
            $existing[$urlKey] = $row;
        }

        return $existing;
    }

    /**
     * @param array<string, array<string, mixed>> $newUrls
     * @param array<string, array<string, mixed>> $existingUrls
     * @return array<string, mixed>
     */
    private function performIncrementalUpdate(
        int $websiteId,
        string $scope,
        string $module,
        array $newUrls,
        array $existingUrls
    ): array {
        $stats = $this->emptyStats();
        $stats['total'] = count($newUrls);

        foreach ($newUrls as $urlKey => $urlData) {
            if (isset($existingUrls[$urlKey])) {
                $existingRow = $existingUrls[$urlKey];
                if ($this->needsUpdate($urlData, $existingRow)) {
                    $this->updateUrl((int)$existingRow[SitemapUrl::schema_fields_ID], $urlData);
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            } else {
                $this->insertUrl($websiteId, $scope, $module, $urlData);
                $stats['inserted']++;
            }
        }

        foreach ($existingUrls as $urlKey => $existingRow) {
            if (!isset($newUrls[$urlKey]) && (int)($existingRow[SitemapUrl::schema_fields_STATUS] ?? 1) !== 0) {
                $this->disableUrl((int)$existingRow[SitemapUrl::schema_fields_ID]);
                $stats['disabled']++;
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $newData
     * @param array<string, mixed> $existingRow
     */
    private function needsUpdate(array $newData, array $existingRow): bool
    {
        $comparisons = [
            'url_key' => SitemapUrl::schema_fields_URL_KEY,
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

    /**
     * @param array<string, mixed> $urlData
     */
    private function insertUrl(int $websiteId, string $scope, string $module, array $urlData): void
    {
        $model = clone $this->sitemapUrl;
        $model->reset()->setData([
            SitemapUrl::schema_fields_WEBSITE_ID => $websiteId,
            SitemapUrl::schema_fields_SCOPE => $scope,
            SitemapUrl::schema_fields_MODULE => $module,
            SitemapUrl::schema_fields_URL_KEY => $urlData['url_key'],
            SitemapUrl::schema_fields_ENTITY_TYPE => $urlData['entity_type'] ?? '',
            SitemapUrl::schema_fields_ENTITY_ID => $urlData['entity_id'] ?? 0,
            SitemapUrl::schema_fields_URL => $urlData['url'],
            SitemapUrl::schema_fields_LASTMOD => $urlData['lastmod'],
            SitemapUrl::schema_fields_CHANGEFREQ => $urlData['changefreq'],
            SitemapUrl::schema_fields_PRIORITY => $urlData['priority'],
            SitemapUrl::schema_fields_METADATA => $urlData['metadata'] ?? '',
            SitemapUrl::schema_fields_STATUS => 1,
        ])->save();
    }

    /**
     * @param array<string, mixed> $urlData
     */
    private function updateUrl(int $id, array $urlData): void
    {
        $model = clone $this->sitemapUrl;
        $model->reset()->load($id);
        $model->setData([
            SitemapUrl::schema_fields_URL_KEY => $urlData['url_key'],
            SitemapUrl::schema_fields_ENTITY_TYPE => $urlData['entity_type'] ?? '',
            SitemapUrl::schema_fields_ENTITY_ID => $urlData['entity_id'] ?? 0,
            SitemapUrl::schema_fields_URL => $urlData['url'],
            SitemapUrl::schema_fields_LASTMOD => $urlData['lastmod'],
            SitemapUrl::schema_fields_CHANGEFREQ => $urlData['changefreq'],
            SitemapUrl::schema_fields_PRIORITY => $urlData['priority'],
            SitemapUrl::schema_fields_METADATA => $urlData['metadata'] ?? '',
            SitemapUrl::schema_fields_STATUS => 1,
        ])->save();
    }

    private function disableUrl(int $id): void
    {
        $model = clone $this->sitemapUrl;
        $model->reset()->load($id);
        $model->setData(SitemapUrl::schema_fields_STATUS, 0)->save();
    }

    /**
     * @param array<string, mixed> $url
     */
    private function normalizeSitemapMetadata(array $url): string
    {
        $metadata = [];
        if (isset($url['metadata']) && is_array($url['metadata'])) {
            $metadata = $url['metadata'];
        } elseif (isset($url['metadata']) && is_string($url['metadata'])) {
            $decoded = json_decode($url['metadata'], true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (isset($url['sitemap']) && is_array($url['sitemap'])) {
            $metadata = array_replace_recursive($metadata, $url['sitemap']);
        }
        foreach (['images', 'image', 'videos', 'video', 'news', 'alternates', 'hreflang'] as $key) {
            if (array_key_exists($key, $url)) {
                $metadata[$key] = $url[$key];
            }
        }

        return $metadata === [] ? '' : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function hasChanges(array $stats): bool
    {
        return ((int)($stats['inserted'] ?? 0) + (int)($stats['updated'] ?? 0) + (int)($stats['disabled'] ?? 0)) > 0;
    }

    private function moduleKey(string $module, string $scope): string
    {
        return $scope !== '' ? $module . '_' . $scope : $module;
    }
}
