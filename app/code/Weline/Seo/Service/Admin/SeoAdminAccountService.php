<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Admin;

use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Seo\Service\Database\SeoTransactionRunner;
use Weline\Seo\Model\SeoWebsiteStats;
use Weline\Seo\Service\SeoPlatformCapabilityService;
use Weline\Seo\Service\SeoWebsiteAccountBindingService;
use Weline\Seo\Service\SeoWebsiteDirectory;
use Weline\Seo\Service\SitemapAdapterRegistry;

final class SeoAdminAccountService
{
    public function __construct(
        private readonly SeoAccount $accounts,
        private readonly SeoWebsiteAccount $websiteAccounts,
        private readonly SeoWebsiteStats $stats,
        private readonly SeoWebsiteDirectory $websiteDirectory,
        private readonly SeoWebsiteAccountBindingService $bindings,
        private readonly SitemapAdapterRegistry $adapters,
        private readonly SeoPlatformCapabilityService $capabilities,
        private readonly SeoTransactionRunner $transactions,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function listAccounts(string $scope = ''): array
    {
        $query = $this->accounts->reset()->select();
        if (trim($scope) !== '') {
            $query->where(SeoAccount::schema_fields_SCOPE, trim($scope));
        }
        $rows = $query->order(SeoAccount::schema_fields_CREATED_AT, 'DESC')->fetchArray();
        foreach ($rows as &$row) {
            $accountId = (int)($row[SeoAccount::schema_fields_ID] ?? 0);
            $bindings = $accountId > 0
                ? $this->websiteAccounts->reset()->where(SeoWebsiteAccount::schema_fields_ACCOUNT_ID, $accountId)->select()->fetchArray()
                : [];
            $row['bound_websites_count'] = count($bindings);
            $row['stats'] = $this->aggregateStats($accountId);
            $row[SeoAccount::schema_fields_CONFIG] = $this->maskConfig($row[SeoAccount::schema_fields_CONFIG] ?? '');
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function saveAccount(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? $params['id'] ?? 0);
        $name = trim((string)($params['name'] ?? ''));
        $platform = strtolower(trim((string)($params['platform'] ?? '')));
        if ($name === '' || strlen($name) > 190) {
            throw new \InvalidArgumentException((string)__('账户名称不能为空且不能超过 190 字节'));
        }
        if ($platform === '' || $this->adapters->getAdapter($platform) === null) {
            throw new \InvalidArgumentException((string)__('平台未注册：%{1}', $platform));
        }
        $capability = $this->capabilities->getCapability($platform) ?? [];
        $configProvided = (array_key_exists('config', $params) && $params['config'] !== null)
            || (array_key_exists('config_json', $params) && trim((string)$params['config_json']) !== '');
        $config = $this->decodeConfig($params['config'] ?? $params['config_json'] ?? null);

        return $this->transactions->run($this->accounts->getConnection(), function () use (
            $accountId,
            $name,
            $platform,
            $params,
            $capability,
            $configProvided,
            $config,
        ): array {
            $account = clone $this->accounts;
            $account->reset();
            if ($accountId > 0) {
                $account->load($accountId);
                if (!$account->getId()) {
                    throw new \InvalidArgumentException((string)__('账户不存在'));
                }
            }
            $enablePush = !empty($capability['supports_url_push']) ? (int)!empty($params['enable_cron_push_urls']) : 0;
            $enableSitemap = !empty($capability['supports_sitemap_submit']) ? (int)!empty($params['enable_cron_sitemap']) : 0;
            $account->setData(SeoAccount::schema_fields_NAME, $name)
                ->setData(SeoAccount::schema_fields_PLATFORM, $platform)
                ->setData(SeoAccount::schema_fields_PROVIDER, $platform)
                ->setData(SeoAccount::schema_fields_SCOPE, trim((string)($params['scope'] ?? '')))
                ->setData(SeoAccount::schema_fields_DESCRIPTION, trim((string)($params['description'] ?? '')))
                ->setData(SeoAccount::schema_fields_IS_ACTIVE, (int)($params['is_active'] ?? SeoAccount::STATUS_ACTIVE))
                ->setData(SeoAccount::schema_fields_ENABLE_CRON_PUSH_URLS, $enablePush)
                ->setData(SeoAccount::schema_fields_ENABLE_CRON_SITEMAP, $enableSitemap);
            if ($configProvided) {
                $account->setConfigArray($config);
            }
            $account->save();
            return $this->result(__('账户保存成功'), ['account_id' => (int)$account->getId()]);
        });
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function saveWebsiteBindings(array $params): array
    {
        if (array_key_exists('account_id', $params) && array_key_exists('website_ids', $params)) {
            return $this->saveAccountWebsiteBindings((int)$params['account_id'], $params['website_ids']);
        }
        if (array_key_exists('website_id', $params) && array_key_exists('account_ids', $params)) {
            return $this->saveWebsiteAccountBindings((int)$params['website_id'], $params['account_ids'], $params['configs'] ?? []);
        }
        throw new \InvalidArgumentException((string)__('绑定参数必须包含 account_id+website_ids 或 website_id+account_ids'));
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function saveWebsiteConfig(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        $websiteId = $this->normalizeWebsiteId($params['website_id'] ?? null);
        $config = is_array($params['config'] ?? null) ? $params['config'] : $params;
        $this->assertAccount($accountId);
        return $this->transactions->run($this->websiteAccounts->getConnection(), function () use ($accountId, $websiteId, $config): array {
            $binding = $this->websiteAccounts->reset()
                ->where(SeoWebsiteAccount::schema_fields_ACCOUNT_ID, $accountId)
                ->where(SeoWebsiteAccount::schema_fields_WEBSITE_ID, $websiteId)
                ->find()->fetch();
            if (!$binding->getId()) {
                throw new \InvalidArgumentException((string)__('该账户未绑定此站点'));
            }
            $binding->setData($this->normalizeBindingConfig($config))->save();
            return $this->result(__('配置保存成功'), ['website_id' => $websiteId, 'account_id' => $accountId]);
        });
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function unbindWebsite(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        $websiteId = $this->normalizeWebsiteId($params['website_id'] ?? null);
        $this->assertAccount($accountId);
        return $this->transactions->run($this->websiteAccounts->getConnection(), function () use ($websiteId, $accountId): array {
            if (!$this->websiteAccounts->unbindWebsiteAccount($websiteId, $accountId)) {
                throw new \InvalidArgumentException((string)__('绑定关系不存在'));
            }
            return $this->result(__('解绑成功'), ['website_id' => $websiteId, 'account_id' => $accountId]);
        });
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function syncAccountStats(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        $account = $this->assertAccount($accountId);
        $platform = $account->getPlatform();
        $adapter = $platform !== '' ? $this->adapters->getAdapter($platform) : null;
        if ($adapter === null || !$adapter->supportsStats()) {
            throw new \InvalidArgumentException((string)__('该平台不支持统计数据获取'));
        }
        $bindings = $this->websiteAccounts->reset()
            ->where(SeoWebsiteAccount::schema_fields_ACCOUNT_ID, $accountId)
            ->select()->fetchArray();
        if ($bindings === []) {
            throw new \InvalidArgumentException((string)__('该账户没有绑定任何站点'));
        }
        $synced = 0;
        $errors = [];
        foreach ($bindings as $binding) {
            $websiteId = (int)($binding[SeoWebsiteAccount::schema_fields_WEBSITE_ID] ?? -1);
            if ($websiteId < 0) {
                continue;
            }
            $website = $this->websiteDirectory->getWebsiteById($websiteId);
            $siteUrl = trim((string)($website['url'] ?? ''));
            if (!is_array($website) || $siteUrl === '') {
                $errors[] = __('站点 %{1} 不存在或没有 URL', $websiteId);
                continue;
            }
            $result = $adapter->getStats($siteUrl, ['config' => $account->getConfigArray()]);
            if (!empty($result['success']) && is_array($result['data'] ?? null)) {
                $record = clone $this->stats;
                $record->reset()->getOrCreateTodayStats($websiteId, $accountId, $platform);
                $record->updateStats($result['data']);
                $synced++;
            } else {
                $errors[] = __('站点 %{1}：%{2}', [$websiteId, $result['message'] ?? __('未知错误')]);
            }
        }
        return [
            'success' => $synced > 0,
            'message' => $synced > 0 ? __('成功同步 %{1} 个站点的统计数据', $synced) : __('统计数据同步失败'),
            'data' => ['synced' => $synced],
            'errors' => $errors,
            'retryable' => $errors !== [],
        ];
    }

    /** @return array<string,mixed> */
    public function websiteBindingData(int $accountId): array
    {
        $this->assertAccount($accountId);
        $bindings = $this->websiteAccounts->reset()
            ->where(SeoWebsiteAccount::schema_fields_ACCOUNT_ID, $accountId)
            ->select()->fetchArray();
        $bound = [];
        $configs = [];
        foreach ($bindings as $binding) {
            $websiteId = (int)($binding[SeoWebsiteAccount::schema_fields_WEBSITE_ID] ?? -1);
            if ($websiteId < 0) {
                continue;
            }
            $bound[] = $websiteId;
            $configs[$websiteId] = [
                'sitemap_frequency' => $binding[SeoWebsiteAccount::schema_fields_SITEMAP_FREQUENCY] ?? SeoWebsiteAccount::DEFAULT_SITEMAP_FREQUENCY,
                'crawl_frequency' => $binding[SeoWebsiteAccount::schema_fields_CRAWL_FREQUENCY] ?? SeoWebsiteAccount::DEFAULT_CRAWL_FREQUENCY,
                'priority' => $binding[SeoWebsiteAccount::schema_fields_PRIORITY] ?? SeoWebsiteAccount::DEFAULT_PRIORITY,
                'is_auto_submit' => (int)($binding[SeoWebsiteAccount::schema_fields_IS_AUTO_SUBMIT] ?? 1),
                'enable_url_push' => (int)($binding[SeoWebsiteAccount::schema_fields_ENABLE_URL_PUSH] ?? 1),
            ];
        }
        return $this->result('', [
            'websites' => $this->websiteDirectory->listWebsites(),
            'bound_website_ids' => $bound,
            'configs' => $configs,
        ]);
    }

    /** @param mixed $websiteIds @return array<string,mixed> */
    private function saveAccountWebsiteBindings(int $accountId, mixed $websiteIds): array
    {
        $this->assertAccount($accountId);
        $normalized = $this->normalizeWebsiteIds($websiteIds);
        return $this->transactions->run($this->websiteAccounts->getConnection(), function () use ($accountId, $normalized): array {
            $existing = [];
            foreach ($this->websiteAccounts->reset()->where(SeoWebsiteAccount::schema_fields_ACCOUNT_ID, $accountId)->select()->fetchArray() as $binding) {
                $websiteId = (int)($binding[SeoWebsiteAccount::schema_fields_WEBSITE_ID] ?? -1);
                if ($websiteId >= 0) {
                    $existing[$websiteId] = $websiteId;
                }
            }
            $added = 0;
            $removed = 0;
            foreach (array_diff($existing, $normalized) as $websiteId) {
                $removed += $this->websiteAccounts->unbindWebsiteAccount($websiteId, $accountId) ? 1 : 0;
            }
            foreach (array_diff($normalized, $existing) as $websiteId) {
                $this->websiteAccounts->bindWebsiteAccount($websiteId, $accountId);
                $added++;
            }
            return $this->result(__('站点绑定保存成功'), ['added' => $added, 'removed' => $removed]);
        });
    }

    /** @param mixed $accountIds @param mixed $configs @return array<string,mixed> */
    private function saveWebsiteAccountBindings(int $websiteId, mixed $accountIds, mixed $configs): array
    {
        $websiteId = $this->normalizeWebsiteId($websiteId);
        $accountIds = is_array($accountIds) ? array_values(array_unique(array_map('intval', $accountIds))) : [];
        foreach ($accountIds as $accountId) {
            $this->assertAccount($accountId);
        }
        $configs = is_array($configs) ? $configs : [];
        return $this->transactions->run($this->websiteAccounts->getConnection(), function () use ($websiteId, $accountIds, $configs): array {
            foreach ($this->bindings->getBindingsByWebsite($websiteId) as $binding) {
                $accountId = (int)($binding[SeoWebsiteAccount::schema_fields_ACCOUNT_ID] ?? 0);
                if ($accountId > 0) {
                    $this->websiteAccounts->unbindWebsiteAccount($websiteId, $accountId);
                }
            }
            foreach ($accountIds as $accountId) {
                $config = is_array($configs[$accountId] ?? null) ? $configs[$accountId] : [];
                $this->websiteAccounts->bindWebsiteAccount($websiteId, $accountId, $this->normalizeBindingConfig($config));
            }
            return $this->result(__('绑定配置保存成功，共绑定 %{1} 个SEO账户', count($accountIds)), ['saved' => count($accountIds)]);
        });
    }

    /** @return array<string,mixed> */
    private function aggregateStats(int $accountId): array
    {
        $totals = ['indexed_pages' => 0, 'submitted_urls' => 0, 'clicks' => 0, 'impressions' => 0, 'error_count' => 0, 'last_sync_at' => null];
        if ($accountId <= 0) {
            return $totals;
        }
        $seen = [];
        foreach ($this->stats->reset()->where(SeoWebsiteStats::schema_fields_ACCOUNT_ID, $accountId)->order(SeoWebsiteStats::schema_fields_STATS_DATE, 'DESC')->select()->fetchArray() as $row) {
            $websiteId = (int)($row[SeoWebsiteStats::schema_fields_WEBSITE_ID] ?? -1);
            if ($websiteId < 0 || isset($seen[$websiteId])) {
                continue;
            }
            $seen[$websiteId] = true;
            foreach (['indexed_pages', 'submitted_urls', 'clicks', 'impressions', 'error_count'] as $field) {
                $totals[$field] += (int)($row[$field] ?? 0);
            }
            $syncAt = $row[SeoWebsiteStats::schema_fields_LAST_SYNC_AT] ?? null;
            if ($syncAt && (!$totals['last_sync_at'] || $syncAt > $totals['last_sync_at'])) {
                $totals['last_sync_at'] = $syncAt;
            }
        }
        return $totals;
    }

    private function assertAccount(int $accountId): SeoAccount
    {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException((string)__('账户ID无效'));
        }
        $account = clone $this->accounts;
        $account->reset()->load($accountId);
        if (!$account->getId()) {
            throw new \InvalidArgumentException((string)__('账户不存在'));
        }
        return $account;
    }

    /** @return list<int> */
    private function normalizeWebsiteIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            throw new \InvalidArgumentException((string)__('website_ids 必须是数组'));
        }
        $normalized = [];
        foreach ($ids as $id) {
            $websiteId = $this->normalizeWebsiteId($id);
            $normalized[$websiteId] = $websiteId;
        }
        return array_values($normalized);
    }

    private function normalizeWebsiteId(mixed $value): int
    {
        if (!(is_int($value) || (is_string($value) && preg_match('/^\d+$/D', $value) === 1))) {
            throw new \InvalidArgumentException((string)__('website_id 必须是非负整数'));
        }
        $websiteId = (int)$value;
        if ($websiteId < 0 || $this->websiteDirectory->getWebsiteById($websiteId) === null) {
            throw new \InvalidArgumentException((string)__('站点不存在：%{1}', $websiteId));
        }
        return $websiteId;
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function normalizeBindingConfig(array $config): array
    {
        $sitemapFrequency = (string)($config['sitemap_frequency'] ?? SeoWebsiteAccount::DEFAULT_SITEMAP_FREQUENCY);
        if (!in_array($sitemapFrequency, ['realtime', 'hourly', 'daily', 'weekly', 'monthly', 'manual'], true)) {
            throw new \InvalidArgumentException((string)__('Sitemap 频率无效'));
        }
        $crawlFrequency = (string)($config['crawl_frequency'] ?? SeoWebsiteAccount::DEFAULT_CRAWL_FREQUENCY);
        if (!in_array($crawlFrequency, ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'], true)) {
            throw new \InvalidArgumentException((string)__('抓取频率无效'));
        }
        $priority = (float)($config['priority'] ?? SeoWebsiteAccount::DEFAULT_PRIORITY);
        if ($priority < 0 || $priority > 1) {
            throw new \InvalidArgumentException((string)__('优先级必须在 0 到 1 之间'));
        }
        return [
            SeoWebsiteAccount::schema_fields_SITEMAP_FREQUENCY => $sitemapFrequency,
            SeoWebsiteAccount::schema_fields_CRAWL_FREQUENCY => $crawlFrequency,
            SeoWebsiteAccount::schema_fields_PRIORITY => $priority,
            SeoWebsiteAccount::schema_fields_IS_AUTO_SUBMIT => array_key_exists('is_auto_submit', $config)
                ? (int)!empty($config['is_auto_submit'])
                : 1,
            SeoWebsiteAccount::schema_fields_ENABLE_URL_PUSH => array_key_exists('enable_url_push', $config)
                ? (int)!empty($config['enable_url_push'])
                : 1,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeConfig(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException((string)__('账户配置必须是 JSON 对象'));
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException((string)__('账户配置必须是 JSON 对象'));
        }
        return $decoded;
    }

    private function maskConfig(mixed $value): array
    {
        $config = [];
        try {
            $config = $this->decodeConfig($value);
        } catch (\Throwable) {
            return [];
        }
        $mask = static function (mixed $item, string $key = '') use (&$mask): mixed {
            if (preg_match('/token|secret|password|credential|api[_-]?key/i', $key) === 1) {
                return $item === null || $item === '' ? '' : '••••••••';
            }
            if (is_array($item)) {
                foreach ($item as $childKey => $child) {
                    $item[$childKey] = $mask($child, (string)$childKey);
                }
            }
            return $item;
        };
        return $mask($config);
    }

    /** @return array<string,mixed> */
    private function result(string $message, array $data): array
    {
        return ['success' => true, 'message' => $message, 'data' => $data, 'errors' => [], 'retryable' => false];
    }
}
