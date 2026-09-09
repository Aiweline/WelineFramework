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
                'hint' => (string)__('须与 GSC 左侧属性名完全一致。可填 https 域名，保存时自动转为 sc-domain:example.com（去掉 www）。请先在 GSC 验证属性并把服务账号加成所有者，再点验证'),
            ],
            [
                'key' => '__section_discover',
                'label' => (string)__('Discover / News 监测'),
                'type' => 'section',
                'required' => false,
                'hint' => (string)__('使用现有网站属性，经 Search Analytics 的 type=discover / googleNews 拉取；无需新建账户类型'),
            ],
            [
                'key' => 'enable_discover_stats',
                'label' => (string)__('启用 Discover 统计'),
                'type' => 'checkbox',
                'required' => false,
                'hint' => (string)__('定时同步时额外拉取 Discover 点击/展示，写入统计 extra.discover'),
            ],
            [
                'key' => 'enable_google_news_stats',
                'label' => (string)__('启用 Google News 统计'),
                'type' => 'checkbox',
                'required' => false,
                'hint' => (string)__('可选；新闻向站点再开启。写入统计 extra.google_news'),
            ],
            [
                'key' => '__section_channels',
                'label' => (string)__('分发渠道（Platform Property）'),
                'type' => 'section',
                'required' => false,
                'hint' => (string)__('为什么配置：在 Google Search Console 把官方社交/视频主页登记为「平台属性」，便于 Google 识别品牌官方渠道并对照表现。本系统只存 URL 作清单，不会自动分发内容，也不会直接提高排名或信任分；请在 GSC 手动添加平台属性（官方 API 尚未开放拉取）。'),
            ],
            [
                'key' => 'youtube_channel_url',
                'label' => 'YouTube',
                'type' => 'url',
                'required' => false,
                'group' => 'channels',
                'placeholder' => 'https://www.youtube.com/@brand',
                'hint' => (string)__('对应 GSC 平台属性 YouTube'),
            ],
            [
                'key' => 'x_profile_url',
                'label' => 'X / Twitter',
                'type' => 'url',
                'required' => false,
                'group' => 'channels',
                'placeholder' => 'https://x.com/brand',
                'hint' => (string)__('对应 GSC 平台属性 X'),
            ],
            [
                'key' => 'instagram_profile_url',
                'label' => 'Instagram',
                'type' => 'url',
                'required' => false,
                'group' => 'channels',
                'placeholder' => 'https://www.instagram.com/brand',
                'hint' => (string)__('对应 GSC 平台属性 Instagram'),
            ],
            [
                'key' => 'tiktok_profile_url',
                'label' => 'TikTok',
                'type' => 'url',
                'required' => false,
                'group' => 'channels',
                'placeholder' => 'https://www.tiktok.com/@brand',
                'hint' => (string)__('对应 GSC 平台属性 TikTok'),
            ],
            [
                'key' => 'linkedin_profile_url',
                'label' => 'LinkedIn',
                'type' => 'url',
                'required' => false,
                'group' => 'channels',
                'placeholder' => 'https://www.linkedin.com/company/brand',
                'hint' => (string)__('品牌登记用；GSC 官方四平台以外仅本地存档'),
            ],
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
