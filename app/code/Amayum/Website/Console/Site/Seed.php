<?php

declare(strict_types=1);

namespace Amayum\Website\Console\Site;

use Amayum\Website\Service\GuideCatalog;
use Amayum\Website\Service\SiteProfileService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Seo\Model\SitemapUrl;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\Theme\Model\WelineTheme;
use Weline\UrlManager\Model\UrlRewrite;
use Weline\Theme\Service\WebsiteThemeBindingService;

/**
 * php bin/w amayum:site:seed --website=amayum --theme=amayum-official
 *
 * 幂等写入：站点资料 JSON、首页入口、主题绑定、10 条 sitemap URL。
 */
final class Seed extends CommandAbstract
{
    public const ALIASES = ['amayum:site:seed'];

    public function execute(array $args = [], array $data = []): mixed
    {
        $printer = ObjectManager::getInstance(Printing::class);
        $websiteCode = \strtolower(\trim((string)($args['website'] ?? 'amayum')));
        $themeName = \trim((string)($args['theme'] ?? 'amayum-official'));

        $website = w_query('websites', 'getWebsiteByCode', ['code' => $websiteCode, 'force_reload' => true]);
        if (!\is_array($website) || (int)($website['website_id'] ?? 0) <= 0) {
            $printer->error((string)__('站点不存在：%{1}；请先运行 website:create。', [$websiteCode]));

            return 1;
        }
        $websiteId = (int)$website['website_id'];

        $theme = ObjectManager::getInstance(WelineTheme::class)
            ->clear()
            ->where(WelineTheme::schema_fields_NAME, $themeName)
            ->find()
            ->fetchArray();
        if (!\is_array($theme) || (int)($theme[WelineTheme::schema_fields_ID] ?? 0) <= 0) {
            $printer->error((string)__('主题未安装：%{1}；请先运行 theme:install -t %{1}。', [$themeName]));

            return 1;
        }
        $themeId = (int)$theme[WelineTheme::schema_fields_ID];

        try {
            ObjectManager::getInstance(WebsiteThemeBindingService::class)
                ->bindThemeForWebsite($websiteId, $websiteCode, $themeId);
        } catch (\Throwable $exception) {
            $printer->error((string)__('主题绑定失败：%{1}', [$exception->getMessage()]));

            return 1;
        }

        /** @var SiteProfileService $siteProfile */
        $siteProfile = ObjectManager::getInstance(SiteProfileService::class);
        $defaults = $siteProfile->defaults();
        $store = ObjectManager::getInstance(ConfigStore::class);
        $scope = $siteProfile->scope($websiteCode);

        $configs = [
            SiteProfileService::KEY_PROFILE => $defaults['profile'] ?? [],
            SiteProfileService::KEY_NAV => $defaults['nav'] ?? [],
            SiteProfileService::KEY_FOOTER => $defaults['footer'] ?? [],
            SiteProfileService::KEY_PROOF_FACTS => $defaults['proof_facts'] ?? [],
            SiteProfileService::KEY_FLAGS => $defaults['flags'] ?? [],
        ];
        foreach ($configs as $key => $value) {
            $ok = $store->setScopedConfig(
                $key,
                $value,
                SiteProfileService::MODULE,
                SiteProfileService::AREA,
                $scope,
                'default',
                ['value_type' => 'json'],
            );
            if (!$ok) {
                $printer->error((string)__('站点资料写入失败：%{1}', [$key]));

                return 1;
            }
        }

        $ok = $store->setScopedConfig(
            'frontend_start_page_path',
            'amayum/frontend/homepage',
            'Weline_Websites',
            'frontend',
            'global',
            'default',
            ['scope_identity' => ScopeIdentity::website($websiteId, $websiteCode)],
        );
        if (!$ok) {
            $printer->error((string)__('首页入口写入失败：frontend_start_page_path'));

            return 1;
        }

        $sitemapCount = $this->seedSitemap($websiteId, $websiteCode, $siteProfile);
        if ($sitemapCount < 0) {
            $printer->error((string)__('sitemap URL 写入失败。'));

            return 1;
        }

        $this->seedPathRewrites($websiteId, $printer);

        foreach (['system_config', 'website', 'theme', 'router', 'module_router'] as $pool) {
            try {
                w_cache($pool)->clear();
            } catch (\Throwable) {
            }
        }

        $printer->success((string)__('Amayum 站点已 seed：website=%{1} theme=%{2} sitemap=%{3}', [
            $websiteCode,
            $themeName,
            (string)$sitemapCount,
        ]));

        return 0;
    }

    public function tip(): string
    {
        return (string)__('幂等写入 Amayum 站点资料、首页入口、主题绑定与 sitemap URL');
    }

    public function help(): array|string
    {
        return [
            'php bin/w amayum:site:seed --website=amayum --theme=amayum-official' => (string)__('站点资料 + frontend_start_page_path + 主题绑定 + 10 条 sitemap URL'),
        ];
    }

    private function seedSitemap(int $websiteId, string $websiteCode, SiteProfileService $siteProfile): int
    {
        $profile = $siteProfile->profile($websiteCode);
        $siteUrl = \rtrim((string)($profile['site_url'] ?? 'https://amayum.com'), '/');
        $today = \gmdate('Y-m-d');
        $guides = ObjectManager::getInstance(GuideCatalog::class)->all();

        $rows = [
            ['path' => '/', 'key' => 'home', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '1.0'],
            ['path' => '/commerce', 'key' => 'commerce', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '0.9'],
            ['path' => '/case-studies', 'key' => 'case-studies', 'lastmod' => $today, 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['path' => '/journal', 'key' => 'journal', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['path' => '/diagnosis', 'key' => 'contact', 'lastmod' => $today, 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['path' => '/legal', 'key' => 'legal', 'lastmod' => $today, 'changefreq' => 'yearly', 'priority' => '0.4'],
            ['path' => '/sitemap', 'key' => 'sitemap', 'lastmod' => $today, 'changefreq' => 'monthly', 'priority' => '0.4'],
        ];
        foreach ($guides as $slug => $guide) {
            $rows[] = [
                'path' => '/journal/' . $slug,
                'key' => 'journal-' . $slug,
                'lastmod' => (string)($guide['date_modified'] ?? $today),
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        /** @var SitemapUrl $model */
        $model = ObjectManager::getInstance(SitemapUrl::class);
        try {
            $model->clear()
                ->where(SitemapUrl::schema_fields_WEBSITE_ID, $websiteId)
                ->where(SitemapUrl::schema_fields_MODULE, SiteProfileService::MODULE)
                ->delete()
                ->fetch();

            foreach ($rows as $row) {
                $model->clear()->setData([
                    SitemapUrl::schema_fields_WEBSITE_ID => $websiteId,
                    SitemapUrl::schema_fields_MODULE => SiteProfileService::MODULE,
                    SitemapUrl::schema_fields_SCOPE => $websiteCode,
                    SitemapUrl::schema_fields_URL_KEY => (string)$row['key'],
                    SitemapUrl::schema_fields_LOCALE => 'zh_Hans_CN',
                    SitemapUrl::schema_fields_ENTITY_TYPE => 'page',
                    SitemapUrl::schema_fields_ENTITY_ID => 0,
                    SitemapUrl::schema_fields_URL => $siteUrl . (string)$row['path'],
                    SitemapUrl::schema_fields_CHANGEFREQ => (string)$row['changefreq'],
                    SitemapUrl::schema_fields_PRIORITY => (string)$row['priority'],
                    SitemapUrl::schema_fields_LASTMOD => (string)$row['lastmod'] . ' 00:00:00',
                    SitemapUrl::schema_fields_STATUS => 1,
                ])->save();
            }
        } catch (\Throwable) {
            return -1;
        }

        return \count($rows);
    }

    /**
     * `/contact` and `/sitemap` collide with same-named default theme layouts,
     * so `Weline_Theme`'s layout_resolve would claim them before this module's
     * router runs. A UrlRewrite row is applied earlier (seo_decode) and wins.
     */
    private function seedPathRewrites(int $websiteId, Printing $printer): void
    {
        $rows = [
            'contact' => 'diagnosis',
            'sitemap' => 'amayum/frontend/sitemap-page',
        ];

        try {
            $model = ObjectManager::getInstance(UrlRewrite::class);
            foreach ($rows as $prettyPath => $controllerPath) {
                $existing = clone $model;
                $existing->clear()->clearQuery()
                    ->where(UrlRewrite::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(UrlRewrite::schema_fields_REWRITE, $prettyPath)
                    ->find()
                    ->fetch();
                if ((int)$existing->getId() > 0) {
                    if ((string)$existing->getData(UrlRewrite::schema_fields_PATH) === $controllerPath) {
                        continue;
                    }
                    $existing->setData(UrlRewrite::schema_fields_PATH, $controllerPath)->save();
                    continue;
                }
                $model->clear()->setData([
                    UrlRewrite::schema_fields_PATH => $controllerPath,
                    UrlRewrite::schema_fields_REWRITE => $prettyPath,
                    UrlRewrite::schema_fields_URL_IDENTIFY => 'amayum-' . $prettyPath,
                    UrlRewrite::schema_fields_WEBSITE_ID => $websiteId,
                ])->save();
            }
        } catch (\Throwable $exception) {
            $printer->warning((string)__('路径重写写入失败（%{1}），/contact 与 /sitemap 可能被主题布局占用。', [$exception->getMessage()]));
        }
    }
}
