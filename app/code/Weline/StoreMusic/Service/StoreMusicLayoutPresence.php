<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemeRuntimeLayoutResolver;

/**
 * Detect whether the current request layout already places the store-music widget.
 * Used by body-end Hook so layout placement wins over Hook on every surface
 * (storefront and editor/preview) — not a preview-only skip.
 */
final class StoreMusicLayoutPresence
{
    public const MODULE = 'Weline_StoreMusic';
    public const CODE = 'store-music';

    public static function layoutPlacesWidget(?Request $request = null): bool
    {
        try {
            $request ??= ObjectManager::getInstance(Request::class);
            $themeId = self::resolveThemeId($request);
            if ($themeId <= 0) {
                return false;
            }

            $pageType = self::resolvePageType($request);
            $status = self::resolveStatus($request);

            /** @var ThemeRuntimeLayoutResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class);
            foreach (self::identityCandidates() as $identity) {
                $layout = $resolver->resolveLayout($themeId, $pageType, $status, 'frontend', $identity);
                if (self::layoutContainsStoreMusic($layout)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $layout
     */
    public static function layoutContainsStoreMusic(array $layout): bool
    {
        foreach ($layout as $areaData) {
            if (!\is_array($areaData)) {
                continue;
            }
            $widgets = $areaData['widgets'] ?? null;
            if (!\is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $module = (string)($widget['widget_module'] ?? '');
                $code = (string)($widget['widget_code'] ?? '');
                if ($module === self::MODULE && $code === self::CODE) {
                    return true;
                }
                if ($code === self::CODE && ($module === '' || str_contains($module, 'StoreMusic'))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function resolveThemeId(Request $request): int
    {
        foreach (['theme_id', 'frontend_theme_id', 'weline_theme_id'] as $key) {
            $raw = (int)$request->getParam($key, 0);
            if ($raw > 0) {
                return $raw;
            }
        }

        try {
            $fromContext = (int)RequestContext::get('theme_id', 0);
            if ($fromContext > 0) {
                return $fromContext;
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            /** @var ThemeContextService $themeContext */
            $themeContext = ObjectManager::getInstance(ThemeContextService::class);
            $theme = $themeContext->resolveTheme('frontend', null, true);
            $id = (int)($theme?->getId() ?? 0);
            if ($id > 0) {
                return $id;
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->clearData()->clearQuery()->getActiveTheme('frontend');
            $id = (int)$theme->getId();
            if ($id > 0) {
                return $id;
            }
        } catch (\Throwable) {
            // fall through
        }

        return 0;
    }

    private static function resolvePageType(Request $request): string
    {
        foreach (['layout_type', 'page_type'] as $key) {
            $raw = trim((string)$request->getParam($key, ''));
            if ($raw !== '') {
                try {
                    /** @var ThemePageTypeResolver $resolver */
                    $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);

                    return $resolver->mapLayoutTypeToPageType($raw);
                } catch (\Throwable) {
                    return $raw;
                }
            }
        }

        try {
            /** @var ThemePageTypeResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);
            $fromRequest = $resolver->resolveLayoutType(null, null, $request, '');
            if ($fromRequest !== '') {
                return $resolver->mapLayoutTypeToPageType($fromRequest);
            }
            $uri = (string)($request->getPathInfo() ?: \w_env_request_uri());

            return $resolver->resolvePageTypeFromUri($uri);
        } catch (\Throwable) {
            // fall through
        }

        return ThemeLayout::PAGE_TYPE_DEFAULT;
    }

    private static function resolveStatus(Request $request): string
    {
        $raw = strtolower(trim((string)$request->getParam('status', '')));
        if ($raw === ThemeLayout::STATUS_DRAFT || $raw === 'draft') {
            return ThemeLayout::STATUS_DRAFT;
        }

        return ThemeLayout::STATUS_PUBLISHED;
    }

    /**
     * @return list<array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string}>
     */
    private static function identityCandidates(): array
    {
        $list = [];
        $seen = [];
        $push = static function (array $candidate) use (&$list, &$seen): void {
            $scope = \trim((string)($candidate['scope'] ?? ''));
            if ($scope === '') {
                return;
            }
            if (isset($seen[$scope])) {
                return;
            }
            $seen[$scope] = true;
            $list[] = [
                'layout_option' => \trim((string)($candidate['layout_option'] ?? 'default')) ?: 'default',
                'scope' => $scope,
                'target_type' => \trim((string)($candidate['target_type'] ?? 'global')) ?: 'global',
                'target_id' => \max(0, (int)($candidate['target_id'] ?? 0)),
                'locale_code' => \trim((string)($candidate['locale_code'] ?? '')),
            ];
        };

        $primary = self::resolveIdentity();
        if ($primary !== []) {
            $push($primary);
        }

        try {
            $scopeIdentity = RequestContext::scopeIdentity();
            if ($scopeIdentity instanceof \Weline\Framework\Runtime\ScopeIdentity) {
                /** @var \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface $scopes */
                $scopes = ObjectManager::getInstance(\Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class);
                $context = $scopes->contextFromIdentity($scopeIdentity);
                foreach ($context->fallbackStorageScopes as $storageScope) {
                    $push(['scope' => (string)$storageScope]);
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        // Match ThemeRuntimeLayoutResolver: prefer published frontend website/store scopes
        // over literal "default" / default.default.default (which miss homepage widgets).
        foreach (['default.__store__.default', 'default.__website__.default', 'default.default.default'] as $scope) {
            $push(['scope' => $scope]);
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveIdentity(): array
    {
        try {
            $identity = RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY, null);
            if ($identity instanceof LayoutIdentity) {
                return [
                    'scope' => $identity->scope,
                    'locale_code' => $identity->localeCode,
                    'target_type' => $identity->targetType,
                    'target_id' => $identity->targetId,
                    'layout_option' => $identity->layoutOption,
                ];
            }
        } catch (\Throwable) {
            // fall through
        }

        return [];
    }
}
