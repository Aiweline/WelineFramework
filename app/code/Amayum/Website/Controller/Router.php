<?php

declare(strict_types=1);

namespace Amayum\Website\Controller;

use Amayum\Website\Service\SiteProfileService;
use Weline\Framework\Context;
use Weline\Framework\Router\RouterInterface;

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
        // 站点门禁（唯一判据在 SiteProfileService）：不按 website code 放行会把
        // Amayum 固定页路由认领进所有站点——实测 changanhanfu.com /contact 被改写到
        // amayum/frontend/contact 后 500。/~site/amayum 预览挂载下 DetectWebsite 已
        // 解析为 amayum，同样放行。
        if (!empty($rule['module']) || !SiteProfileService::isAmayumSite()) {
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
}
