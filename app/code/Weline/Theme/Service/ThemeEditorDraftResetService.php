<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Model\ThemeScopePatch;
use Weline\Theme\Model\ThemeScopeWorkspace;

/**
 * Clears only the current editing draft materialization for selected resources.
 * Does not delete ThemeScopeRevision / ThemeScopeRelease / ThemeLayoutVersion rows,
 * and never creates restore/backup versions.
 */
final class ThemeEditorDraftResetService
{
    public const LAYOUT_SCOPE_CURRENT = 'current_layout';
    public const LAYOUT_SCOPE_ALL = 'all_layouts';

    public function __construct(
        private readonly ThemeScopeWorkspace $workspaces,
        private readonly ThemeScopePatch $patches,
        private readonly ThemeLayoutService $layoutService,
        private readonly ThemeRuntimeCacheCleaner $cacheCleaner,
        private readonly ThemeLayoutScopeNormalizer $layoutScopeNormalizer,
        private readonly WidgetDefaultInjectionService $defaultInjectionService,
    ) {
    }

    /**
     * @param list<string> $resources
     * @return array{
     *   resources:list<string>,
     *   layout_scope:string,
     *   cleared:array<string,array{workspaces:int,patches:int,layout_drafts:bool,default_injections:array{cleared_user_deleted:int,applied_defaults:int}}>,
     *   cache:array<string,mixed>
     * }
     */
    public function reset(
        ThemeEditorContext $baseline,
        array $resources,
        string $layoutScope = self::LAYOUT_SCOPE_CURRENT,
    ): array {
        $resources = $this->normalizeResources($resources);
        $layoutScope = $layoutScope === self::LAYOUT_SCOPE_ALL
            ? self::LAYOUT_SCOPE_ALL
            : self::LAYOUT_SCOPE_CURRENT;

        $cleared = [];
        foreach ($resources as $resourceType) {
            $cleared[$resourceType] = $this->resetResource($baseline, $resourceType, $layoutScope);
        }

        $cache = $this->cacheCleaner->clearNonGlobalCaches(
            $baseline->themeId > 0 ? $baseline->themeId : null,
            'theme_editor_draft_reset',
        );

        return [
            'resources' => $resources,
            'layout_scope' => $layoutScope,
            'cleared' => $cleared,
            'cache' => $cache,
        ];
    }

    /**
     * @return array{workspaces:int,patches:int,layout_drafts:bool,default_injections:array{cleared_user_deleted:int,applied_defaults:int}}
     */
    private function resetResource(
        ThemeEditorContext $baseline,
        string $resourceType,
        string $layoutScope,
    ): array {
        $context = $baseline->withResource($resourceType);
        $workspaceCount = 0;
        $patchCount = 0;

        if ($resourceType === ThemeEditorContext::RESOURCE_LAYOUT
            && $layoutScope === self::LAYOUT_SCOPE_ALL
        ) {
            foreach ($this->findLayoutWorkspaces($context) as $workspace) {
                $workspaceCount++;
                $patchCount += $this->clearDraftPatchesForWorkspace($workspace);
            }
        } else {
            $workspace = $this->findWorkspace($context);
            if ($workspace instanceof ThemeScopeWorkspace) {
                $workspaceCount = 1;
                $patchCount = $this->clearDraftPatchesForWorkspace($workspace);
            }
        }

        $layoutDrafts = false;
        $defaultInjections = [
            'cleared_user_deleted' => 0,
            'applied_defaults' => 0,
        ];
        if ($resourceType === ThemeEditorContext::RESOURCE_LAYOUT) {
            $identity = $this->layoutIdentityFromContext($context);
            if ($layoutScope === self::LAYOUT_SCOPE_ALL) {
                $layoutDrafts = $this->layoutService->discardDraft(
                    $context->themeId,
                    null,
                    $identity,
                );
            } else {
                $layoutDrafts = $this->layoutService->discardDraft(
                    $context->themeId,
                    $context->layoutType,
                    $identity,
                );
            }
            if ($layoutDrafts) {
                $componentArea = $context->area === PreviewContextService::AREA_BACKEND
                    ? PreviewContextService::AREA_BACKEND
                    : PreviewContextService::AREA_FRONTEND;
                $pageType = $layoutScope === self::LAYOUT_SCOPE_ALL ? null : $context->layoutType;
                $defaultInjections = $this->defaultInjectionService->restoreDefaultInjectionsAfterDraftReset(
                    $context->themeId,
                    $identity,
                    $componentArea,
                    $pageType,
                );
            }
        }

        return [
            'workspaces' => $workspaceCount,
            'patches' => $patchCount,
            'layout_drafts' => $layoutDrafts,
            'default_injections' => $defaultInjections,
        ];
    }

    /**
     * @return list<ThemeScopeWorkspace>
     */
    private function findLayoutWorkspaces(ThemeEditorContext $context): array
    {
        $rows = (clone $this->workspaces)->clearData()->clearQuery()
            ->where(ThemeScopeWorkspace::schema_fields_SCOPE, $context->scope->storageScope)
            ->where(ThemeScopeWorkspace::schema_fields_STORE_MODE, $context->scope->storeMode)
            ->where(ThemeScopeWorkspace::schema_fields_AREA, $context->area)
            ->where(ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE, ThemeEditorContext::RESOURCE_LAYOUT)
            ->where(ThemeScopeWorkspace::schema_fields_THEME_ID, $context->identityThemeId())
            ->where(ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION, $context->identityLayoutOption())
            ->where(ThemeScopeWorkspace::schema_fields_LOCALE, $context->identityLocale())
            ->where(ThemeScopeWorkspace::schema_fields_TARGET_TYPE, $context->identityTargetType())
            ->where(ThemeScopeWorkspace::schema_fields_TARGET_ID, $context->identityTargetId())
            ->select()
            ->fetchArray();

        $result = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row[ThemeScopeWorkspace::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $workspace = clone $this->workspaces;
            $workspace->clearData()->clearQuery()->load($id);
            if ($workspace->getId() > 0) {
                $result[] = $workspace;
            }
        }

        return $result;
    }

    private function findWorkspace(ThemeEditorContext $context): ?ThemeScopeWorkspace
    {
        $workspace = clone $this->workspaces;
        $workspace->clearData()->clearQuery()
            ->where(ThemeScopeWorkspace::schema_fields_IDENTITY_HASH, $context->identityHash())
            ->find()
            ->fetch();

        return $workspace->getId() > 0 ? $workspace : null;
    }

    private function clearDraftPatchesForWorkspace(ThemeScopeWorkspace $workspace): int
    {
        $draftRevisionId = (int)$workspace->getData(ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID);
        if ($draftRevisionId <= 0) {
            return 0;
        }

        return $this->deletePatchesForRevision($draftRevisionId);
    }

    private function deletePatchesForRevision(int $revisionId): int
    {
        if ($revisionId <= 0) {
            return 0;
        }

        $rows = (clone $this->patches)->clearData()->clearQuery()
            ->where(ThemeScopePatch::schema_fields_REVISION_ID, $revisionId)
            ->select()
            ->fetchArray();
        $count = 0;
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $patchId = (int)($row[ThemeScopePatch::schema_fields_ID] ?? 0);
            if ($patchId <= 0) {
                continue;
            }
            $patch = clone $this->patches;
            $patch->clearData()->clearQuery()->load($patchId);
            if ($patch->getId() > 0) {
                $patch->delete()->fetch();
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string}
     */
    private function layoutIdentityFromContext(ThemeEditorContext $context): array
    {
        return [
            'layout_option' => $context->layoutOption,
            'scope' => $this->layoutScopeNormalizer->encodeStorageScope(
                $context->scope->storageScope,
                $context->scope->storeMode,
            ),
            'target_type' => $context->targetType,
            'target_id' => $context->targetId,
            'locale_code' => $context->locale === 'default' ? '' : $context->locale,
        ];
    }

    /**
     * @param list<mixed> $resources
     * @return list<string>
     */
    private function normalizeResources(array $resources): array
    {
        $normalized = [];
        foreach ($resources as $resource) {
            $resource = \trim((string)$resource);
            if ($resource === '' || !\in_array($resource, ThemeEditorContext::RESOURCES, true)) {
                continue;
            }
            $normalized[$resource] = $resource;
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('theme_editor_draft_reset_resources_empty');
        }

        return \array_values($normalized);
    }
}
