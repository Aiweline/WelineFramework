<?php

declare(strict_types=1);

namespace Amayum\Website\Controller;

use Weline\Framework\Context;
use Weline\Framework\Router\RouterInterface;
use Weline\Websites\Data\WebsiteData;

/**
 * 认领 Amayum 固定页面路径。
 *
 * 不认领 `/`（由 Website frontend_start_page_path 决定）、`/sitemap.xml` 与
 * `/robots.txt`（由 Weline_Seo 协议端点输出）。
 */
final class Router implements RouterInterface
{
    private const ROUTES = [
        'commerce' => 'amayum/frontend/commerce',
        'case-studies' => 'amayum/frontend/case-studies',
        'journal' => 'amayum/frontend/journal',
        'diagnosis' => 'amayum/frontend/contact',
        'contact' => 'amayum/frontend/contact',
        'legal' => 'amayum/frontend/legal',
        'sitemap' => 'amayum/frontend/sitemap-page',
    ];

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module']) || !self::isAmayumWebsite()) {
            return;
        }

        $normalized = \strtolower(\trim(\str_replace('\\', '/', (string)$path), '/'));
        if ($normalized === '') {
            return;
        }

        if (isset(self::ROUTES[$normalized])) {
            $path = self::ROUTES[$normalized];
            $rule['module'] = 'Amayum_Website';

            return;
        }

        if (\preg_match('#^journal/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$#D', $normalized, $matches) !== 1) {
            return;
        }

        $path = 'amayum/frontend/journal-view';
        $rule['module'] = 'Amayum_Website';
        Context::current()->set('input.query.slug', (string)$matches[1]);
    }

    /**
     * 站点门禁：本模块只服务 Amayum 站。ModuleRouter 在每个店面请求上回调本
     * Router；不按 website code 放行会把 Amayum 固定页路由认领进所有站点
     * （实测 changanhanfu.com /contact 被改写为 amayum/frontend/contact，模板
     * require 未部署的 Amayum 主题文件直接 500）。
     *
     * 直接问 WebsiteData，不能用 SiteProfileService::currentWebsiteCode()：
     * 它在取不到 code 时回退 'amayum'，门禁会形同虚设（与
     * AmayumSeoProfileProvider::isAmayumSite() 同一判据）。/~site/amayum
     * 预览挂载下 DetectWebsite 已把当前站解析为 amayum，这里同样放行。
     */
    private static function isAmayumWebsite(): bool
    {
        try {
            $code = \strtolower(\trim((string)(WebsiteData::getCode() ?? '')));
        } catch (\Throwable) {
            return false;
        }

        return $code === 'amayum';
    }
}
