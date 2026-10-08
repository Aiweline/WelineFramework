<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;

/**
 * Clears only the current editing draft materialization for selected resources.
 * Preserves historical patches and ThemeScopeRevision / ThemeScopeRelease / ThemeLayoutVersion rows,
 * and never creates restore/backup versions.
 */
final class ThemeEditorDraftResetService
{
    public const LAYOUT_SCOPE_CURRENT = 'current_layout';
    public const LAYOUT_SCOPE_ALL = 'all_layouts';

    public function __construct(
        private readonly ThemeScopeWorkspace $workspaces,
        private readonly ThemeScopedWorkspaceInterface $scopedWorkspace,
        private readonly ThemeLayoutService $layoutService,
        private readonly ThemeRuntimeCacheCleaner $cacheCleaner,
        private readonly ThemeLayoutScopeNormalizer $layoutScopeNormalizer,
        private readonly WidgetDefaultInjectionService $defaultInjectionService,
    ) {
    }

    /**
     * @param list<string> $resources
     * @param callable|null $onProgress signature: function(string $step, string $message, int $percent, array $extra): void
     * @return array{
     *   resources:list<string>,
     *   layout_scope:string,
     *   cleared:array<string,array{workspaces:int,patches:int,layout_drafts:bool,default_injections:array{cleared_user_deleted:int,applied_defaults:int}}>,
     *   cache:array<string,mixed>,
     *   ssh_log:list<string>,
     *   solidify?:array{ok:bool,reason?:string,theme_version_id?:int,content_revision?:int,fingerprints?:array<string,string>}
     * }
     */
    public function reset(
        ThemeEditorContext $baseline,
        array $resources,
        string $layoutScope = self::LAYOUT_SCOPE_CURRENT,
        ?callable $onProgress = null,
    ): array {
        $resources = $this->normalizeResources($resources);
        $layoutScope = $layoutScope === self::LAYOUT_SCOPE_ALL
            ? self::LAYOUT_SCOPE_ALL
            : self::LAYOUT_SCOPE_CURRENT;

        $sshLog = [];
        $emit = function (
            string $step,
            string $message,
            int $percent,
            string $sshLine = '',
            array $extra = [],
        ) use (&$sshLog, $onProgress): void {
            if ($sshLine !== '') {
                $sshLog[] = $sshLine;
            }
            if ($onProgress === null) {
                return;
            }
            $payload = $extra;
            $payload['ssh'] = $sshLine !== '' ? $sshLine : ($extra['ssh'] ?? '');
            $payload['ssh_log'] = $sshLog;
            $onProgress($step, $message, \max(0, \min(100, $percent)), $payload);
        };

        $scope = $baseline->scope->storageScope;
        $storeMode = $baseline->scope->storeMode;
        $emit(
            'start',
            (string)__('开始重置当前编辑草稿'),
            2,
            \sprintf(
                '$ theme-editor reset-draft --theme=%d --scope=%s --store-mode=%s --layout=%s --layout-scope=%s --resources=%s',
                $baseline->themeId,
                $scope,
                $storeMode,
                $baseline->layoutType,
                $layoutScope,
                \implode(',', $resources),
            ),
            [
                'theme_id' => $baseline->themeId,
                'scope' => $scope,
                'layout_type' => $baseline->layoutType,
                'layout_scope' => $layoutScope,
                'resources' => $resources,
            ],
        );

        $cleared = [];
        $resourceTotal = \count($resources);
        $resourceIndex = 0;
        foreach ($resources as $resourceType) {
            $resourceIndex++;
            $percent = 5 + (int)\floor(($resourceIndex / \max(1, $resourceTotal)) * 45);
            $emit(
                'clear_' . $resourceType,
                (string)__('正在清理草稿资源：%{resource}', ['resource' => $resourceType]),
                $percent,
                \sprintf('$ clear-draft-resource --type=%s --layout-scope=%s', $resourceType, $layoutScope),
                ['resource' => $resourceType],
            );
            $cleared[$resourceType] = $this->resetResource($baseline, $resourceType, $layoutScope);
            $summary = $cleared[$resourceType];
            $emit(
                'cleared_' . $resourceType,
                (string)__('已清理 %{resource}：workspaces=%{workspaces} patches=%{patches}', [
                    'resource' => $resourceType,
                    'workspaces' => (int)($summary['workspaces'] ?? 0),
                    'patches' => (int)($summary['patches'] ?? 0),
                ]),
                $percent,
                \sprintf(
                    'ok clear %s workspaces=%d patches=%d layout_drafts=%s',
                    $resourceType,
                    (int)($summary['workspaces'] ?? 0),
                    (int)($summary['patches'] ?? 0),
                    !empty($summary['layout_drafts']) ? 'yes' : 'no',
                ),
                ['resource' => $resourceType, 'cleared' => $summary],
            );
        }

        $emit(
            'cache',
            (string)__('正在清理草稿预览缓存'),
            55,
            \sprintf('$ clear-draft-preview-caches --theme=%d', $baseline->themeId),
        );
        $cache = $this->cacheCleaner->clearDraftPreviewCaches(
            $baseline->themeId > 0 ? $baseline->themeId : null,
        );
        $emit(
            'cache_done',
            (string)__('草稿预览缓存已清理'),
            60,
            'ok cache cleared',
            ['cache' => $cache],
        );

        $result = [
            'resources' => $resources,
            'layout_scope' => $layoutScope,
            'cleared' => $cleared,
            'cache' => $cache,
            'ssh_log' => $sshLog,
        ];

        if (\in_array(ThemeEditorContext::RESOURCE_LAYOUT, $resources, true)) {
            $emit(
                'solidify',
                (string)__('正在重固当前作用域布局固化物'),
                70,
                \sprintf(
                    '$ theme-layout solidify --theme=%d --scope=%s --store-mode=%s --layout=%s',
                    $baseline->themeId,
                    $scope,
                    $storeMode,
                    $baseline->layoutType,
                ),
            );
            /** @var ThemeLayoutEntityBakeCoordinator $bake */
            $bake = ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class);
            $solidify = $bake->solidifyCurrentScopeVersion(
                $baseline->withResource(ThemeEditorContext::RESOURCE_LAYOUT),
            );
            $result['solidify'] = $solidify;
            $ok = !empty($solidify['ok']);
            $fingerprintCount = \is_array($solidify['fingerprints'] ?? null)
                ? \count($solidify['fingerprints'])
                : 0;
            $emit(
                $ok ? 'solidify_done' : 'solidify_failed',
                $ok
                    ? (string)__('固化物重固完成：version=%{version} fingerprints=%{count}', [
                        'version' => (int)($solidify['theme_version_id'] ?? 0),
                        'count' => $fingerprintCount,
                    ])
                    : (string)__('固化物重固未完成：%{reason}', [
                        'reason' => (string)($solidify['reason'] ?? 'unknown'),
                    ]),
                $ok ? 92 : 88,
                $ok
                    ? \sprintf(
                        'ok solidify theme_version_id=%d content_revision=%d fingerprints=%d',
                        (int)($solidify['theme_version_id'] ?? 0),
                        (int)($solidify['content_revision'] ?? 0),
                        $fingerprintCount,
                    )
                    : \sprintf('fail solidify reason=%s', (string)($solidify['reason'] ?? 'unknown')),
                ['solidify' => $solidify],
            );
        }

        $result['ssh_log'] = $sshLog;
        $emit(
            'done',
            (string)__('当前编辑草稿已重置'),
            100,
            'done reset-draft',
            ['result' => $result],
        );

        return $result;
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
                $patchCount += $this->clearDraftPatchesForWorkspace(
                    $workspace,
                    $context->withLayoutType((string)$workspace->getData(ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE)),
                );
            }
        } else {
            $workspace = $this->findWorkspace($context);
            if ($workspace instanceof ThemeScopeWorkspace) {
                $workspaceCount = 1;
                $patchCount = $this->clearDraftPatchesForWorkspace($workspace, $context);
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

    private function clearDraftPatchesForWorkspace(
        ThemeScopeWorkspace $workspace,
        ThemeEditorContext $context,
    ): int {
        if ((int)$workspace->getData(ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID) <= 0) {
            return 0;
        }

        $state = $this->scopedWorkspace->load($context, true);
        $changes = [];
        foreach ($state['owned_paths'] ?? [] as $path) {
            $changes[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_INHERIT,
                'path' => $path,
            ]);
        }
        if ($changes === []) {
            return 0;
        }

        // A draft pointer may reference a published revision. Restore ownership
        // through the canonical writer so all old patches remain immutable and
        // the reset receives a fresh optimistic revision before publication.
        $this->scopedWorkspace->applyChanges(
            context: $context,
            expectedRevision: (int)$state['revision'],
            expectedParentReleaseId: isset($state['expected_parent_release_id'])
                ? (int)$state['expected_parent_release_id']
                : null,
            changes: $changes,
            actorId: 'system:theme-editor-draft-reset',
            summary: 'theme_editor_draft_reset',
        );

        return \count($changes);
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
