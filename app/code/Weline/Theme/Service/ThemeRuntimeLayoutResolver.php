<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeRevision;
use Weline\Theme\Service\Scoped\ThemeScopedPreviewResolver;

/**
 * Storefront/runtime layout resolution from scoped workspace releases.
 *
 * Published non-target structure may reuse HotCache Policy here (read-model).
 * SlotRenderer must not hold layout/widget process caches.
 */
final class ThemeRuntimeLayoutResolver
{
    private const NO_PLACEMENTS_WIDGET_MODULE = 'Weline_Theme';
    private const NO_PLACEMENTS_WIDGET_TYPE = 'layout_state';
    private const NO_PLACEMENTS_WIDGET_CODE = '__no_widget_placements__';

    public function __construct(
        private readonly ThemeScopedPreviewResolver $previewResolver,
        private readonly ThemeScopedWorkspaceInterface $workspace,
        private readonly ThemeLayoutService $layoutService,
        private readonly ThemeScopeRelease $releases,
        private readonly ThemeScopeRevision $revisions,
    ) {
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    public function resolveLayout(
        int $themeId,
        string $pageType,
        string $status = ThemeLayout::STATUS_PUBLISHED,
        string $area = 'frontend',
        array $identity = [],
    ): array {
        $load = function () use ($themeId, $pageType, $status, $area, $identity): array {
            $context = $this->buildContext($themeId, $pageType, $area, $identity);
            try {
                return $this->previewResolver->resolveStructureLayout(
                    $context,
                    $status === ThemeLayout::STATUS_PUBLISHED
                        ? ThemeLayout::STATUS_PUBLISHED
                        : ThemeLayout::STATUS_DRAFT,
                );
            } catch (\Throwable) {
                return [];
            }
        };

        // Draft / page-level target: always fresh. Published structure: HotCache read-model.
        if ($status === ThemeLayout::STATUS_DRAFT || $this->hasTargetIdentity($identity)) {
            return $load();
        }

        $hotCache = $this->resolvePublishedLayoutHotCache();
        if (!$hotCache instanceof StorefrontScopeHotCache) {
            return $load();
        }

        $resolved = $hotCache->rememberPolicy(
            StorefrontThemeCacheCoordinator::publishedLayoutStructurePolicy(),
            $this->publishedLayoutStructureLogicalKey($themeId, $pageType, $area, $identity),
            $load,
        );

        return \is_array($resolved) ? $resolved : [];
    }

    /**
     * Structure layout plus request/editor locale I18N overlay (not structure-cacheable).
     *
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    public function resolveLayoutForRender(
        int $themeId,
        string $pageType,
        string $status = ThemeLayout::STATUS_PUBLISHED,
        string $area = 'frontend',
        array $identity = [],
        ?string $overlayLocale = null,
    ): array {
        $layout = $this->resolveLayout($themeId, $pageType, $status, $area, $identity);
        if ($layout === []) {
            return [];
        }

        return $this->overlayLocaleOnLayout(
            $layout,
            $themeId,
            $pageType,
            $status,
            $area,
            $identity,
            $overlayLocale,
        );
    }

    /**
     * Apply RESOURCE_I18N translations onto an already-built area/widget layout.
     * Used by storefront entity hard-cut fill (baked structure) and DB layout paths.
     *
     * @param array<string,mixed> $layout
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    public function overlayLocaleOnLayout(
        array $layout,
        int $themeId,
        string $pageType,
        string $status = ThemeLayout::STATUS_PUBLISHED,
        string $area = 'frontend',
        array $identity = [],
        ?string $overlayLocale = null,
    ): array {
        if ($layout === []) {
            return [];
        }
        $locale = \trim((string)($overlayLocale ?? ''));
        if ($locale === '' || \strcasecmp($locale, 'default') === 0) {
            $locale = \trim((string)($identity['locale_code'] ?? $identity['locale'] ?? ''));
        }
        if ($locale === '' || \strcasecmp($locale, 'default') === 0) {
            try {
                $locale = \trim((string)(RequestContext::locale() ?? ''));
            } catch (\Throwable) {
                $locale = '';
            }
        }
        if ($locale === '' || \strcasecmp($locale, 'default') === 0) {
            return $layout;
        }

        try {
            $context = $this->buildContext($themeId, $pageType, $area, $identity)
                ->withLocale($locale);

            return $this->previewResolver->applyLayoutLocaleOverlay(
                $layout,
                $context,
                $status === ThemeLayout::STATUS_PUBLISHED
                    ? ThemeLayout::STATUS_PUBLISHED
                    : ThemeLayout::STATUS_DRAFT,
            );
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning(
                    'theme_runtime_locale_overlay_failed: ' . $e->getMessage(),
                    [
                        'theme_id' => $themeId,
                        'page_type' => $pageType,
                        'locale' => $locale,
                        'scope' => (string)($identity['scope'] ?? ''),
                        'exception' => $e::class,
                    ],
                    'theme_runtime_i18n',
                );
            }

            return $layout;
        }
    }

    /**
     * @param array<string,mixed> $identity
     */
    public function buildContext(
        int $themeId,
        string $pageType,
        string $area,
        array $identity = [],
    ): ThemeEditorContext {
        $application = $identity['application_context'] ?? ThemeApplicationContext::current($area, (string)($identity['purpose'] ?? 'runtime'));
        if (!$application instanceof ThemeApplicationContext) {
            throw new \InvalidArgumentException('theme_runtime_consumer_context_required');
        }
        if ($application->themeId !== $themeId || $application->area !== $area) {
            throw new \InvalidArgumentException('theme_runtime_context_theme_mismatch');
        }
        $scopeContext = $identity['content_scope'] ?? ThemeContentScope::fromApplication($application);
        if (!$scopeContext instanceof ThemeContentScope) {
            throw new \InvalidArgumentException('theme_runtime_content_scope_invalid');
        }
        if (!isset($identity['content_scope']) && $application->themeVersionId > 0
            && in_array($application->purpose, ['runtime','preview'], true)) {
            while ($scopeContext->storageScope !== $application->versionOwnerScope
                || $scopeContext->storeMode !== $application->versionOwnerStoreMode) {
                if ($scopeContext->parent === null) {
                    throw new \InvalidArgumentException('theme_runtime_version_owner_not_supplied');
                }
                $scopeContext = $scopeContext->parent;
            }
        }
        $identity = $this->normalizeIdentity($identity);
        return new ThemeEditorContext(
            scope: $scopeContext,
            area: $area === 'backend' ? 'backend' : 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: $themeId,
            layoutType: $pageType,
            layoutOption: (string)$identity['layout_option'],
            locale: 'default',
            targetType: (string)$identity['target_type'],
            targetId: (int)$identity['target_id'],
            application: $application,
        );
    }

    /**
     * @param array<string,mixed> $identity
     */
    public function hasNoWidgetPlacements(
        int $themeId,
        string $pageType,
        string $status,
        array $identity = [],
        string $area = 'frontend',
    ): bool {
        try {
            $context = $this->buildContext($themeId, $pageType, $area, $identity);
            $includeDraft = $status !== ThemeLayout::STATUS_PUBLISHED;
            $state = $this->workspace->load($context, $includeDraft);
            $payloadKey = $includeDraft ? 'draft_payload' : 'published_payload';
            $payload = \is_array($state[$payloadKey] ?? null) ? $state[$payloadKey] : [];
            if ($this->payloadHasNoPlacementsMarker($payload)) {
                return true;
            }
            if ($payload !== []) {
                return false;
            }
        } catch (\Throwable) {
            // Fall through to legacy marker lookup during transition.
        }

        return $this->layoutService->hasNoWidgetPlacements($themeId, $pageType, $status, $identity);
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{themePublishedVersionId:string,themePublishedVersion:string}
     */
    public function resolvePublishedVersionInfo(
        ?int $themeId = null,
        string $pageType = 'homepage',
        array $identity = [],
        string $area = 'frontend',
    ): array {
        $empty = [
            'themePublishedVersionId' => '',
            'themePublishedVersion' => '',
        ];
        if ($themeId === null || $themeId <= 0) {
            return $empty;
        }

        foreach ($this->identityCandidates($identity) as $candidate) {
            try {
                $context = $this->buildContext($themeId, $pageType, $area, $candidate);
                $state = $this->workspace->load($context, false);
                $releaseId = (int)($state['effective_release_id'] ?? $state['published_release_id'] ?? 0);
                if ($releaseId <= 0) {
                    continue;
                }
                $label = $this->releaseLabel($releaseId);

                return [
                    'themePublishedVersionId' => (string)$releaseId,
                    'themePublishedVersion' => $label,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $empty;
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string}
     */
    private function normalizeIdentity(array $identity): array
    {
        if ($identity === []) {
            $installed = RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY);
            if ($installed instanceof LayoutIdentity) {
                return $installed->toArray();
            }
        }

        return [
            'layout_option' => \trim((string)($identity['layout_option'] ?? 'default')) ?: 'default',
            'scope' => \trim((string)($identity['scope'] ?? '')),
            'target_type' => \trim((string)($identity['target_type'] ?? 'global')) ?: 'global',
            'target_id' => \max(0, (int)($identity['target_id'] ?? 0)),
            'locale_code' => \trim((string)($identity['locale_code'] ?? $identity['locale'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function payloadHasNoPlacementsMarker(array $payload): bool
    {
        $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            if ((string)($node['widget_module'] ?? '') === self::NO_PLACEMENTS_WIDGET_MODULE
                && (string)($node['widget_type'] ?? '') === self::NO_PLACEMENTS_WIDGET_TYPE
                && (string)($node['widget_code'] ?? '') === self::NO_PLACEMENTS_WIDGET_CODE
            ) {
                return true;
            }
        }

        return (bool)($payload['selection']['no_widget_placements'] ?? false);
    }

    private function releaseLabel(int $releaseId, int $depth = 0): string
    {
        $release = clone $this->releases;
        $release->clearData()->clearQuery()->load($releaseId);
        if (!$release->getId()) {
            return '#' . $releaseId;
        }
        $reason = \trim((string)$release->getData(ThemeScopeRelease::schema_fields_REASON));
        // 继承传播：用父级已发布修订号/摘要，避免露出 parent_release_propagation。
        if ($reason === 'parent_release_propagation' && $depth < 5) {
            $parentId = (int)$release->getData(ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID);
            if ($parentId > 0 && $parentId !== $releaseId) {
                return $this->releaseLabel($parentId, $depth + 1);
            }
        }

        $revisionId = (int)$release->getData(ThemeScopeRelease::schema_fields_REVISION_ID);
        if ($revisionId > 0) {
            $revision = clone $this->revisions;
            $revision->clearData()->clearQuery()->load($revisionId);
            $summary = \trim((string)$revision->getData(ThemeScopeRevision::schema_fields_SUMMARY));
            if ($summary !== '' && $this->isHumanVersionLabel($summary)) {
                return $summary;
            }
            $revisionNo = (int)$revision->getData(ThemeScopeRevision::schema_fields_REVISION_NO);
            if ($revisionNo > 0) {
                return 'r' . $revisionNo;
            }
        }

        // reason 仅当像人读说明时才展示；theme_editor_publish 等机器码一律不用。
        if ($reason !== '' && $this->isHumanVersionLabel($reason)) {
            return $reason;
        }

        return '#' . $releaseId;
    }

    private function isHumanVersionLabel(string $label): bool
    {
        $label = \trim($label);
        if ($label === '') {
            return false;
        }
        // snake_case / code-like：layout_node_added、theme_editor_publish、parent_release_propagation
        if (\preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)+$/', $label) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $identity
     * @return list<array{layout_option:string,scope:string,locale_code:string,target_type:string,target_id:int}>
     */
    private function identityCandidates(array $identity): array
    {
        if ($identity !== []) { return [$identity]; }
        $application = ThemeApplicationContext::current('frontend');
        if ($application === null) { throw new \InvalidArgumentException('theme_runtime_consumer_context_required'); }
        $out = [];
        $scope = ThemeContentScope::fromApplication($application);
        while ($scope !== null) {
            $out[] = ['application_context'=>$application, 'content_scope'=>$scope, 'scope'=>$scope->storageScope,
                'store_mode'=>$scope->storeMode, 'layout_option'=>'default', 'locale_code'=>'', 'target_type'=>'global', 'target_id'=>0];
            $scope = $scope->parent;
        }
        return $out;
    }

    /**
     * @param array{target_type?:string,target_id?:int} $identity
     */
    private function hasTargetIdentity(array $identity): bool
    {
        $targetType = \trim((string)($identity['target_type'] ?? ''));
        $targetId = (int)($identity['target_id'] ?? 0);

        return ($targetType !== '' && $targetType !== 'global') || $targetId > 0;
    }

    /**
     * @param array{layout_option?:string,scope?:string,target_type?:string,target_id?:int} $identity
     */
    private function publishedLayoutStructureLogicalKey(
        int $themeId,
        string $pageType,
        string $area,
        array $identity,
    ): string {
        $application = $identity['application_context'] ?? ThemeApplicationContext::current($area);
        if (!$application instanceof ThemeApplicationContext) {
            throw new \InvalidArgumentException('theme_runtime_consumer_context_required');
        }
        $inputKey = hash('sha256', json_encode($application->toArray(), JSON_THROW_ON_ERROR));
        return 'pub_layout|'
            . $inputKey . '|'
            . ($area === 'backend' ? 'backend' : 'frontend') . '|'
            . $themeId . '|'
            . $pageType . '|'
            . \trim((string)($identity['layout_option'] ?? 'default')) . '|'
            . \trim((string)($identity['scope'] ?? '')) . '|'
            . \trim((string)($identity['target_type'] ?? 'global')) . '|'
            . (int)($identity['target_id'] ?? 0);
    }

    private function resolvePublishedLayoutHotCache(): ?StorefrontScopeHotCache
    {
        try {
            $resolved = ObjectManager::getInstance(StorefrontScopeHotCache::class);

            return $resolved instanceof StorefrontScopeHotCache ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
