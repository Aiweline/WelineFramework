<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePublishedSnapshotReaderInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\WelineTheme;

/**
 * Resolve theme brand assets for the current Scope from appearance workspace.
 *
 * Brand lives under appearance payload key `brand` with paths:
 * /brand/favicon, /brand/apple_touch_icon, /brand/logo_light, /brand/logo_dark.
 *
 * Site name / description are Website/Store/Channel identity fields
 * (BrandBasicsIdentityProviderInterface), not appearance.brand text keys.
 */
final class ThemeBrandResolver
{
    public const KEYS = ['favicon', 'apple_touch_icon', 'logo_light', 'logo_dark'];

    public function __construct(
        private readonly ThemeScopedWorkspaceInterface $workspace,
        private readonly ScopeHierarchyInterface $scopes,
        private readonly ScopeIdentityCatalogInterface $catalog,
    ) {
    }

    /**
     * @return array{favicon:string,apple_touch_icon:string,logo_light:string,logo_dark:string,source_scope:?string}
     */
    public function resolvePublishedBrand(
        string $area = 'frontend',
        ?int $themeId = null,
        ?ScopeContext $scope = null,
        bool $includeDraft = false,
    ): array {
        $empty = [
            'favicon' => '',
            'apple_touch_icon' => '',
            'logo_light' => '',
            'logo_dark' => '',
            'source_scope' => null,
        ];
        try {
            $scope ??= $this->currentScopeContext();
            $themeId ??= $this->resolveActiveThemeId($area);
            if ($themeId <= 0 || $scope === null) {
                return $empty;
            }

            $merged = $empty;
            $seen = [];
            $cursor = $themeId;
            // 子主题 appearance.brand 为空时沿父主题链回落（品牌常发布在壳主题上）。
            while ($cursor > 0 && !isset($seen[$cursor])) {
                $seen[$cursor] = true;
                $layer = $this->readBrandLayer($area, $cursor, $scope, $includeDraft);
                $tookAssets = false;
                foreach (self::KEYS as $key) {
                    if ($merged[$key] === '' && $layer[$key] !== '') {
                        $merged[$key] = $layer[$key];
                        $tookAssets = true;
                    }
                }
                if ($tookAssets && $layer['source_scope'] !== null) {
                    $merged['source_scope'] = $layer['source_scope'];
                } elseif ($merged['source_scope'] === null && $layer['source_scope'] !== null) {
                    $merged['source_scope'] = $layer['source_scope'];
                }
                if ($this->brandLayerHasAssets($merged)) {
                    break;
                }
                $cursor = $this->resolveParentThemeId($cursor);
            }

            return $merged;
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * Read appearance.brand for one theme, filling missing keys from ancestor Scopes.
     *
     * A child Scope may publish appearance with empty brand{} (colors/tokens only).
     * publishedState stops at that Release and would otherwise hide parent-scope logos —
     * walk Scope parents until keys are filled (channel → store → website → global).
     *
     * @return array{favicon:string,apple_touch_icon:string,logo_light:string,logo_dark:string,source_scope:?string}
     */
    private function readBrandLayer(
        string $area,
        int $themeId,
        ScopeContext $scope,
        bool $includeDraft,
    ): array {
        $merged = [
            'favicon' => '',
            'apple_touch_icon' => '',
            'logo_light' => '',
            'logo_dark' => '',
            'source_scope' => null,
        ];
        $cursor = $scope;
        $seen = [];
        while ($cursor instanceof ScopeContext) {
            $storageKey = (string)$cursor->storageScope;
            if ($storageKey !== '' && isset($seen[$storageKey])) {
                break;
            }
            if ($storageKey !== '') {
                $seen[$storageKey] = true;
            }
            $layer = $this->readBrandAtScope($area, $themeId, $cursor, $includeDraft);
            $tookAssets = false;
            foreach (self::KEYS as $key) {
                if ($merged[$key] === '' && $layer[$key] !== '') {
                    $merged[$key] = $layer[$key];
                    $tookAssets = true;
                }
            }
            if ($tookAssets && $layer['source_scope'] !== null) {
                $merged['source_scope'] = $layer['source_scope'];
            } elseif ($merged['source_scope'] === null && $layer['source_scope'] !== null) {
                $merged['source_scope'] = $layer['source_scope'];
            }
            if ($this->brandLayerKeysComplete($merged)) {
                break;
            }
            $parentIdentity = $this->scopes->parentIdentity($cursor->identity);
            if (!$parentIdentity instanceof ScopeIdentity) {
                break;
            }
            try {
                $cursor = $this->scopes->contextFromIdentity($parentIdentity);
            } catch (\Throwable) {
                break;
            }
        }

        return $merged;
    }

    /**
     * @return array{favicon:string,apple_touch_icon:string,logo_light:string,logo_dark:string,source_scope:?string}
     */
    private function readBrandAtScope(
        string $area,
        int $themeId,
        ScopeContext $scope,
        bool $includeDraft,
    ): array {
        $empty = [
            'favicon' => '',
            'apple_touch_icon' => '',
            'logo_light' => '',
            'logo_dark' => '',
            'source_scope' => null,
        ];
        $context = new ThemeEditorContext(
            scope: $scope,
            area: $area === 'backend' ? 'backend' : 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_APPEARANCE,
            themeId: $themeId,
        );
        if (!$includeDraft && $this->workspace instanceof ThemePublishedSnapshotReaderInterface) {
            $snapshot = $this->workspace->readPublishedSnapshot($context);
            $payload = $snapshot['payload'];
            $sourceScope = $snapshot['source_scope'];
        } else {
            $state = $this->workspace->load($context, $includeDraft);
            $payloadKey = $includeDraft ? 'draft_payload' : 'published_payload';
            $payload = \is_array($state[$payloadKey] ?? null) ? $state[$payloadKey] : [];
            if ($payload === [] && $includeDraft && \is_array($state['published_payload'] ?? null)) {
                $payload = $state['published_payload'];
            }
            $sourceScope = isset($state['published_source_scope'])
                ? (string)$state['published_source_scope']
                : (isset($state['parent_source_scope']) ? (string)$state['parent_source_scope'] : null);
        }
        $brand = \is_array($payload['brand'] ?? null) ? $payload['brand'] : [];
        $result = $empty;
        foreach (self::KEYS as $key) {
            $value = \trim((string)($brand[$key] ?? ''));
            if ($value !== '') {
                $result[$key] = $value;
            }
        }
        $result['source_scope'] = $sourceScope;

        return $result;
    }

    /**
     * @param array{favicon:string,apple_touch_icon:string,logo_light:string,logo_dark:string,source_scope:?string} $brand
     */
    private function brandLayerKeysComplete(array $brand): bool
    {
        foreach (self::KEYS as $key) {
            if ($brand[$key] === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{favicon:string,apple_touch_icon:string,logo_light:string,logo_dark:string,source_scope:?string} $brand
     */
    private function brandLayerHasAssets(array $brand): bool
    {
        foreach (self::KEYS as $key) {
            if ($brand[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    protected function resolveParentThemeId(int $themeId): int
    {
        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->clearData()->clearQuery()->load($themeId);
            if (!(int)$theme->getId()) {
                return 0;
            }

            return max(0, (int)$theme->getParentId());
        } catch (\Throwable) {
            return 0;
        }
    }

    public function resolveBrandPath(string $key, string $area = 'frontend'): string
    {
        $key = \trim($key);
        if (!\in_array($key, self::KEYS, true)) {
            return '';
        }
        $brand = $this->resolvePublishedBrand($area);

        return (string)($brand[$key] ?? '');
    }

    private function currentScopeContext(): ?ScopeContext
    {
        try {
            $identity = RequestContext::scopeIdentity();
            if ($identity instanceof ScopeIdentity) {
                // 安装入口已验证并冻结当前请求身份；这里只计算同一身份的范围回退链。
                return $this->scopes->contextFromIdentity($identity);
            }
            $identity = ScopeIdentity::global();
            $authoritative = $this->catalog->authoritativeIdentity($identity);

            return $this->scopes->contextFromIdentity($authoritative);
        } catch (\Throwable) {
            try {
                return $this->scopes->contextFromIdentity(ScopeIdentity::global());
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function resolveActiveThemeId(string $area): int
    {
        $normalized = $area === 'backend' ? 'backend' : 'frontend';
        try {
            /** @var ThemeContextService $themeContext */
            $themeContext = ObjectManager::getInstance(ThemeContextService::class);
            $requested = $themeContext->resolveTheme($normalized, null, true);
            if ($requested && (int)$requested->getId() > 0) {
                return (int)$requested->getId();
            }
        } catch (\Throwable) {
        }
        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->clearData()->clearQuery()->getActiveTheme($normalized);

            return max(0, (int)$theme->getId());
        } catch (\Throwable) {
            return 0;
        }
    }
}
