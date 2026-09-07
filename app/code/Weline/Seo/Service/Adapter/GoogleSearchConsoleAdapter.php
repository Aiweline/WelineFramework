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
            'accepted' => false,
            'status' => 'unsupported',
            'message' => __('Google 普通页面请使用 Search Console Sitemap 提交；Indexing API 仅适用于招聘信息或直播视频'),
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
                'sensitive' => true,
                'required' => true,
                'accept' => '.json,application/json',
                'placeholder' => '{"type":"service_account","project_id":"..."}',
                'hint' => (string)__('先下载 JSON；把其中的 client_email 在 GSC「用户和权限」加成所有者后，再粘贴到此处'),
            ],
            [
                'key' => 'site_url',
                'label' => (string)__('Search Console 站点属性 URL'),
                'type' => 'text',
                'required' => true,
                'placeholder' => 'https://www.example.com/ 或 sc-domain:example.com',
                'hint' => (string)__('可填 https 域名，保存时自动转为 sc-domain:example.com（去掉 www）。也可直接填 GSC 域名属性。请先在 GSC 验证属性并把服务账号加成所有者，再点验证'),
            ],
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
