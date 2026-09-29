<?php

declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\View\Template;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Theme\Service\ThemeVersionPreviewResolver;
use Weline\Theme\Service\Scoped\ThemeEditorContextFactory;

/** Select ordinary template sources; execution always belongs to Template. */
final class SolidifiedControllerTemplateResolver
{
    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeScopeVersionService $scopeVersions,
        private readonly ScopeHierarchyInterface $scopes,
    ) {}

    public function resolveExecutableLayoutPath(int $themeId, string $layoutType, string $layoutOption = 'default', string $area = 'frontend', string $targetType = 'global', ?int $targetId = null): ?string
    {
        if ($themeId < 1 || trim($layoutType) === '') { return null; }
        $selection = $this->resolveRequestIdentity($themeId, $layoutType, $layoutOption, $area, $targetType, $targetId);
        if ($selection === null) { return null; }
        [$identity, $layoutIdentity, $authorizedPreview] = $selection;
        RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, $layoutIdentity);
        $path = $this->resolveForIdentity($identity, $layoutType, $layoutIdentity->layoutOption, $layoutIdentity->targetType, $layoutIdentity->targetId);
        if ($path !== null) { return $path; }
        // Ordinary storefront requests select existing sources only. Keep the
        // captured public partials while the controller falls back to its source.
        if (!$authorizedPreview) { return null; }

        // Authorized editor/Token previews may rebuild their exact V/R (including
        // formal Tokens). Candidates stay in memory and never replace the draft.
        $candidates = ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class)->candidateForIdentity(
            $identity, $layoutType, $layoutIdentity->layoutOption, $layoutIdentity->targetType, $layoutIdentity->targetId,
        );
        $page = $this->paths->pageLayoutPhtml($identity, $layoutType, $layoutIdentity->layoutOption, $layoutIdentity->targetType, $layoutIdentity->targetId);
        $snapshot = ThemeLayoutSourceSnapshot::fromCandidates($identity, $page, $candidates);
        $snapshot->install(Template::getInstance());
        return $snapshot->pagePath();
    }

    public function resolveForIdentity(ThemeVersionIdentity $identity, string $layoutType, string $layoutOption = 'default', string $targetType = 'global', ?int $targetId = null): ?string
    {
        $snapshot = ThemeLayoutSourceSnapshot::capture($this->paths, $identity, $layoutType, $layoutOption, $targetType, $targetId);
        $snapshot->install(Template::getInstance());
        return $snapshot->pagePath();
    }

    /** @return array{ThemeVersionIdentity,LayoutIdentity,bool}|null */
    private function resolveRequestIdentity(int $themeId, string $layoutType, string $layoutOption, string $area, string $targetType, ?int $targetId): ?array
    {
        $preview = ObjectManager::getInstance(PreviewContextService::class);
        $installed = RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY);
        if ($installed instanceof LayoutIdentity) {
            $layoutOption = $installed->layoutOption;
            $targetType = $installed->targetType;
            $targetId = $installed->targetId;
        }
        if ($preview->hasAuthoritativePreviewContext()) {
            $context = $preview->getCurrentContext();
            $token = ObjectManager::getInstance(PreviewTokenService::class)->getCurrentPreviewData();
            $request = ObjectManager::getInstance(Request::class);
            if (is_array($token)) {
                // Token data wins over every URL identity field, including V/mode/R.
                $locale = (string)($context['locale'] ?? '');
                $context = array_replace(is_array($token['context'] ?? null) ? $token['context'] : [], array_filter($token, static fn($v): bool => $v !== null));
                $context['locale'] = $locale;
                $layoutOption = 'default';
                $targetType = 'global';
                $targetId = 0;
                $area = (string)($context['area'] ?? $context['editor_area'] ?? $area);
            } else {
                foreach (['theme_version_id','version_id','mode','content_revision','editor_context'] as $key) {
                    $value = $request->getParam($key, null);
                    if ($value !== null && $value !== '') { $context[$key] = $value; }
                }
            }
            $typed = $context['editor_context'] ?? null;
            if ($typed !== null && $typed !== '') {
                $editor = ObjectManager::getInstance(ThemeEditorContextFactory::class)->fromInput(['editor_context' => $typed], 'layout');
                $themeId = $editor->themeId;
                $area = $editor->area;
                $scope = $editor->scope->storageScope;
                $storeMode = $editor->scope->storeMode;
                $layoutOption = $editor->layoutOption;
                $targetType = $editor->targetType;
                $targetId = $editor->targetId;
            } else {
                $themeId = (int)($context['theme_id'] ?? $context[$area . '_theme_id'] ?? $themeId);
                $scope = (string)($context['canonical_scope'] ?? $context['scope'] ?? 'default.default.default');
                $storeMode = (string)($context['store_mode'] ?? 'normal');
                $layoutOption = (string)($context['layout_option'] ?? $layoutOption);
                $targetType = (string)($context['theme_layout_target_type'] ?? $context['theme_layout_source_target_type'] ?? $targetType);
                $targetId = (int)($context['theme_layout_target_id'] ?? $context['theme_layout_source_target_id'] ?? $targetId ?? 0);
            }
            $cursor = array_replace($context, ['theme_id'=>$themeId, 'canonical_scope'=>$scope, 'scope'=>$scope, 'store_mode'=>$storeMode, 'area'=>$area]);
            $versionId = (int)($cursor['theme_version_id'] ?? $cursor['version_id'] ?? 0);
            if ($versionId < 1) {
                $version = ($cursor['status'] ?? 'draft') === 'published'
                    ? $this->scopeVersions->getPublished($themeId, $scope, $storeMode, $area)
                    : $this->scopeVersions->getCurrent($themeId, $scope, $storeMode, $area);
                if ($version === null) { return null; }
                $versionId = $version->getVersionId();
            }
            $cursor['mode'] = (string)($cursor['mode'] ?? (($cursor['status'] ?? 'draft') === 'published' ? 'formal' : 'draft'));
            $layoutIdentity = new LayoutIdentity($layoutOption, $scope, $targetType, max(0, (int)$targetId), (string)($context['locale'] ?? ''));
            $resolved = ObjectManager::getInstance(ThemeVersionPreviewResolver::class)->resolve($themeId, $layoutType, $area, $layoutIdentity->toArray() + ['store_mode'=>$storeMode], $versionId, $cursor);
            RequestContext::set(ThemeVersionPreviewResolver::REQUEST_KEY, $resolved);
            if (!($resolved['resolved'] ?? false) || !($resolved['version_identity'] ?? null) instanceof ThemeVersionIdentity) {
                throw new \RuntimeException((string)($resolved['reason'] ?? 'theme_layout_preview_source_unresolved'));
            }
            return [$resolved['version_identity'], $layoutIdentity, true];
        }

        $scope = 'default.default.default';
        $storeMode = 'normal';
        if (RequestContext::isInitialized()) {
            $runtimeIdentity = RequestContext::scopeIdentity();
            if ($runtimeIdentity instanceof ScopeIdentity) {
                $scope = $this->scopes->contextFromIdentity($runtimeIdentity)->storageScope;
                $storeMode = $runtimeIdentity->storeMode ?? 'normal';
            }
        }
        $version = $this->scopeVersions->getPublished($themeId, $scope, $storeMode, $area);
        if ($version === null || $version->getVersionId() < 1) { return null; }
        $identity = $version->toVersionIdentity()->withVersion($version->getVersionId(), ThemeVersionIdentity::MODE_FORMAL, max(1, $version->getContentRevision()));
        if ($identity->area !== $area || $identity->storeMode !== $storeMode) {
            throw new \RuntimeException('theme_layout_published_owner_mismatch');
        }
        return [$identity, new LayoutIdentity($layoutOption, $identity->canonicalScope, $targetType, max(0, (int)$targetId)), false];
    }
}
