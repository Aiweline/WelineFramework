<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Service\AllMenu\MenuTreeNormalizer;
use Weline\Theme\Service\StorefrontHeaderNavFragmentCache;

/**
 * Cached fetch helpers for header navigation partials.
 */
final class HeaderNavFragment
{
    private const MEGA_PANEL_TEMPLATE = 'Weline_Theme::theme/frontend/partials/header/mega-menu-panel.phtml';
    private const SIDEBAR_NAV_TEMPLATE = 'Weline_Theme::theme/frontend/partials/header/categories-sidebar-nav.phtml';

    /**
     * @param array<string, mixed> $params
     */
    public static function fetchMegaMenuPanel(Template $template, array $params): string
    {
        $item = \is_array($params['item'] ?? null) ? $params['item'] : [];
        if ($item === [] || self::shouldBypass($template)) {
            return (string)$template->fetch(self::MEGA_PANEL_TEMPLATE, $params);
        }

        $panelId = \trim((string)($params['panel_id'] ?? ''));
        $drawerFlyout = !empty($params['drawer_flyout']);
        $showBannerWithChildren = self::coerceBool($params['show_banner_with_children'] ?? true, true);

        /** @var StorefrontHeaderNavFragmentCache $cache */
        $cache = ObjectManager::getInstance(StorefrontHeaderNavFragmentCache::class);

        return $cache->rememberMegaMenuPanel(
            $panelId,
            $drawerFlyout,
            $item,
            static fn(): string => (string)$template->fetch(self::MEGA_PANEL_TEMPLATE, $params),
            $showBannerWithChildren,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function fetchCategoriesSidebarNav(Template $template, array $params): string
    {
        $items = \is_array($params['items'] ?? null) ? $params['items'] : [];
        if ($items === [] || self::shouldBypass($template)) {
            return (string)$template->fetch(self::SIDEBAR_NAV_TEMPLATE, $params);
        }

        /** @var StorefrontHeaderNavFragmentCache $cache */
        $cache = ObjectManager::getInstance(StorefrontHeaderNavFragmentCache::class);

        return $cache->rememberCategoriesSidebarNav(
            $items,
            static fn(): string => (string)$template->fetch(self::SIDEBAR_NAV_TEMPLATE, $params),
        );
    }

    /**
     * Ensure header nav hrefs keep the active storefront currency/lang path prefix.
     */
    public static function localizeHref(string $url): string
    {
        try {
            /** @var MenuTreeNormalizer $normalizer */
            $normalizer = ObjectManager::getInstance(MenuTreeNormalizer::class);

            return $normalizer->localizeUrl($url);
        } catch (\Throwable) {
            $url = trim($url);

            return $url !== '' ? $url : '#';
        }
    }

    private static function shouldBypass(Template $template): bool
    {
        try {
            $requestPath = \strtolower((string)($template->request->getPathInfo() ?: \w_env_request_uri()));
            $isThemePreviewContent = \str_contains($requestPath, 'theme/frontend/theme-preview/content')
                || \str_contains($requestPath, 'theme/backend/theme-preview/content');

            if ((string)$template->request->getGet('visual_editor', '') === '1'
                || (string)$template->request->getGet('preview', '') === '1'
                || \str_contains($requestPath, 'workspace-preview')
            ) {
                return true;
            }

            // Editor iframe uses editor_mode=1 but nav HTML is catalog-backed chrome.
            // Allow scope-hot fragment cache on theme-preview/content to avoid cold
            // mega-menu/sidebar SSR on every iframe reload.
            if (!$isThemePreviewContent) {
                $editorMode = \trim((string)($template->request->getParam('editor_mode', '') ?? ''));
                if ($editorMode === '1' || \strtolower($editorMode) === 'true') {
                    return true;
                }
                if ((bool)$template->getData('editor_mode')) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    private static function coerceBool(mixed $value, bool $default = true): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return ((int)$value) !== 0;
        }
        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));
            if (\in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
            if (\in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }
        if ($value === null) {
            return $default;
        }

        return (bool)$value;
    }
}
