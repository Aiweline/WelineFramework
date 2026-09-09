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
            $context = new ThemeEditorContext(
                scope: $scope,
                area: $area === 'backend' ? 'backend' : 'frontend',
                resourceType: ThemeEditorContext::RESOURCE_APPEARANCE,
                themeId: $themeId,
            );
            if (!$includeDraft && $this->workspace instanceof ThemePublishedSnapshotReaderInterface) {
                // 整份品牌随公开快照在工作区 Context 复用，避免每个品牌键加载编辑器溯源信息。
                $snapshot = $this->workspace->readPublishedSnapshot($context);
                $payload = $snapshot['payload'];
                $sourceScope = $snapshot['source_scope'];
            } else {
                // 旧 Provider 和预览保留原接口及草稿回退语义。
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
        } catch (\Throwable) {
            return $empty;
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
        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->clearData()->clearQuery()->getActiveTheme($area === 'backend' ? 'backend' : 'frontend');

            return max(0, (int)$theme->getId());
        } catch (\Throwable) {
            return 0;
        }
    }
}
