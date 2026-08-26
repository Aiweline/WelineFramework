<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
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

        /** @var StorefrontHeaderNavFragmentCache $cache */
        $cache = ObjectManager::getInstance(StorefrontHeaderNavFragmentCache::class);

        return $cache->rememberMegaMenuPanel(
            $panelId,
            $drawerFlyout,
            $item,
            static fn(): string => (string)$template->fetch(self::MEGA_PANEL_TEMPLATE, $params),
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

    private static function shouldBypass(Template $template): bool
    {
        try {
            $editorMode = \trim((string)($template->request->getParam('editor_mode', '') ?? ''));
            if ($editorMode === '1' || \strtolower($editorMode) === 'true') {
                return true;
            }
        } catch (\Throwable) {
        }

        if ((bool)$template->getData('editor_mode')) {
            return true;
        }

        try {
            $requestPath = \strtolower((string)($template->request->getPathInfo() ?: \w_env_request_uri()));
            if ((string)$template->request->getGet('visual_editor', '') === '1'
                || (string)$template->request->getGet('preview', '') === '1'
                || \str_contains($requestPath, 'workspace-preview')
            ) {
                return true;
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }
}
