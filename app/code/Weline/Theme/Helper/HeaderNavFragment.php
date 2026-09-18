<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
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
        $panelId = \trim((string)($params['panel_id'] ?? ''));
        $drawerFlyout = !empty($params['drawer_flyout']);
        if ($item === [] || self::shouldBypass($template)) {
            return (string)RequestLifecycleTrace::measurePhase(
                'theme.header.mega_panel.render',
                static fn(): string => (string)$template->fetch(self::MEGA_PANEL_TEMPLATE, $params),
                [
                    'panel_id' => $panelId,
                    'drawer' => $drawerFlyout,
                    'cache' => 'bypass',
                ],
            );
        }

        $showBannerWithChildren = self::coerceBool($params['show_banner_with_children'] ?? true, true);

        /** @var StorefrontHeaderNavFragmentCache $cache */
        $cache = ObjectManager::getInstance(StorefrontHeaderNavFragmentCache::class);

        return (string)RequestLifecycleTrace::measurePhase(
            'theme.header.mega_panel.cache',
            static fn(): string => $cache->rememberMegaMenuPanel(
                $panelId,
                $drawerFlyout,
                $item,
                static fn(): string => (string)RequestLifecycleTrace::measurePhase(
                    'theme.header.mega_panel.render',
                    static fn(): string => (string)$template->fetch(self::MEGA_PANEL_TEMPLATE, $params),
                    [
                        'panel_id' => $panelId,
                        'drawer' => $drawerFlyout,
                        'cache' => 'miss',
                    ],
                ),
                $showBannerWithChildren,
            ),
            [
                'panel_id' => $panelId,
                'drawer' => $drawerFlyout,
                'children' => \count(\is_array($item['children'] ?? null) ? $item['children'] : []),
            ],
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function fetchCategoriesSidebarNav(Template $template, array $params): string
    {
        $items = \is_array($params['items'] ?? null) ? $params['items'] : [];
        if ($items === [] || self::shouldBypass($template)) {
            return (string)RequestLifecycleTrace::measurePhase(
                'theme.header.sidebar.render',
                static fn(): string => (string)$template->fetch(self::SIDEBAR_NAV_TEMPLATE, $params),
                [
                    'items' => \count($items),
                    'cache' => 'bypass',
                ],
            );
        }

        /** @var StorefrontHeaderNavFragmentCache $cache */
        $cache = ObjectManager::getInstance(StorefrontHeaderNavFragmentCache::class);

        return (string)RequestLifecycleTrace::measurePhase(
            'theme.header.sidebar.cache',
            static fn(): string => $cache->rememberCategoriesSidebarNav(
                $items,
                static fn(): string => (string)RequestLifecycleTrace::measurePhase(
                    'theme.header.sidebar.render',
                    static fn(): string => (string)$template->fetch(self::SIDEBAR_NAV_TEMPLATE, $params),
                    [
                        'items' => \count($items),
                        'cache' => 'miss',
                    ],
                ),
            ),
            [
                'items' => \count($items),
            ],
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

    /**
     * Allocate a document-unique mega-menu panel id.
     * Same display slug (e.g. two "yun-dong-hu-wai" roots) must not collide.
     *
     * @param array<string, true> $usedIds
     */
    public static function allocateMegaPanelId(
        string $text,
        string $url,
        array &$usedIds,
        string $identity = '',
    ): string {
        $slug = \preg_replace('/[^a-z0-9_-]+/i', '-', \strtolower(\trim($text))) ?? '';
        $slug = \trim($slug, '-');
        $base = $slug !== '' ? ('mega-menu-' . $slug) : '';
        if ($base === '' || $base === 'mega-menu') {
            $base = 'mega-menu-' . \substr(\md5($text . '|' . $url), 0, 10);
        }

        $candidate = $base;
        if (isset($usedIds[$candidate])) {
            $suffix = \preg_replace('/[^a-z0-9_-]+/i', '-', \strtolower(\trim($identity))) ?? '';
            $suffix = \trim($suffix, '-');
            if ($suffix === '') {
                $suffix = \substr(\md5($url . '|' . $text), 0, 8);
            }
            $candidate = $base . '-' . $suffix;
        }

        $unique = $candidate;
        $n = 2;
        while (isset($usedIds[$unique])) {
            $unique = $candidate . '-' . $n;
            $n++;
        }
        $usedIds[$unique] = true;

        return $unique;
    }

    private static function shouldBypass(Template $template): bool
    {
        try {
            $requestPath = \strtolower((string)($template->request->getPathInfo() ?: \w_env_request_uri()));

            if ((string)$template->request->getGet('visual_editor', '') === '1'
                || (string)$template->request->getGet('preview', '') === '1'
                || \str_contains($requestPath, 'workspace-preview')
            ) {
                return true;
            }

            // Editor iframe uses editor_mode=1 on the real storefront path.
            // Catalog-backed chrome may still use fragment cache; bypass only when
            // the request is a generic preview/editor shell that must stay cold.
            $editorMode = \trim((string)($template->request->getParam('editor_mode', '') ?? ''));
            if ($editorMode === '1' || \strtolower($editorMode) === 'true') {
                return true;
            }
            if ((bool)$template->getData('editor_mode')) {
                return true;
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
