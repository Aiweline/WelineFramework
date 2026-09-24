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
    private const HORIZONTAL_NAV_TEMPLATE = 'Weline_Theme::theme/frontend/partials/header/categories-horizontal-nav.phtml';
    private const SEARCH_TYPE_DROPDOWN_TEMPLATE = 'Weline_Theme::theme/frontend/partials/search/type-dropdown.phtml';

    /**
     * Search type / category flyout with fragment gate (WS2):
     * shared HotCache holds the stable tree only; selected type / category / facade
     * are applied request-locally after HIT. Same-request build ≤1 via cache memo.
     *
     * @param array<string, mixed> $params
     */
    public static function fetchSearchTypeDropdown(Template $template, array $params): string
    {
        $types = \is_array($params['search_types'] ?? null) ? $params['search_types'] : [];
        $menuId = \trim((string)($params['menu_id'] ?? ''));
        if ($menuId === '') {
            $menuId = 'header-search-type-menu';
        }
        $selectedType = \trim((string)($params['selected_type'] ?? 'all'));
        if ($selectedType === '') {
            $selectedType = 'all';
        }
        $selectedCategoryId = (int)($params['selected_category_id'] ?? 0);

        $renderStable = static function () use ($template, $types, $menuId): string {
            return (string)RequestLifecycleTrace::measurePhase(
                'theme.header.search_types.render',
                static fn(): string => (string)$template->fetch(self::SEARCH_TYPE_DROPDOWN_TEMPLATE, [
                    'search_types' => $types,
                    'selected_type' => 'all',
                    'selected_category_id' => 0,
                    'menu_id' => $menuId,
                    'stable_fragment' => true,
                ]),
                [
                    'menu_id' => $menuId,
                    'types' => \count($types),
                    'cache' => 'miss',
                ],
            );
        };

        if ($types === [] || self::shouldBypass($template)) {
            $html = (string)RequestLifecycleTrace::measurePhase(
                'theme.header.search_types',
                static fn(): string => $renderStable(),
                ['cache' => 'bypass', 'menu_id' => $menuId],
            );

            return self::applySearchTypeDropdownSelection($html, $selectedType, $selectedCategoryId);
        }

        /** @var StorefrontHeaderNavFragmentCache $cache */
        $cache = ObjectManager::getInstance(StorefrontHeaderNavFragmentCache::class);

        $html = (string)RequestLifecycleTrace::measurePhase(
            'theme.header.search_types',
            static fn(): string => (string)RequestLifecycleTrace::measurePhase(
                'theme.header.search_types.cache',
                static fn(): string => $cache->rememberSearchTypeDropdown(
                    $menuId,
                    $types,
                    $renderStable,
                ),
                [
                    'menu_id' => $menuId,
                    'types' => \count($types),
                ],
            ),
            [
                'menu_id' => $menuId,
                'types' => \count($types),
            ],
        );

        return self::applySearchTypeDropdownSelection($html, $selectedType, $selectedCategoryId);
    }

    /**
     * Paint request-local selection onto a stable (selection-free) dropdown snapshot.
     */
    public static function applySearchTypeDropdownSelection(
        string $html,
        string $selectedType,
        int $selectedCategoryId,
    ): string {
        if ($html === '') {
            return $html;
        }
        $selectedType = \trim($selectedType);
        if ($selectedType === '') {
            $selectedType = 'all';
        }
        $selectedCategoryId = \max(0, $selectedCategoryId);

        $escType = \htmlspecialchars($selectedType, \ENT_QUOTES, 'UTF-8');
        $escCat = $selectedCategoryId > 0
            ? \htmlspecialchars((string)$selectedCategoryId, \ENT_QUOTES, 'UTF-8')
            : '';

        $selectedLabel = self::resolveSearchTypeFacadeLabel($html, $escType, $escCat);

        // Hidden inputs (request-local).
        $html = \preg_replace(
            '/(<input\b[^>]*\bname="type"[^>]*\bvalue=")[^"]*(")/i',
            '${1}' . $escType . '${2}',
            $html,
            1,
        ) ?? $html;
        $html = \preg_replace(
            '/(<input\b[^>]*\bname="category_id"[^>]*\bvalue=")[^"]*(")/i',
            '${1}' . $escCat . '${2}',
            $html,
            1,
        ) ?? $html;

        // Facade label.
        $html = \preg_replace(
            '/(<span\b[^>]*\bclass="[^"]*\bsearch-type-label\b[^"]*"[^>]*>)(.*?)(<\/span>)/is',
            '${1}' . \htmlspecialchars($selectedLabel, \ENT_QUOTES, 'UTF-8') . '${3}',
            $html,
            1,
        ) ?? $html;

        // Clear residual active / open markers, then paint request selection.
        $html = \str_replace(
            [' is-active', ' is-open', 'aria-selected="true"', 'aria-expanded="true"', 'data-state="open"'],
            ['', '', 'aria-selected="false"', 'aria-expanded="false"', 'data-state="closed"'],
            $html,
        );

        $html = self::activateMatchingSearchOption($html, $escType, $escCat);
        if ($selectedType !== 'all' || $selectedCategoryId > 0) {
            $html = self::openSearchTypeBranch($html, $selectedType);
        }

        return $html;
    }

    private static function extractOptionLabelAttr(string $openTag): ?string
    {
        if (\preg_match('/\bdata-display-label="([^"]*)"/i', $openTag, $m)) {
            return \html_entity_decode($m[1], \ENT_QUOTES, 'UTF-8');
        }
        if (\preg_match('/\bdata-label="([^"]*)"/i', $openTag, $m)) {
            return \html_entity_decode($m[1], \ENT_QUOTES, 'UTF-8');
        }

        return null;
    }

    private static function resolveSearchTypeFacadeLabel(
        string $html,
        string $escType,
        string $escCat,
    ): string {
        if ($escCat !== '') {
            $pattern = '/<button\b(?=[^>]*\bdata-search-type-option\b)(?=[^>]*\bdata-value="'
                . \preg_quote($escType, '/')
                . '")(?=[^>]*\bdata-category-id="'
                . \preg_quote($escCat, '/')
                . '")[^>]*>/i';
            if (\preg_match($pattern, $html, $m)) {
                $label = self::extractOptionLabelAttr($m[0]);
                if ($label !== null && $label !== '') {
                    return $label;
                }
            }
            $pattern = '/<button\b(?=[^>]*\bdata-search-type-option\b)(?=[^>]*\bdata-category-id="'
                . \preg_quote($escCat, '/')
                . '")[^>]*>/i';
            if (\preg_match($pattern, $html, $m)) {
                $label = self::extractOptionLabelAttr($m[0]);
                if ($label !== null && $label !== '') {
                    return $label;
                }
            }
        }

        $pattern = '/<button\b(?=[^>]*\bdata-search-type-option\b)(?=[^>]*\bdata-value="'
            . \preg_quote($escType, '/')
            . '")(?=[^>]*\bdata-category-id="")[^>]*>/i';
        if (\preg_match($pattern, $html, $m)) {
            $label = self::extractOptionLabelAttr($m[0]);
            if ($label !== null && $label !== '') {
                return $label;
            }
        }
        $pattern = '/<button\b(?=[^>]*\bdata-search-type-option\b)(?=[^>]*\bdata-value="'
            . \preg_quote($escType, '/')
            . '")[^>]*>/i';
        if (\preg_match($pattern, $html, $m)) {
            $label = self::extractOptionLabelAttr($m[0]);
            if ($label !== null && $label !== '') {
                return $label;
            }
        }

        return \html_entity_decode($escType, \ENT_QUOTES, 'UTF-8');
    }

    private static function activateMatchingSearchOption(
        string $html,
        string $escType,
        string $escCat,
    ): string {
        $pattern = '/<button\b[^>]*\bdata-search-type-option\b[^>]*\bdata-value="'
            . \preg_quote($escType, '/')
            . '"[^>]*\bdata-category-id="'
            . \preg_quote($escCat, '/')
            . '"[^>]*>/i';
        if (!\preg_match($pattern, $html, $m, \PREG_OFFSET_CAPTURE)) {
            // Reordered attributes: match by value+category anywhere in the tag.
            $pattern = '/<button\b(?=[^>]*\bdata-search-type-option\b)(?=[^>]*\bdata-value="'
                . \preg_quote($escType, '/')
                . '")(?=[^>]*\bdata-category-id="'
                . \preg_quote($escCat, '/')
                . '")[^>]*>/i';
            if (!\preg_match($pattern, $html, $m, \PREG_OFFSET_CAPTURE)) {
                return $html;
            }
        }
        $openTag = $m[0][0];
        $offset = (int)$m[0][1];
        $patched = $openTag;
        if (!\str_contains($patched, 'is-active')) {
            $patched = \preg_replace(
                '/\bclass="([^"]*)"/',
                'class="$1 is-active"',
                $patched,
                1,
            ) ?? $patched;
        }
        if (\str_contains($patched, 'aria-selected="false"')) {
            $patched = \str_replace('aria-selected="false"', 'aria-selected="true"', $patched);
        } elseif (!\str_contains($patched, 'aria-selected=')) {
            $patched = \rtrim($patched, '>') . ' aria-selected="true">';
        }

        return \substr($html, 0, $offset) . $patched . \substr($html, $offset + \strlen($openTag));
    }

    private static function openSearchTypeBranch(string $html, string $typeCode): string
    {
        $escType = \htmlspecialchars(\trim($typeCode), \ENT_QUOTES, 'UTF-8');
        if ($escType === '' || $escType === 'all') {
            return $html;
        }

        $pattern = '/<div\b(?=[^>]*\bsearch-type-node\b)(?=[^>]*\bhas-children\b)(?=[^>]*\bdata-value="'
            . \preg_quote($escType, '/')
            . '")[^>]*>/i';
        if (!\preg_match($pattern, $html, $m, \PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $openTag = $m[0][0];
        $offset = (int)$m[0][1];
        $patched = $openTag;
        if (!\str_contains($patched, 'is-open')) {
            $patched = \preg_replace(
                '/\bclass="([^"]*)"/',
                'class="$1 is-open"',
                $patched,
                1,
            ) ?? $patched;
        }
        $patched = \str_replace('data-state="closed"', 'data-state="open"', $patched);
        if (!\str_contains($patched, 'data-state=')) {
            $patched = \rtrim($patched, '>') . ' data-state="open">';
        }
        $html = \substr($html, 0, $offset) . $patched . \substr($html, $offset + \strlen($openTag));

        // Expand branch button + un-hide first submenu under this node.
        $nodeEnd = $offset + \strlen($patched);
        $branchPos = \stripos($html, 'data-search-type-branch', $nodeEnd);
        $submenuPos = \stripos($html, 'data-search-type-submenu', $nodeEnd);
        if ($branchPos !== false && ($submenuPos === false || $branchPos < $submenuPos)) {
            $btnStart = \strrpos(\substr($html, 0, $branchPos), '<button');
            if ($btnStart !== false) {
                $btnEnd = \strpos($html, '>', $btnStart);
                if ($btnEnd !== false) {
                    $btnTag = \substr($html, $btnStart, $btnEnd - $btnStart + 1);
                    $btnTag = \str_replace('aria-expanded="false"', 'aria-expanded="true"', $btnTag);
                    $html = \substr($html, 0, $btnStart) . $btnTag . \substr($html, $btnEnd + 1);
                }
            }
        }
        $submenuPos = \stripos($html, 'data-search-type-submenu', $nodeEnd);
        if ($submenuPos !== false) {
            $subStart = \strrpos(\substr($html, 0, $submenuPos), '<div');
            if ($subStart !== false) {
                $subTagEnd = \strpos($html, '>', $subStart);
                if ($subTagEnd !== false) {
                    $subTag = \substr($html, $subStart, $subTagEnd - $subStart + 1);
                    $subTag = \str_replace([' hidden="hidden"', ' hidden'], ['', ''], $subTag);
                    if (\str_ends_with(\rtrim($subTag, '>'), ' hidden')) {
                        $subTag = \preg_replace('/\s+hidden(?=\s|>)/', '', $subTag) ?? $subTag;
                    }
                    $subTag = \preg_replace('/\s*\bhidden\b/', '', $subTag) ?? $subTag;
                    $html = \substr($html, 0, $subStart) . $subTag . \substr($html, $subTagEnd + 1);
                }
            }
        }

        return $html;
    }

    /**
     * Cached full horizontal category strip (links + mega panels). Collapses the
     * serial mega-panel waterfall into one headerNavigationPolicy bag.
     *
     * @param array<string, mixed> $params
     */
    public static function fetchCategoriesHorizontalNav(Template $template, array $params): string
    {
        $items = \is_array($params['items'] ?? null) ? $params['items'] : [];
        $showBannerWithChildren = self::coerceBool($params['show_banner_with_children'] ?? true, true);
        if ($items === [] || self::shouldBypass($template)) {
            return (string)RequestLifecycleTrace::measurePhase(
                'theme.header.horizontal.render',
                static fn(): string => (string)$template->fetch(self::HORIZONTAL_NAV_TEMPLATE, $params),
                [
                    'items' => \count($items),
                    'cache' => 'bypass',
                ],
            );
        }

        /** @var StorefrontHeaderNavFragmentCache $cache */
        $cache = ObjectManager::getInstance(StorefrontHeaderNavFragmentCache::class);

        return (string)RequestLifecycleTrace::measurePhase(
            'theme.header.horizontal.cache',
            static fn(): string => $cache->rememberCategoriesHorizontalNav(
                $items,
                $showBannerWithChildren,
                static fn(): string => (string)RequestLifecycleTrace::measurePhase(
                    'theme.header.horizontal.render',
                    static fn(): string => (string)$template->fetch(self::HORIZONTAL_NAV_TEMPLATE, $params),
                    [
                        'items' => \count($items),
                        'cache' => 'miss',
                    ],
                ),
            ),
            [
                'items' => \count($items),
                'banner' => $showBannerWithChildren,
            ],
        );
    }

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
