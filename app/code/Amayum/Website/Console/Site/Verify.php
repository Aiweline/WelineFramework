<?php

declare(strict_types=1);

namespace Amayum\Website\Console\Site;

use Amayum\Website\Service\SiteProfileService;
use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Seo\Model\SitemapUrl;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\WebsiteThemeBindingService;

/**
 * php bin/w amayum:site:verify --website=amayum --theme=amayum-official
 */
final class Verify extends CommandAbstract
{
    public const ALIASES = ['amayum:site:verify'];

    public function execute(array $args = [], array $data = []): mixed
    {
        $printer = ObjectManager::getInstance(Printing::class);
        $websiteCode = \strtolower(\trim((string)($args['website'] ?? 'amayum')));
        $themeName = \trim((string)($args['theme'] ?? 'amayum-official'));
        $failures = 0;

        $website = w_query('websites', 'getWebsiteByCode', ['code' => $websiteCode, 'force_reload' => true]);
        $websiteId = \is_array($website) ? (int)($website['website_id'] ?? 0) : 0;
        $this->report($printer, $websiteId > 0, 'website ' . $websiteCode . ' (id=' . $websiteId . ')', $failures);
        if ($websiteId <= 0) {
            return 1;
        }

        $theme = ObjectManager::getInstance(WelineTheme::class)
            ->clear()
            ->where(WelineTheme::schema_fields_NAME, $themeName)
            ->find()
            ->fetchArray();
        $themeId = \is_array($theme) ? (int)($theme[WelineTheme::schema_fields_ID] ?? 0) : 0;
        $this->report($printer, $themeId > 0, 'theme ' . $themeName . ' (id=' . $themeId . ')', $failures);

        $summary = [];
        try {
            $summary = ObjectManager::getInstance(WebsiteThemeBindingService::class)->summarize((array)$website);
        } catch (\Throwable) {
            $summary = [];
        }
        $boundThemeId = (int)($summary['theme_id'] ?? 0);
        $this->report(
            $printer,
            $themeId > 0 && $boundThemeId === $themeId,
            'theme binding frontend (bound theme_id=' . $boundThemeId . ')',
            $failures,
        );

        /** @var ConfigReader $reader */
        $reader = ObjectManager::getInstance(ConfigReader::class);
        $scope = (new SiteProfileService())->scope($websiteCode);
        foreach ([
            SiteProfileService::KEY_PROFILE,
            SiteProfileService::KEY_NAV,
            SiteProfileService::KEY_FOOTER,
            SiteProfileService::KEY_PROOF_FACTS,
            SiteProfileService::KEY_FLAGS,
        ] as $key) {
            $value = $reader->get($key, SiteProfileService::MODULE, SiteProfileService::AREA, null, $scope);
            $this->report($printer, \is_array($value) && $value !== [], 'config ' . $key, $failures);
        }

        $startPage = (string)$reader->get('frontend_start_page_path', 'Weline_Websites', 'frontend', '', $scope);
        $this->report(
            $printer,
            $startPage === 'amayum/frontend/homepage',
            'frontend_start_page_path = ' . ($startPage !== '' ? $startPage : '(empty)'),
            $failures,
        );

        /** @var SitemapUrl $sitemap */
        $sitemap = ObjectManager::getInstance(SitemapUrl::class);
        $rows = $sitemap->clear()
            ->where(SitemapUrl::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SitemapUrl::schema_fields_MODULE, SiteProfileService::MODULE)
            ->where(SitemapUrl::schema_fields_STATUS, 1)
            ->select()
            ->fetchArray();
        $sitemapCount = \is_array($rows) ? \count($rows) : 0;
        $this->report($printer, $sitemapCount === 10, 'sitemap active URLs = ' . $sitemapCount . ' (expected 10)', $failures);

        $routeFile = Env::path_FRONTEND_PC_ROUTER_FILE;
        $routes = \is_file($routeFile) ? require $routeFile : [];
        $routes = \is_array($routes) ? $routes : [];
        foreach ([
            'amayum/frontend/homepage',
            'amayum/frontend/commerce',
            'amayum/frontend/case-studies',
            'amayum/frontend/journal',
            'amayum/frontend/journal-view',
            'amayum/frontend/contact',
            'amayum/frontend/legal',
            'amayum/frontend/sitemap-page',
        ] as $route) {
            $this->report($printer, isset($routes[$route]), 'route ' . $route, $failures);
        }

        if ($failures > 0) {
            $printer->error((string)__('Amayum 站点校验失败：%{1} 项。', [(string)$failures]));

            return 1;
        }

        $printer->success((string)__('Amayum 站点校验通过。'));

        return 0;
    }

    public function tip(): string
    {
        return (string)__('校验 Amayum 站点资料、主题绑定、首页入口、sitemap 与路由');
    }

    public function help(): array|string
    {
        return [
            'php bin/w amayum:site:verify --website=amayum --theme=amayum-official' => (string)__('输出每项 OK/FAIL；任一失败返回 exit code 1'),
        ];
    }

    private function report(Printing $printer, bool $ok, string $label, int &$failures): void
    {
        if ($ok) {
            $printer->note('[OK] ' . $label);

            return;
        }
        $failures++;
        $printer->error('[FAIL] ' . $label);
    }
}
