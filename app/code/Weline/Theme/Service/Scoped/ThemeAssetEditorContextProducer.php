<?php
declare(strict_types=1);
namespace Weline\Theme\Service\Scoped;

use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePreviewPublishActorBinder;

/** 后台 ACL 成功后调用；范围来自消费方已验证的目录，不从原始 URL 或业务模型发现来源。 */
final class ThemeAssetEditorContextProducer
{
    public function __construct(
        private readonly BackendUserContextProviderInterface $actors,
        private readonly WelineTheme $themes,
        private readonly ThemeContextService $themeContext,
    ) {}

    public function build(int $themeId, string $area, ThemeContentScope $scope,
        ?ThemeVersionIdentity $version = null, array $resourceReferences = []): ThemeApplicationContext
    {
        $actor=$this->resolveActor();
        // 与既有工作区写边界相同的正式后台身份条件，不把客户端身份当作认证。
        if ($actor === null || $actor->getId()<1 || !$actor->getIsEnabled()) {
            throw new \RuntimeException('theme_asset_editor_actor_required');
        }
        $theme=(clone $this->themes)->clearData()->clearQuery()->load($themeId);
        if ((int)$theme->getId()!==$themeId || $themeId<1 || !$this->themeContext->themeSupportsArea($theme,$area)) {
            throw new \InvalidArgumentException('theme_asset_editor_theme_unavailable');
        }
        if ($version !== null && ($version->themeId!==$themeId || $version->area!==$area
            || $version->canonicalScope!==$scope->storageScope || $version->storeMode!==$scope->storeMode)) {
            throw new \InvalidArgumentException('theme_asset_editor_version_owner_mismatch');
        }
        $scopes=[];
        for ($cursor=$scope; $cursor!==null; $cursor=$cursor->parent) {
            $scopes[]=['provider'=>$cursor->provider,'scope_key'=>$cursor->scopeKey,'store_mode'=>$cursor->storeMode,
                'display_name'=>$cursor->displayName,'default_locale'=>$cursor->defaultLocale,
                'resource_references'=>$cursor===$scope ? $resourceReferences : []];
        }
        return new ThemeApplicationContext(
            provider:$scope->provider, scopeKey:$scope->scopeKey, storeMode:$scope->storeMode, area:$area,
            themeId:$themeId, versionOwnerScope:$version?->canonicalScope ?? $scope->storageScope,
            versionOwnerStoreMode:$version?->storeMode ?? $scope->storeMode,
            themeVersionId:$version?->themeVersionId ?? 0, contentRevision:$version?->contentRevision ?? 0,
            defaultLocale:$scope->defaultLocale, displayName:$scope->displayName, contentScopes:$scopes, purpose:'asset',
        );
    }

    /**
     * Prefer the authenticated backend session; fall back only to a request-scoped
     * actor installed after Frontend preview Token validation (sibling Host publish).
     */
    private function resolveActor(): ?BackendUserContext
    {
        $actor = $this->actors->current();
        if ($actor !== null && $actor->getId() > 0 && $actor->getIsEnabled()) {
            return $actor;
        }

        return ThemePreviewPublishActorBinder::current();
    }
}
