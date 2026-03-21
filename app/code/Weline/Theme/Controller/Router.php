<?php

declare(strict_types=1);

namespace Weline\Theme\Controller;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;

/**
 * Theme 路由重写器
 *
 * 带 preview_theme 的前台请求在路由前置阶段建立预览上下文。
 * 保持原 URL，不做跳转式路径改写。
 */
class Router implements RouterInterface
{
    /**
     * 识别首页 preview 请求；当前仅记录并保持原路径（直接预览，不跳转）。
     */
    public static function rewritePreviewThemeQuery(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        try {
            $request = ObjectManager::getInstance(Request::class);
            if ($request->isBackend()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $themeId = (int)($_GET['preview_theme'] ?? 0);
        if ($themeId <= 0) {
            return;
        }

        $normalized = trim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($normalized, 'theme/frontend/theme-preview')) {
            return;
        }

        if (!self::isThemePreviewEntryPath($normalized)) {
            return;
        }
    }

    private static function isThemePreviewEntryPath(string $normalizedPath): bool
    {
        return $normalizedPath === '' || $normalizedPath === 'index' || $normalizedPath === 'index/index';
    }

    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        self::rewritePreviewThemeQuery($path, $rule);
    }
}
