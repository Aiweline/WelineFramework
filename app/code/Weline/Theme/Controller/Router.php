<?php

declare(strict_types=1);

namespace Weline\Theme\Controller;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemePageTypeResolver;

/**
 * Theme route preprocessor.
 *
 * Keep legacy preview entry `?preview_theme=...` compatible by rewriting
 * frontend preview entry requests to Theme preview gateway.
 */
class Router implements RouterInterface
{
    public static function rewritePreviewThemeQuery(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $request = null;
        try {
            /** @var Request $request */
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

        $normalizedPath = trim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($normalizedPath, 'theme/frontend/theme-preview')) {
            return;
        }

        if (self::shouldSkipPreviewRewrite($normalizedPath)) {
            return;
        }

        $layoutType = trim((string)($_GET['page_type'] ?? $_GET['layout_type'] ?? ''));
        if ($layoutType === '') {
            try {
                /** @var ThemePageTypeResolver $resolver */
                $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);
                $layoutType = $resolver->resolveLayoutTypeFromUri(
                    (string)($_SERVER['REQUEST_URI'] ?? '/'),
                    ''
                );
            } catch (\Throwable) {
                $layoutType = '';
            }
        }
        if ($layoutType === '') {
            if (self::isThemePreviewEntryPath($normalizedPath)) {
                $layoutType = 'homepage';
            } else {
                return;
            }
        }

        $editorArea = strtolower(trim((string)($_GET['editor_area'] ?? ($_GET['preview_area'] ?? 'frontend'))));
        if ($editorArea !== PreviewContextService::AREA_BACKEND) {
            $editorArea = PreviewContextService::AREA_FRONTEND;
        }

        $queryOverrides = [
            'editor_area' => $editorArea,
            'page_type' => $layoutType,
        ];

        if ($editorArea === PreviewContextService::AREA_BACKEND) {
            // Legacy preview_theme is an explicit theme choice and must win over
            // any stale preview context that may already exist in the worker.
            $queryOverrides['backend_theme_id'] = $themeId;
        } else {
            $queryOverrides['frontend_theme_id'] = $themeId;
        }

        if ((string)($_GET['layout_type'] ?? '') === '') {
            $queryOverrides['layout_type'] = $layoutType;
        }
        if ((string)($_GET['layout_option'] ?? '') === '') {
            $queryOverrides['layout_option'] = 'default';
        }
        if ((string)($_GET['preview_mode'] ?? '') === '') {
            $queryOverrides['preview_mode'] = PreviewContextService::DEFAULT_PREVIEW_MODE;
        }
        if ((string)($_GET['status'] ?? '') === '') {
            $queryOverrides['status'] = PreviewContextService::DEFAULT_STATUS;
        }
        if ((string)($_GET['shell'] ?? '') === '') {
            $queryOverrides['shell'] = PreviewContextService::SHELL_PREVIEW;
        }
        if ((string)($_GET['target_type'] ?? '') === '') {
            $queryOverrides['target_type'] = PreviewContextService::TARGET_TYPE_LAYOUT;
        }
        if ((string)($_GET['target_value'] ?? '') === '') {
            $queryOverrides['target_value'] = $layoutType;
        }

        self::applyQueryOverrides($request, $queryOverrides);

        $path = 'theme/frontend/theme-preview/gateway';
    }

    private static function applyQueryOverrides(?Request $request, array $queryOverrides): void
    {
        foreach ($queryOverrides as $key => $value) {
            $_GET[$key] = $value;

            if ($request) {
                $request->setGet($key, $value);
            }
        }

        if ($request) {
            $request->setData('params', $request->getParameterBag()->all());
        }
    }

    private static function shouldSkipPreviewRewrite(string $normalizedPath): bool
    {
        if ($normalizedPath === '') {
            return false;
        }

        if (str_contains($normalizedPath, '.')) {
            return true;
        }

        $staticOrApiPrefixes = [
            'static',
            'pub/static',
            'pub/media',
            'media',
            'uploads',
            'api',
            'rest',
            'graphql',
        ];

        foreach ($staticOrApiPrefixes as $prefix) {
            if ($normalizedPath === $prefix || str_starts_with($normalizedPath, $prefix . '/')) {
                return true;
            }
        }

        return false;
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
