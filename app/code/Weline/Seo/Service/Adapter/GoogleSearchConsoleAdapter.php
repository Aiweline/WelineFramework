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

    public function getAccountConfigFields(): array
    {
        return [
            [
                'key' => 'service_account',
                'label' => (string)__('Google Service Account JSON'),
                'type' => 'json',
                'required' => true,
                'accept' => '.json,application/json',
                'placeholder' => '{"type":"service_account","project_id":"..."}',
                'hint' => (string)__('可粘贴完整 JSON，或上传 Google Cloud 下载的密钥文件'),
            ],
            [
                'key' => 'site_url',
                'label' => (string)__('Search Console 站点属性 URL'),
                'type' => 'website_url',
                'required' => true,
                'placeholder' => 'https://www.example.com/',
                'hint' => (string)__('从网站列表选择；须与 Search Console 已验证站点属性一致'),
            ],
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
