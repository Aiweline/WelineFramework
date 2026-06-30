<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Adapter;

use Weline\Seo\Adapter\GoogleSitemapAdapter;
use Weline\Seo\Interface\SearchEngineAdapterInterface;

class GoogleSearchConsoleAdapter implements SearchEngineAdapterInterface
{
    public function getCode(): string
    {
        return 'google_search_console';
    }

    public function getLabel(): string
    {
        return 'Google Search Console';
    }

    public function pushUrls(array $urls, array $options = []): array
    {
        return [
            'success' => false,
            'message' => __('Google Search Console API 不提供通用 URL 推送，请使用 Google Indexing API 或 Sitemap 提交'),
            'data' => [
                'urls' => array_values(array_filter(array_map('trim', $urls))),
            ],
        ];
    }

    public function submitSitemap(string $sitemapUrl, array $options = []): array
    {
        return (new GoogleSitemapAdapter())->submitSitemap($sitemapUrl, $options);
    }

    public function getRequirements(): array
    {
        return [
            'service_account' => __('Google Service Account JSON 凭据内容'),
            'site_url' => __('Search Console 中已验证的站点属性 URL'),
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
