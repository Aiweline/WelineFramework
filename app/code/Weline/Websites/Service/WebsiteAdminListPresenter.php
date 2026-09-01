<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Model\Website;

/**
 * 网站后台列表行展示：合并列与 Store/Channel 摘要。
 */
final class WebsiteAdminListPresenter
{
    /**
     * @param array<string, mixed> $website enrichWebsiteListingItems 之后的行
     * @return array<string, mixed>
     */
    public function presentRow(array $website): array
    {
        $websiteId = (int)($website['website_id'] ?? 0);
        $name = trim((string)($website['name'] ?? ''));
        $code = trim((string)($website['code'] ?? ''));
        $timezone = trim((string)($website['default_timezone'] ?? ''));

        $siteHeadParts = [];
        if ($name !== '') {
            $siteHeadParts[] = $name;
        }
        if ($code !== '') {
            $siteHeadParts[] = $code;
        }
        $siteHeadParts[] = '#' . $websiteId;
        $siteHead = implode(' · ', $siteHeadParts);

        $siteMetaParts = [];
        if ($timezone !== '') {
            $siteMetaParts[] = $timezone;
        }
        if ($websiteId === Website::ID_DEFAULT || $code === Website::CODE_DEFAULT) {
            $siteMetaParts[] = (string)__('默认站点');
        }

        $frontendUrl = trim((string)($website['frontend_url'] ?? ''));
        if ($frontendUrl === '') {
            $frontendUrl = trim((string)($website['url'] ?? ''));
        }
        $domainList = is_array($website['domain_list'] ?? null) ? $website['domain_list'] : [];
        $accessEntry = $this->formatAccessEntry($frontendUrl, $domainList);

        $currencyCodes = is_array($website['currency_codes'] ?? null) ? $website['currency_codes'] : [];
        $languageCodes = is_array($website['language_codes'] ?? null) ? $website['language_codes'] : [];
        $defaultCurrency = trim((string)($website['default_currency'] ?? ''));

        $storeChannelDirectory = is_array($website['store_channel_directory'] ?? null)
            ? $website['store_channel_directory']
            : [];

        return [
            'website_id' => $websiteId,
            'site_head' => $siteHead !== '' ? $siteHead : '—',
            'site_meta' => $siteMetaParts !== [] ? implode(' · ', $siteMetaParts) : '—',
            'access_entry' => $accessEntry,
            'market_cluster' => $this->formatMarketCluster($languageCodes, $currencyCodes, $defaultCurrency),
            'store_channel_summary' => $this->formatStoreChannelSummary($storeChannelDirectory),
            'frontend_url' => $frontendUrl,
            'backend_url' => trim((string)($website['backend_url'] ?? '')),
        ];
    }

    /**
     * @param list<array<string, mixed>> $domainList
     */
    private function formatAccessEntry(string $frontendUrl, array $domainList): string
    {
        if ($frontendUrl === '') {
            return '—';
        }

        $domainCount = count($domainList);
        if ($domainCount > 1) {
            return $frontendUrl . ' · +' . ($domainCount - 1) . ' ' . (string)__('域名');
        }

        return $frontendUrl;
    }

    /**
     * @param list<string> $languageCodes
     * @param list<string> $currencyCodes
     */
    private function formatMarketCluster(array $languageCodes, array $currencyCodes, string $defaultCurrency): string
    {
        if ($languageCodes === []) {
            $languageLabel = (string)__('全部语言');
        } elseif (count($languageCodes) <= 2) {
            $languageLabel = implode(', ', array_map('strval', $languageCodes));
        } else {
            $languageLabel = (string)__('%{1} 种语言', [count($languageCodes)]);
        }

        if ($currencyCodes === []) {
            $currencyLabel = (string)__('全部货币');
        } elseif ($defaultCurrency !== '') {
            $currencyLabel = (string)__('默认 %{1}', [$defaultCurrency]);
        } elseif (count($currencyCodes) <= 2) {
            $currencyLabel = implode(', ', array_map('strval', $currencyCodes));
        } else {
            $currencyLabel = (string)__('%{1} 种货币', [count($currencyCodes)]);
        }

        return $languageLabel . ' · ' . $currencyLabel;
    }

    /**
     * @param list<array<string, mixed>> $directory
     */
    private function formatStoreChannelSummary(array $directory): string
    {
        if ($directory === []) {
            return (string)__('暂无 Store/Channel');
        }

        $storeCount = count($directory);
        $channelCount = 0;
        $highlightStore = '';

        foreach ($directory as $store) {
            $channels = is_array($store['channels'] ?? null) ? $store['channels'] : [];
            $channelCount += count($channels);

            if ($highlightStore === '' && !empty($store['is_default'])) {
                $highlightStore = $this->formatStoreLabel($store);
            }
        }

        if ($highlightStore === '') {
            $highlightStore = $this->formatStoreLabel($directory[0]);
        }

        $summary = (string)__(
            '%{1} Store · %{2} Channel',
            [$storeCount, $channelCount],
        );

        if ($highlightStore !== '') {
            $summary .= ' · ' . $highlightStore;
        }

        return $summary;
    }

    /** @param array<string, mixed> $store */
    private function formatStoreLabel(array $store): string
    {
        $name = trim((string)($store['name'] ?? ''));
        $code = trim((string)($store['code'] ?? ''));
        if ($name !== '' && $code !== '') {
            return $name . '/' . $code;
        }

        return $name !== '' ? $name : $code;
    }
}
