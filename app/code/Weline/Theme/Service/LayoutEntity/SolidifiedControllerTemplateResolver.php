<?php

declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Framework\View\Template;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Theme\Service\ThemeVersionPreviewResolver;
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
        $layoutType = \trim($layoutType);
        $layoutOption = \trim((string)$layoutIdentity->layoutOption);
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }
        if ($layoutType !== '') {
            RequestContext::set(
                \Weline\Widget\Cache\WidgetOutputCache::REQUEST_LAYOUT_NAME_KEY,
                $layoutType . '.' . $layoutOption,
            );
        }
        $path = $this->resolveForIdentity($identity, $layoutType, $layoutIdentity->layoutOption, $layoutIdentity->targetType, $layoutIdentity->targetId);
        if ($path !== null) { return $path; }
        // Authorized editor/Token previews may rebuild their exact V/R (including
        // formal Tokens). Candidates stay in memory and never replace the draft.
        $rebuildInMemoryOnly = $authorizedPreview;
        if (!$authorizedPreview) {
            // Storefront request hit a missing solidified artifact: solidify it through
            // the same unified entry the editor pipeline uses, then read.
            //
            // Why: the runtime must only ever "read if present, build if absent".
            // Refusing to build here meant the page fell back to source slots that are
            // deliberately empty shells, and RequiredDefaultInjectionRuntimeSafetyNet
            // then re-rendered all 11 chrome widgets on EVERY request (measured
            // +74,084 bytes / ~150 ms per request on /best-sellers). The artifacts were
            // never written back, so the cost never amortised.
            //
            // Fail-open: a bake problem must not 500 the storefront — fall through to
            // the source-slot path exactly as before.
            if (!$this->solidifyMissingArtifact($layoutType, $layoutIdentity)) {
                return null;
            }
            $path = $this->resolveForIdentity(
                $identity,
                $layoutType,
                $layoutIdentity->layoutOption,
                $layoutIdentity->targetType,
                $layoutIdentity->targetId,
            );
            if ($path !== null) { return $path; }
            // The bake ran but this identity still has no candidate: keep the previous
            // behaviour instead of inventing a page from partial output.
            $rebuildInMemoryOnly = false;
        }
        if (!$rebuildInMemoryOnly) {
            return null;
        }

        $candidates = ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class)->candidateForIdentity(
            $identity, $layoutType, $layoutIdentity->layoutOption, $layoutIdentity->targetType, $layoutIdentity->targetId,
        );
        $page = $this->paths->pageLayoutPhtml($identity, $layoutType, $layoutIdentity->layoutOption, $layoutIdentity->targetType, $layoutIdentity->targetId);
        $snapshot = ThemeLayoutSourceSnapshot::fromCandidates($identity, $page, $candidates);
        $snapshot->install(Template::getInstance());
        return $snapshot->pagePath();
    }

    /**
     * Build the missing solidified artifact through the unified bake entry.
     *
     * Returns true when the artifacts were published. A `false` return means the
     * caller must keep the legacy source-slot path (fail-open).
     */
    private function solidifyMissingArtifact(string $layoutType, LayoutIdentity $layoutIdentity): bool
    {
        try {
            $coordinator = ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class);
            // Same entry the editor/publish pipeline uses: it merges required
            // default_injections into the node map (RequiredDefaultInjectionBakeMerger)
            // and publishes page+chrome artifacts, so a later request only reads.
            //
            // Target the PUBLISHED version, not the current one: the storefront read
            // path resolves `getPublished()`, so baking `getCurrent()` can write a row
            // this request will never read (theme 3: current=v1901, published=v2867).
            $report = $coordinator->solidifyPublishedScopeVersion($this->bakeContext($layoutType, $layoutIdentity));
            $ok = (bool)($report['ok'] ?? false);
            if (!$ok) {
                $this->logBakeFailure('solidify_reported_not_ok: ' . (string)($report['reason'] ?? ''));

                return false;
            }

            return true;
        } catch (\Throwable $throwable) {
            $this->logBakeFailure($throwable::class . ': ' . $throwable->getMessage());

            return false;
        }
    }

    /**
     * Editor context for an on-demand storefront bake.
     *
     * The bake identity is (theme, scope, store mode, area); those come from the
     * request's resolved Website scope, never from a hardcoded default — a bake for
     * the wrong scope would publish into another site's artifacts.
     */
    private function bakeContext(string $layoutType, LayoutIdentity $layoutIdentity): ThemeEditorContext
    {
        /** @var ThemeContextService $themeContext */
        $themeContext = ObjectManager::getInstance(ThemeContextService::class);
        $theme = $themeContext->resolveTheme('frontend', null, false);
        $themeId = \is_object($theme) && \method_exists($theme, 'getId') ? (int)$theme->getId() : 0;

        /** @var ScopeHierarchyInterface $scopes */
        $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
        $identity = RequestContext::scopeIdentity();
        if (!$identity instanceof ScopeIdentity) {
            $identity = ScopeIdentity::global();
        }
        $scope = $scopes->contextFromIdentity($identity);

        return new ThemeEditorContext(
            scope: ThemeContentScope::fromStoredOwner($scope->storageScope, $scope->storeMode, 'default'),
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: $themeId,
            layoutType: \trim($layoutType) !== '' ? \trim($layoutType) : ThemeLayout::PAGE_TYPE_HOME,
            layoutOption: \trim((string)$layoutIdentity->layoutOption) !== ''
                ? \trim((string)$layoutIdentity->layoutOption)
                : 'default',
        );
    }

    /**
     * A failed on-demand bake is an operational signal, not a request failure:
     * the page still renders from source slots, but the per-request cost stays.
     */
    private function logBakeFailure(string $reason): void
    {
        if (!\function_exists('w_log_warning')) {
            return;
        }
        try {
            \w_log_warning('[ThemeSolidify] on-demand bake failed: ' . $reason);
        } catch (\Throwable) {
        }
    }

    public function resolveForIdentity(ThemeVersionIdentity $identity, string $layoutType, string $layoutOption = 'default', string $targetType = 'global', ?int $targetId = null): ?string
    {
        $layoutType = \trim($layoutType);
        $layoutOption = \trim($layoutOption);
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }
        // Controllers that call resolveForIdentity directly (skipping resolveExecutableLayoutPath)
        // still need current layout name for required-injection page-type resolution.
        if ($layoutType !== '') {
            RequestContext::set(
                \Weline\Widget\Cache\WidgetOutputCache::REQUEST_LAYOUT_NAME_KEY,
                $layoutType . '.' . $layoutOption,
            );
        }
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
            if (\is_string($typed) && trim($typed) !== '') {
                $typed = json_decode($typed, true);
            }
            if (\is_array($typed)) {
                // Storefront editor iframe only has runtime ThemeApplicationContext.
                // ThemeEditorContextFactory requires editor/preview/asset purpose, so
                // parse the typed cursor owner here without re-entering that gate.
                $themeId = (int)($typed['theme_id'] ?? $themeId);
                $area = (string)($typed['area'] ?? $typed['editor_area'] ?? $area);
                $scopePayload = $typed['scope'] ?? null;
                if (\is_array($scopePayload)) {
                    $scope = (string)($scopePayload['storage_scope'] ?? $scopePayload['scope_key'] ?? '');
                    $storeMode = (string)($scopePayload['store_mode'] ?? 'normal');
                    if ($scope === '' && isset($scopePayload['identity']) && \is_array($scopePayload['identity'])) {
                        $scope = $this->scopes->toStorageScope(
                            ScopeIdentity::fromArray($scopePayload['identity'])
                        );
                        $storeMode = (string)($scopePayload['identity']['store_mode'] ?? $storeMode);
                    }
                } else {
                    $scope = (string)($context['canonical_scope'] ?? $context['scope'] ?? 'default.default.default');
                    $storeMode = (string)($context['store_mode'] ?? 'normal');
                }
                $layoutOption = (string)($typed['layout_option'] ?? $layoutOption);
                $targetType = (string)($typed['target_type'] ?? $targetType);
                $targetId = (int)($typed['target_id'] ?? $targetId ?? 0);
            } else {
                $themeId = (int)($context['theme_id'] ?? $context[$area . '_theme_id'] ?? $themeId);
                $scope = (string)($context['canonical_scope'] ?? $context['scope'] ?? 'default.default.default');
                $storeMode = (string)($context['store_mode'] ?? 'normal');
                $layoutOption = (string)($context['layout_option'] ?? $layoutOption);
                $targetType = (string)($context['theme_layout_target_type'] ?? $context['theme_layout_source_target_type'] ?? $targetType);
                $targetId = (int)($context['theme_layout_target_id'] ?? $context['theme_layout_source_target_id'] ?? $targetId ?? 0);
            }
            if ($scope === '') {
                $scope = 'default.default.default';
            }
            $cursor = array_replace($context, ['theme_id'=>$themeId, 'canonical_scope'=>$scope, 'scope'=>$scope, 'store_mode'=>$storeMode, 'area'=>$area]);
            $versionId = (int)($cursor['theme_version_id'] ?? $cursor['version_id'] ?? 0);
            if ($versionId < 1) {
                $version = ($cursor['status'] ?? 'draft') === 'published'
                    ? $this->scopeVersions->getPublished($themeId, $scope, $storeMode, $area)
                    : $this->scopeVersions->getCurrent($themeId, $scope, $storeMode, $area);
                if ($version === null) { return null; }
                $versionId = $version->getVersionId();
                $cursor['content_revision'] = (int)($cursor['content_revision'] ?? 0) > 0
                    ? (int)$cursor['content_revision']
                    : max(1, (int)$version->getContentRevision());
            }
            $cursor['mode'] = (string)($cursor['mode'] ?? (($cursor['status'] ?? 'draft') === 'published' ? 'formal' : 'draft'));
            $locale = trim((string)($context['locale'] ?? (\is_array($typed) ? ($typed['locale'] ?? '') : '')));
            if ($locale === '' || strcasecmp($locale, 'default') === 0) {
                $locale = 'zh_Hans_CN';
            }
            $layoutIdentity = new LayoutIdentity($layoutOption, $scope, $targetType, max(0, (int)$targetId), (string)($context['locale'] ?? ''));
            // purpose selects ThemeApplicationContext::current($area, $purpose).
            // Storefront iframe installs runtime, not preview/editor.
            $resolveIdentity = $layoutIdentity->toArray() + [
                'store_mode' => $storeMode,
                'content_scope' => \Weline\Theme\Api\Scoped\ThemeContentScope::fromStoredOwner($scope, $storeMode, $locale),
                'purpose' => 'runtime',
            ];
            $resolved = ObjectManager::getInstance(ThemeVersionPreviewResolver::class)->resolve($themeId, $layoutType, $area, $resolveIdentity, $versionId, $cursor);
            RequestContext::set(ThemeVersionPreviewResolver::REQUEST_KEY, $resolved);
            if (!($resolved['resolved'] ?? false) || !($resolved['version_identity'] ?? null) instanceof ThemeVersionIdentity) {
                throw new \RuntimeException((string)($resolved['reason'] ?? 'theme_layout_preview_source_unresolved'));
            }
            return [$resolved['version_identity'], $layoutIdentity, true];
        }

        $scope = 'default.default.default';
        $storeMode = 'normal';
        $application = ThemeApplicationContext::current($area, 'runtime');
        if ($application instanceof ThemeApplicationContext) {
            if ($application->themeId > 0) {
                $themeId = $application->themeId;
            } elseif ($themeId < 1) {
                $registered = ObjectManager::getInstance(DefaultThemeInterface::class)->getRegisteredDefault($area);
                $catalogId = (int)($registered[WelineTheme::schema_fields_ID] ?? $registered['id'] ?? 0);
                if ($catalogId > 0) {
                    $themeId = $catalogId;
                }
            }
            $scope = $application->versionOwnerScope;
            $storeMode = $application->versionOwnerStoreMode;
        } elseif (RequestContext::isInitialized()) {
            $runtimeIdentity = RequestContext::scopeIdentity();
            if ($runtimeIdentity instanceof ScopeIdentity) {
                $scope = $this->scopes->contextFromIdentity($runtimeIdentity)->storageScope;
                $storeMode = $runtimeIdentity->storeMode ?? 'normal';
            }
        }
        $version = $this->resolvePublishedVersionAlongThemeChain(
            $themeId,
            $scope,
            $storeMode,
            $area,
            $layoutType,
            $layoutOption,
        );
        if ($version === null || $version->getVersionId() < 1) { return null; }
        $identity = $version->toVersionIdentity()->withVersion($version->getVersionId(), ThemeVersionIdentity::MODE_FORMAL, max(1, $version->getContentRevision()));
        if ($identity->area !== $area || $identity->storeMode !== $storeMode) {
            throw new \RuntimeException('theme_layout_published_owner_mismatch');
        }
        return [$identity, new LayoutIdentity($layoutOption, $identity->canonicalScope, $targetType, max(0, (int)$targetId)), false];
    }

    /**
     * Child design themes may bind package_defaults (theme_version_id=0) while
     * some layouts still inherit from the parent/module chain (LayoutScanner).
     * Inherit ancestor published solidification only for layouts the child does
     * not override in its own design tree — otherwise parent bake would clobber
     * brand homepage/content (e.g. design-theme promo → default promo-banner).
     *
     * Own published alone is not enough: a child with a sealed version still
     * reads parent identity for layouts it does not override (no sibling/shared disk write).
     */
    private function resolvePublishedVersionAlongThemeChain(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
        string $layoutType,
        string $layoutOption,
    ): ?ThemeScopeVersion {
        $own = $this->scopeVersions->getPublished($themeId, $scope, $storeMode, $area);
        if ($this->themeProvidesLayoutOverride($themeId, $layoutType, $layoutOption, $area)) {
            return ($own instanceof ThemeScopeVersion && $own->getVersionId() > 0) ? $own : null;
        }
        $seen = [$themeId => true];
        $candidateId = $themeId;
        while ($candidateId > 0) {
            $theme = (clone ObjectManager::getInstance(WelineTheme::class))->clearData()->clearQuery()->load($candidateId);
            $parentId = (int)$theme->getParentId();
            if ($parentId < 1 || (int)$theme->getId() !== $candidateId || isset($seen[$parentId])) {
                break;
            }
            $seen[$parentId] = true;
            $candidateId = $parentId;
            $version = $this->scopeVersions->getPublished($candidateId, $scope, $storeMode, $area);
            if ($version instanceof ThemeScopeVersion && $version->getVersionId() > 0) {
                return $version;
            }
        }

        return ($own instanceof ThemeScopeVersion && $own->getVersionId() > 0) ? $own : null;
    }

    /** True when the active theme's design layer supplies this layout option. */
    private function themeProvidesLayoutOverride(
        int $themeId,
        string $layoutType,
        string $layoutOption,
        string $area,
    ): bool {
        return ThemeLayoutDesignOverride::provides($themeId, $layoutType, $layoutOption, $area);
    }
}
