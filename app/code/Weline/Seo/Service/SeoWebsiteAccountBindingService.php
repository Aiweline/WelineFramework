<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoWebsiteAccount;

class SeoWebsiteAccountBindingService
{
    public function __construct(
        private readonly SeoWebsiteAccount $websiteAccount,
        private readonly SeoAccount $seoAccount,
        private readonly SitemapAdapterRegistry $sitemapAdapterRegistry,
        private readonly SeoPlatformCapabilityService $capabilityService
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getBindingsByWebsite(int $websiteId): array
    {
        if ($websiteId <= 0) {
            return [];
        }

        return $this->websiteAccount->reset()
            ->where(SeoWebsiteAccount::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetchArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBindingMapByWebsite(int $websiteId): array
    {
        $map = [];
        foreach ($this->getBindingsByWebsite($websiteId) as $binding) {
            $accountId = (int)($binding[SeoWebsiteAccount::schema_fields_ACCOUNT_ID] ?? 0);
            if ($accountId > 0) {
                $map[$accountId] = $binding;
            }
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    public function getBindingCounts(array $websites): array
    {
        $counts = [];
        foreach ($websites as $website) {
            if (!is_array($website)) {
                continue;
            }
            $websiteId = (int)($website['website_id'] ?? $website['id'] ?? 0);
            if ($websiteId > 0) {
                $counts[$websiteId] = count($this->getBindingsByWebsite($websiteId));
            }
        }

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWebsiteAccountsWithPlatforms(int $websiteId, bool $activeOnly = true): array
    {
        $result = [];

        foreach ($this->getBindingsByWebsite($websiteId) as $binding) {
            $accountId = (int)($binding[SeoWebsiteAccount::schema_fields_ACCOUNT_ID] ?? 0);
            if ($accountId <= 0) {
                continue;
            }

            $account = $this->loadAccountData($accountId);
            if ($account === null) {
                continue;
            }
            if ($activeOnly && (int)($account[SeoAccount::schema_fields_IS_ACTIVE] ?? 0) !== SeoAccount::STATUS_ACTIVE) {
                continue;
            }

            $platformCode = $this->resolvePlatformCode($account);
            $adapter = $platformCode !== '' ? $this->sitemapAdapterRegistry->getAdapter($platformCode) : null;
            $capability = $platformCode !== '' ? $this->capabilityService->getCapability($platformCode) : null;

            $result[] = [
                'account' => $account,
                'account_id' => $accountId,
                'account_config' => $this->decodeConfig($account[SeoAccount::schema_fields_CONFIG] ?? ''),
                'platform_code' => $platformCode,
                'adapter' => $adapter,
                'binding' => $binding,
                'capability' => $capability,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, \Weline\Seo\Interface\SitemapPlatformAdapterInterface>
     */
    public function getWebsiteAdapters(int $websiteId): array
    {
        $adapters = [];
        foreach ($this->getWebsiteAccountsWithPlatforms($websiteId) as $info) {
            $platformCode = (string)($info['platform_code'] ?? '');
            $adapter = $info['adapter'] ?? null;
            if ($platformCode !== '' && $adapter !== null && !isset($adapters[$platformCode])) {
                $adapters[$platformCode] = $adapter;
            }
        }

        return $adapters;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUrlPushAccounts(int $websiteId): array
    {
        $accounts = [];
        foreach ($this->getWebsiteAccountsWithPlatforms($websiteId) as $info) {
            $account = (array)($info['account'] ?? []);
            $binding = (array)($info['binding'] ?? []);
            $capability = (array)($info['capability'] ?? []);

            if ((int)($account[SeoAccount::schema_fields_ENABLE_CRON_PUSH_URLS] ?? 0) !== 1) {
                continue;
            }
            if ((int)($binding[SeoWebsiteAccount::schema_fields_ENABLE_URL_PUSH] ?? 1) !== 1) {
                continue;
            }
            if (empty($capability['supports_url_push'])) {
                continue;
            }

            $accounts[] = $info;
        }

        return $accounts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSitemapSubmitAccounts(int $websiteId): array
    {
        $accounts = [];
        foreach ($this->getWebsiteAccountsWithPlatforms($websiteId) as $info) {
            $account = (array)($info['account'] ?? []);
            $binding = (array)($info['binding'] ?? []);
            $adapter = $info['adapter'] ?? null;

            if ((int)($account[SeoAccount::schema_fields_ENABLE_CRON_SITEMAP] ?? 0) !== 1) {
                continue;
            }
            if ((int)($binding[SeoWebsiteAccount::schema_fields_IS_AUTO_SUBMIT] ?? 0) !== 1) {
                continue;
            }
            if ($adapter === null || !$adapter->supportsAutoSubmit()) {
                continue;
            }

            $accounts[] = $info;
        }

        return $accounts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getStatsAccounts(): array
    {
        $accounts = [];
        $websiteIds = [];
        foreach ($this->websiteAccount->reset()->select()->fetchArray() as $binding) {
            $websiteId = (int)($binding[SeoWebsiteAccount::schema_fields_WEBSITE_ID] ?? 0);
            if ($websiteId <= 0) {
                continue;
            }
            $websiteIds[$websiteId] = $websiteId;
        }

        foreach ($websiteIds as $websiteId) {
            foreach ($this->getWebsiteAccountsWithPlatforms($websiteId) as $info) {
                $adapter = $info['adapter'] ?? null;
                if ($adapter !== null && $adapter->supportsStats()) {
                    $accounts[] = $info + ['website_id' => $websiteId];
                }
            }
        }

        return $accounts;
    }

    /**
     * @param array<string, mixed> $account
     */
    public function resolvePlatformCode(array $account): string
    {
        $platformCode = trim((string)($account[SeoAccount::schema_fields_PLATFORM] ?? ''));
        if ($platformCode !== '') {
            return $platformCode;
        }

        return (string)($this->sitemapAdapterRegistry->extractPlatformFromProvider(
            (string)($account[SeoAccount::schema_fields_PROVIDER] ?? '')
        ) ?? '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadAccountData(int $accountId): ?array
    {
        $account = $this->seoAccount->reset()->load($accountId);
        if (!$account->getId()) {
            return null;
        }

        return $account->getData();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(mixed $config): array
    {
        if (is_array($config)) {
            return $config;
        }
        if (!is_string($config) || trim($config) === '') {
            return [];
        }

        $decoded = json_decode($config, true);
        return is_array($decoded) ? $decoded : [];
    }
}
