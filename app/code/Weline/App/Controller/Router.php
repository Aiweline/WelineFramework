<?php

declare(strict_types=1);

namespace Weline\App\Controller;

use Weline\Framework\Router\RouterInterface;

/**
 * Weline_App 公开短路由。
 *
 * 这里只做「公开路径 → 已编译控制器路由」的映射；由
 * App\Observer\ProcessAppUriBefore 在 process_uri_before 事件里调用。
 * 与 Theme\Controller\Router 的模块路由链保持同一扩展方式。
 */
final class Router implements RouterInterface
{
    /** @var array<string, string> */
    private const ROUTES = [
        'app/download' => 'weline_app/frontend/download',
        'manifest.webmanifest' => 'weline_app/frontend/pwa/manifest',
        'site.webmanifest' => 'weline_app/frontend/pwa/manifest',
        'sw.js' => 'weline_app/frontend/pwa/sw',
        'pwa-icon-192.png' => 'weline_app/frontend/pwa/icon192',
        'pwa-icon-512.png' => 'weline_app/frontend/pwa/icon512',
    ];

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalized = \trim(\strtolower($path), '/ ');
        $route = self::ROUTES[$normalized] ?? '';
        if ($route === '') {
            return;
        }

        $path = $route;
        $rule['module'] = 'Weline_App';
    }
}
