<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Api\Scoped\ThemePublishedSnapshotReaderInterface;
use Weline\Theme\Api\Scoped\ThemeResolvedValue;
use Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeScopePatch;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeReleaseBatch;
use Weline\Theme\Model\ThemeScopeRevision;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Service\LayoutContentValidationRegistry;

/** Canonical per-path draft, merge and immutable release service. */
final class ThemeScopedWorkspace implements ThemeScopedWorkspaceInterface, ThemePublishedSnapshotReaderInterface
{
    private const REQUEST_LOAD_CACHE_PREFIX = 'theme.scoped.workspace.load.v1.';
    private const REQUEST_LOAD_CACHE_KEYS = 'theme.scoped.workspace.load.v1.keys';
    private const REQUEST_WORKSPACE_CACHE_PREFIX = 'theme.scoped.workspace.row.v1.';
    private const REQUEST_WORKSPACE_CACHE_KEYS = 'theme.scoped.workspace.row.v1.keys';

    private readonly ThemeScopeReleaseBatch $releaseBatches;

    public function __construct(
        private readonly ThemeScopeWorkspace $workspaces,
        private readonly ThemeScopeRevision $revisions,
        private readonly ThemeScopePatch $patches,
        private readonly ThemeScopeRelease $releases,
        private readonly ScopeHierarchyInterface $scopes,
        private readonly ThemeScopedResourceAdapterInterface $adapter,
        private readonly ThemePatchEngine $patchEngine,
        private readonly ThemeLayoutPayloadDiffer $layoutDiffer,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly LayoutContentValidationRegistry $contentValidators,
        private readonly ThemeLayoutSnapshotNormalizer $layoutSnapshots,
        ?ThemeScopeReleaseBatch $releaseBatches = null,
    ) {
        $this->releaseBatches = $releaseBatches
            ?? ObjectManager::getInstance(ThemeScopeReleaseBatch::class);
    }

    public function readPublishedSnapshot(ThemeEditorContext $context): array
    {
        $cacheKey = $this->requestLoadCacheKey($context, false) . ':published-snapshot';
        $allowRequestCache = !$this->transactions->isActive($this->workspaces->getConnection());
        if (RequestContext::isInitialized()) {
            if ($allowRequestCache) {
                $cached = RequestContext::get($cacheKey);
                if (\is_array($cached)) {
                    return $cached;
                }
            } else {
                // 事务内只清除此轻读快照；实际访问的行由 publishedState 定向跳过旧 memo。
                RequestContext::remove($cacheKey);
            }
        }

        $readSnapshot = function () use ($context, $allowRequestCache): array {
            $published = $this->publishedState($context, $allowRequestCache);
            return [
                'payload' => $published['payload'],
                'release_id' => $published['release_id'],
                'source_scope' => $published['source_scope'],
            ];
        };
        $shareSnapshot = $allowRequestCache && \in_array($context->resourceType, [
            ThemeEditorContext::RESOURCE_LAYOUT,
            ThemeEditorContext::RESOURCE_META,
            ThemeEditorContext::RESOURCE_APPEARANCE,
            ThemeEditorContext::RESOURCE_I18N,
        ], true);
        // 完整资源身份独立于访问者上下文；事务和绑定投影继续使用原读取链。
        $snapshot = $shareSnapshot
            ? ObjectManager::getInstance(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class)->rememberPolicy(
                \Weline\Theme\Service\StorefrontThemeCacheCoordinator::publishedSnapshotPolicy(),
                $context->identityHash(),
                $readSnapshot,
            )
            : $readSnapshot();
        if ($allowRequestCache) {
            // 挂回当前请求，并保留工作区写入口的清理追踪；共享层只保存原始快照。
            $this->rememberRequestLoad($cacheKey, $snapshot);
        }

        return $snapshot;
    }

    /** Read this identity's release using its recorded parent chain. */
    public function readHistoricalLayoutRelease(ThemeEditorContext $context, int $releaseId): ?array
    {
        $release = $this->loadRelease($releaseId);
        if (!$release instanceof ThemeScopeRelease
            || (string)$release->getData(ThemeScopeRelease::schema_fields_IDENTITY_HASH) !== $context->identityHash()) {
            return null;
        }
        return $this->composeLayoutPayloadBySlotNearPriority($release);
    }

    /** Read an immutable draft revision without rebasing it onto today's parent release. */
    public function readHistoricalLayoutRevision(ThemeEditorContext $context, int $revisionId): array
    {
        $missing = ['resolved' => false, 'reason' => 'historical_draft_baseline_missing',
            'draft_revision_id' => $revisionId, 'draft_payload' => null];
        $workspace = $this->findWorkspace($context, false, false);
        $revision = (clone $this->revisions)->clearData()->clearQuery()->load($revisionId);
        if (!$workspace || $revision->getId() !== $revisionId
            || (int)$revision->getData(ThemeScopeRevision::schema_fields_WORKSPACE_ID) !== $workspace->getId()) {
            return $missing + ['identity_mismatch' => true];
        }
        $parent = $this->loadRelease((int)$revision->getData(ThemeScopeRevision::schema_fields_PARENT_RELEASE_ID));
        if ($parent instanceof ThemeScopeRelease) {
            $payload = $this->patchEngine->apply($this->composeLayoutPayloadBySlotNearPriority($parent), $this->commandsForRevision($revisionId));
            return ['resolved' => true, 'reason' => '', 'draft_revision_id' => $revisionId, 'draft_payload' => $payload];
        }
        // A published snapshot of this exact revision is an immutable baseline too.
        $rows = (clone $this->releases)->clearData()->clearQuery()
            ->where(ThemeScopeRelease::schema_fields_WORKSPACE_ID, $workspace->getId())
            ->where(ThemeScopeRelease::schema_fields_REVISION_ID, $revisionId)
            ->where(ThemeScopeRelease::schema_fields_STATUS, ThemeScopeRelease::STATUS_EFFECTIVE)
            ->select()->fetchArray();
        $payloads = [];
        if (is_array($rows) && isset($rows[ThemeScopeRelease::schema_fields_ID])) { $rows = [$rows]; }
        foreach (is_array($rows) ? $rows : [] as $row) {
            $release = (clone $this->releases)->clearData()->setData($row);
            $payload = $this->composeLayoutPayloadBySlotNearPriority($release);
            $payloads[hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))] = $payload;
        }
        if (count($payloads) === 1) {
            return ['resolved' => true, 'reason' => '', 'draft_revision_id' => $revisionId, 'draft_payload' => array_values($payloads)[0]];
        }
        return $missing;
    }

    public function load(ThemeEditorContext $context, bool $includeDraft = true): array
    {
        $cacheKey = $this->requestLoadCacheKey($context, $includeDraft);
        // Never reuse or refill request memo while a write transaction is open:
        // prepare/apply paths can otherwise re-cache a pre-publish snapshot and
        // make post-commit assertCurrentScopedLayoutPublished fail closed.
        $allowRequestCache = !$this->transactions->isActive($this->workspaces->getConnection());
        if ($allowRequestCache && RequestContext::isInitialized()) {
            $cached = RequestContext::get($cacheKey, null);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        $workspace = $this->findWorkspace($context, false, $allowRequestCache);
        $parent = $this->parentPublishedState($context);
        $published = $this->publishedState($context, $allowRequestCache);
        $ownRelease = $workspace instanceof ThemeScopeWorkspace
            ? $this->loadRelease((int)$workspace->getData(ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID))
            : null;
        $publishedRevisionId = $ownRelease instanceof ThemeScopeRelease
            ? $this->nullablePositiveInt($ownRelease->getData(ThemeScopeRelease::schema_fields_REVISION_ID))
            : null;
        $commands = [];
        $draftRevisionId = 0;
        if ($includeDraft && $workspace instanceof ThemeScopeWorkspace) {
            $draftRevisionId = (int)$workspace->getData(
                ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID,
            );
            $commands = $this->commandsForRevision($draftRevisionId);
        }
        // A draft revision with no local patches is an explicit restore-to-inherit state.
        $draftPayload = $draftRevisionId > 0
            ? $this->patchEngine->apply($parent['payload'], $commands)
            : $published['payload'];

        $state = [
            'context' => $context->toArray(),
            'revision' => $workspace?->getRevision() ?? 0,
            'draft_revision_id' => $workspace
                ? $this->nullablePositiveInt($workspace->getData(ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID))
                : null,
            'published_revision_id' => $publishedRevisionId,
            'expected_parent_release_id' => $parent['release_id'],
            'parent_source_scope' => $parent['source_scope'],
            'published_release_id' => $ownRelease?->getId(),
            'effective_release_id' => $published['release_id'],
            'last_good_release_id' => $workspace
                ? $this->nullablePositiveInt($workspace->getData(ThemeScopeWorkspace::schema_fields_LAST_GOOD_RELEASE_ID))
                : null,
            'status' => (string)($workspace?->getData(ThemeScopeWorkspace::schema_fields_STATUS)
                ?: ThemeScopeWorkspace::STATUS_ACTIVE),
            'conflicts' => $workspace?->conflicts() ?? [],
            'owned_paths' => \array_values(\array_map(
                static fn(ThemePatchCommand $command): string => $command->path,
                $commands,
            )),
            'owned_rules' => \array_map(
                static fn(ThemePatchCommand $command): array => [
                    'path' => $command->path,
                    'operation' => $command->operation,
                ],
                $commands,
            ),
            // Sparse provenance rules are cheaper than resolving every visual
            // field separately. The client selects the nearest matching Release
            // rule (and the longest path within that Release); absence means the
            // theme-package default supplied by the resource Adapter.
            'inherited_source_rules' => $this->inheritedSourceRules($parent['release']),
            'changes' => \array_map(
                static fn(ThemePatchCommand $command): array => $command->toArray(),
                $commands,
            ),
            'draft_payload' => $draftPayload,
            'published_payload' => $published['payload'],
            'published_source_scope' => $published['source_scope'],
        ];

        if ($allowRequestCache) {
            $this->rememberRequestLoad($cacheKey, $state);
        }

        return $state;
    }

    public function applyChanges(
        ThemeEditorContext $context,
        int $expectedRevision,
        ?int $expectedParentReleaseId,
        array $changes,
        string $actorId,
        string $actorName = '',
        string $summary = '',
        bool $skipContentValidation = false,
    ): array {
        $this->flushRequestLoadCache();
        $this->assertActor($actorId, $actorName);
        $changes = $this->assertCommands($context, $changes);
        $expectedParentReleaseId = $this->nullablePositiveInt($expectedParentReleaseId);

        $result = $this->transactions->runWrite(
            $this->workspaces->getConnection(),
            function () use (
                $context,
                $expectedRevision,
                $expectedParentReleaseId,
                $changes,
                $actorId,
                $actorName,
                $summary,
                $skipContentValidation,
            ): array {
                $workspace = $this->findWorkspace($context, true);
                $actualRevision = $workspace?->getRevision() ?? 0;
                if ($actualRevision !== $expectedRevision) {
                    throw new \RuntimeException('theme_scope_revision_conflict');
                }
                $parent = $this->parentPublishedState($context);
                if ($parent['release_id'] !== $expectedParentReleaseId) {
                    throw new \RuntimeException('theme_scope_parent_release_conflict');
                }

                if (!$workspace instanceof ThemeScopeWorkspace) {
                    $workspace = $this->createWorkspace($context);
                }
                $current = $this->commandsForRevision((int)$workspace->getData(
                    ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID,
                ));
                $owned = $this->patchEngine->mergeOwnedCommands($current, $changes);
                $baselineParentReleaseId = $this->nullablePositiveInt(
                    $workspace->getData(ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID),
                );
                $oldParent = $actualRevision === 0 && $baselineParentReleaseId === null
                    ? $parent['payload']
                    : $this->payloadForReleaseOrRootBase($baselineParentReleaseId, $context);
                $conflicts = $this->patchEngine->structuralConflicts($oldParent, $parent['payload'], $owned);
                $revisionNo = $actualRevision + 1;
                $revision = $this->insertRevision(
                    $workspace->getId(),
                    $revisionNo,
                    $actualRevision,
                    $parent['release_id'],
                    $actorId,
                    $actorName,
                    $summary,
                    $conflicts,
                );
                $this->insertPatches($workspace->getId(), $revision->getId(), $owned);

                $draftPayload = $this->patchEngine->apply($parent['payload'], $owned);
                if (!$skipContentValidation) {
                    $this->indexLayoutDraft($context, $draftPayload, $revision->getId(), $actorId);
                }

                $workspace->setData([
                    ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID => $revision->getId(),
                    ThemeScopeWorkspace::schema_fields_REVISION => $revisionNo,
                    ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID => $parent['release_id'],
                    ThemeScopeWorkspace::schema_fields_STATUS => $conflicts === []
                        ? ThemeScopeWorkspace::STATUS_ACTIVE
                        : ThemeScopeWorkspace::STATUS_CONFLICT,
                    ThemeScopeWorkspace::schema_fields_CONFLICT_JSON => $this->json($conflicts),
                ])->save();

                return [
                    'revision' => $revisionNo,
                    'revision_id' => $revision->getId(),
                    'expected_parent_release_id' => $parent['release_id'],
                    'owned_paths' => \array_map(
                        static fn(ThemePatchCommand $command): string => $command->path,
                        $owned,
                    ),
                    'owned_rules' => \array_map(
                        static fn(ThemePatchCommand $command): array => [
                            'path' => $command->path,
                            'operation' => $command->operation,
                        ],
                        $owned,
                    ),
                    'changes' => \array_map(
                        static fn(ThemePatchCommand $command): array => $command->toArray(),
                        $owned,
                    ),
                    'conflicts' => $conflicts,
                    'draft_payload' => $draftPayload,
                ];
            },
        );

        if ($context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT
            && \is_array($result['draft_payload'] ?? null)
        ) {
            $this->bakeLayoutEntityAfterWrite($context, $result, $changes);
        }

        $this->flushRequestLoadCache();

        return $result;
    }

    public function replaceEffectivePayload(
        ThemeEditorContext $context,
        int $expectedRevision,
        ?int $expectedParentReleaseId,
        array $effectivePayload,
        string $actorId,
        string $actorName = '',
        string $summary = '',
    ): array {
        if ($context->resourceType !== ThemeEditorContext::RESOURCE_LAYOUT) {
            throw new \InvalidArgumentException('theme_scope_full_payload_replace_resource_invalid');
        }
        $this->flushRequestLoadCache();
        $this->assertActor($actorId, $actorName);
        $expectedParentReleaseId = $this->nullablePositiveInt($expectedParentReleaseId);

        $result = $this->transactions->runWrite(
            $this->workspaces->getConnection(),
            function () use (
                $context,
                $expectedRevision,
                $expectedParentReleaseId,
                $effectivePayload,
                $actorId,
                $actorName,
                $summary,
            ): array {
                $workspace = $this->findWorkspace($context, true);
                $actualRevision = $workspace?->getRevision() ?? 0;
                if ($actualRevision !== $expectedRevision) {
                    throw new \RuntimeException('theme_scope_revision_conflict');
                }
                $parent = $this->parentPublishedState($context);
                if ($parent['release_id'] !== $expectedParentReleaseId) {
                    throw new \RuntimeException('theme_scope_parent_release_conflict');
                }

                $compiled = $this->adapter->compile($context, $effectivePayload);
                $target = \is_array($compiled['payload'] ?? null) ? $compiled['payload'] : $effectivePayload;
                $commands = $this->layoutDiffer->diff($parent['payload'], $target);
                if ($commands !== []) {
                    $commands = $this->assertCommands($context, $commands);
                }
                if (!$workspace instanceof ThemeScopeWorkspace) {
                    $workspace = $this->createWorkspace($context);
                }

                $revisionNo = $actualRevision + 1;
                $revision = $this->insertRevision(
                    $workspace->getId(),
                    $revisionNo,
                    $actualRevision,
                    $parent['release_id'],
                    $actorId,
                    $actorName,
                    $summary,
                    [],
                );
                $this->insertPatches($workspace->getId(), $revision->getId(), $commands);
                $this->indexLayoutDraft($context, $target, $revision->getId(), $actorId);
                $workspace->setData([
                    ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID => $revision->getId(),
                    ThemeScopeWorkspace::schema_fields_REVISION => $revisionNo,
                    ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID => $parent['release_id'],
                    ThemeScopeWorkspace::schema_fields_STATUS => ThemeScopeWorkspace::STATUS_ACTIVE,
                    ThemeScopeWorkspace::schema_fields_CONFLICT_JSON => null,
                ])->save();

                return [
                    'revision' => $revisionNo,
                    'revision_id' => $revision->getId(),
                    'expected_parent_release_id' => $parent['release_id'],
                    'owned_paths' => \array_map(
                        static fn(ThemePatchCommand $command): string => $command->path,
                        $commands,
                    ),
                    'owned_rules' => \array_map(
                        static fn(ThemePatchCommand $command): array => [
                            'path' => $command->path,
                            'operation' => $command->operation,
                        ],
                        $commands,
                    ),
                    'changes' => \array_map(
                        static fn(ThemePatchCommand $command): array => $command->toArray(),
                        $commands,
                    ),
                    'conflicts' => [],
                    'draft_payload' => $this->patchEngine->apply($parent['payload'], $commands),
                ];
            },
        );
        $this->flushRequestLoadCache();

        return $result;
    }

    public function publish(
        ThemeEditorContext $context,
        int $expectedRevision,
        ?int $expectedParentReleaseId,
        string $actorId,
        string $actorName = '',
        string $reason = '',
    ): array {
        $this->flushRequestLoadCache();
        $this->assertActor($actorId, $actorName);
        $expectedParentReleaseId = $this->nullablePositiveInt($expectedParentReleaseId);

        $result = $this->transactions->runWrite(
            $this->workspaces->getConnection(),
            function () use (
                $context,
                $expectedRevision,
                $expectedParentReleaseId,
                $actorId,
                $actorName,
                $reason,
            ): array {
                $workspace = $this->findWorkspace($context, true);
                if (!$workspace instanceof ThemeScopeWorkspace) {
                    throw new \RuntimeException('theme_scope_workspace_missing');
                }
                if ($workspace->getRevision() !== $expectedRevision) {
                    throw new \RuntimeException('theme_scope_revision_conflict');
                }
                $parent = $this->parentPublishedState($context);
                if ($parent['release_id'] !== $expectedParentReleaseId) {
                    throw new \RuntimeException('theme_scope_parent_release_conflict');
                }
                $revisionId = (int)$workspace->getData(ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID);
                if ($revisionId <= 0) {
                    throw new \RuntimeException('theme_scope_draft_revision_missing');
                }
                $commands = $this->commandsForRevision($revisionId);
                $oldParent = $this->payloadForReleaseOrRootBase(
                    $this->nullablePositiveInt($workspace->getData(ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID)),
                    $context,
                );
                $conflicts = $this->patchEngine->structuralConflicts($oldParent, $parent['payload'], $commands);
                if ($conflicts !== []) {
                    $workspace->setData([
                        ThemeScopeWorkspace::schema_fields_STATUS => ThemeScopeWorkspace::STATUS_CONFLICT,
                        ThemeScopeWorkspace::schema_fields_CONFLICT_JSON => $this->json($conflicts),
                    ])->save();
                    $this->markRevisionConflictState($revisionId, $conflicts);

                    // Return normally so the conflict state commits. Throwing
                    // inside runWrite would roll it back and the editor could
                    // never present reset/re-anchor/rebaseline recovery actions.
                    return [
                        'blocked' => true,
                        'revision' => $workspace->getRevision(),
                        'conflicts' => $conflicts,
                    ];
                }

                $currentRelease = $this->loadRelease((int)$workspace->getData(
                    ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID,
                ));
                if ($currentRelease instanceof ThemeScopeRelease
                    && (int)$currentRelease->getData(ThemeScopeRelease::schema_fields_REVISION_ID) === $revisionId
                    && $this->nullablePositiveInt($currentRelease->getData(
                        ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID,
                    )) === $parent['release_id']
                ) {
                    $this->adapter->projectPublished(
                        $context,
                        $currentRelease->payload(),
                        $currentRelease->getId(),
                    );
                    $this->dispatchScopedPublishResourceChange(
                        $context,
                        (int)$currentRelease->getId(),
                        'theme.scoped.publish',
                        true,
                    );
                    return [
                        'release_id' => $currentRelease->getId(),
                        'revision' => $workspace->getRevision(),
                        'parent_release_id' => $parent['release_id'],
                        'fingerprint' => (string)$currentRelease->getData(ThemeScopeRelease::schema_fields_FINGERPRINT),
                        'payload' => $currentRelease->payload(),
                        'conflicts' => [],
                        'idempotent' => true,
                    ];
                }

                $effective = $this->patchEngine->apply($parent['payload'], $commands);
                $compiled = $this->adapter->compile($context, $effective);
                $effective = \is_array($compiled['payload'] ?? null) ? $compiled['payload'] : $effective;
                $artifact = \is_array($compiled['artifact'] ?? null) ? $compiled['artifact'] : [];
                $this->validateLayoutPublication($context, $effective, $actorId);
                $release = $this->insertRelease(
                    $workspace,
                    $context,
                    $revisionId,
                    $parent['release_id'],
                    $effective,
                    $artifact,
                    $actorId,
                    $actorName,
                    $reason,
                );
                // Task 3: prepare layout-entity candidates before the published pointer is visible.
                // Failure here rolls back the DB write so P/D selection is not consumed.
                if ($context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT) {
                    $this->bakeLayoutEntityAfterWrite($context, [
                        'release_id' => $release->getId(),
                        'payload' => $effective,
                        'revision_id' => $revisionId,
                        'changes' => [],
                    ], []);
                }
                $this->adapter->projectPublished($context, $effective, $release->getId());
                $this->markRevisionPublished($revisionId);
                $workspace->setData([
                    ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID => $release->getId(),
                    ThemeScopeWorkspace::schema_fields_LAST_GOOD_RELEASE_ID => $release->getId(),
                    ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID => $parent['release_id'],
                    ThemeScopeWorkspace::schema_fields_STATUS => ThemeScopeWorkspace::STATUS_ACTIVE,
                    ThemeScopeWorkspace::schema_fields_CONFLICT_JSON => null,
                ])->save();
                $this->dispatchScopedPublishResourceChange(
                    $context,
                    (int)$release->getId(),
                    'theme.scoped.publish',
                    false,
                );

                return [
                    'release_id' => $release->getId(),
                    'revision' => $workspace->getRevision(),
                    'parent_release_id' => $parent['release_id'],
                    'fingerprint' => (string)$release->getData(ThemeScopeRelease::schema_fields_FINGERPRINT),
                    'payload' => $effective,
                    'conflicts' => [],
                    'idempotent' => false,
                    'layout_entity_prepared' => $context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT,
                ];
            },
        );

        if (($result['blocked'] ?? false) === true) {
            return $result;
        }

        // Layout bake already ran inside the write intent (prepare-before-pointer).
        if ($context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT
            && empty($result['layout_entity_prepared'])
            && (int)($result['release_id'] ?? 0) > 0
            && \is_array($result['payload'] ?? null)
        ) {
            $this->bakeLayoutEntityAfterWrite($context, $result, $result['changes'] ?? []);
        }

        $result['descendants'] = $this->propagateToDescendants($context, $actorId, $actorName);

        $this->flushRequestLoadCache();

        return $result;
    }

    public function publishBatch(
        ThemeScopedReleaseBatch $batch,
        string $actorId,
        string $actorName = '',
        string $reason = '',
    ): array {
        $this->flushRequestLoadCache();
        $this->assertActor($actorId, $actorName);

        $result = $this->transactions->runWrite(
            $this->workspaces->getConnection(),
            function () use ($batch, $actorId, $actorName, $reason): array {
                $prepared = [];
                $changed = 0;
                foreach ($batch->items() as $item) {
                    $preparedItem = $this->prepareReleaseBatchItem($item, $actorId);
                    $prepared[] = $preparedItem;
                    if (($preparedItem['status'] ?? '') === 'pending') {
                        $changed++;
                    }
                }
                if ($changed === 0) {
                    throw new \RuntimeException('theme_scope_release_batch_no_changes');
                }

                $committedAt = \date('Y-m-d H:i:s');
                $batchRecord = $this->insertReleaseBatch(
                    $batch,
                    $actorId,
                    $actorName,
                    $reason,
                    $committedAt,
                );
                $resources = [];
                foreach ($prepared as $preparedItem) {
                    if (($preparedItem['status'] ?? '') === 'pending') {
                        $resourceReceipt = $this->applyPreparedReleaseBatchItem(
                            $preparedItem,
                            $actorId,
                            $actorName,
                            $reason,
                            $committedAt,
                        );
                        $resourceReceipt['descendants'] = $this->propagateFrozenDescendantsInTransaction(
                            $preparedItem['descendants'],
                            $actorId,
                            $actorName,
                            $committedAt,
                        );
                        $resources[] = $resourceReceipt;
                        continue;
                    }
                    $resources[] = $this->unchangedReleaseBatchReceipt(
                        $preparedItem,
                        $actorId,
                        $committedAt,
                    );
                }

                $receipt = [
                    'batch_id' => $batchRecord->getId(),
                    'batch_digest' => $batch->digest(),
                    'state' => ThemeScopeReleaseBatch::STATE_PUBLISHED,
                    'scope' => $batch->baseContext()->scope->storageScope,
                    'actor_id' => $actorId,
                    'committed_at' => $committedAt,
                    'resources' => $resources,
                ];
                $batchRecord->setData([
                    ThemeScopeReleaseBatch::schema_fields_RECEIPT_JSON => $this->json($receipt),
                    ThemeScopeReleaseBatch::schema_fields_STATE => ThemeScopeReleaseBatch::STATE_PUBLISHED,
                ])->save();
                $this->dispatchScopedPublishResourceChange(
                    $batch->baseContext(),
                    (int)$batchRecord->getId(),
                    'theme.scoped.release_batch_publish',
                    false,
                );

                return $receipt;
            },
        );
        $this->flushRequestLoadCache();
        // publishBatch 事务内只 projectPublished；布局实体化（r{releaseId}）必须在提交后完成，
        // 否则店面硬切找不到 bake 目录，仍显示旧预览/旧 Hook。
        $this->bakePublishedLayoutResourcesFromBatchReceipt($result);

        return $result;
    }

    /**
     * Materialize layout entity trees for every layout resource in a batch receipt.
     *
     * @param array<string,mixed> $result
     */
    private function bakePublishedLayoutResourcesFromBatchReceipt(array $result): void
    {
        /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator $coordinator */
        $coordinator = ObjectManager::getInstance(
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class,
        );
        foreach ((array)($result['resources'] ?? []) as $receipt) {
            if (!\is_array($receipt)) {
                continue;
            }
            if ((string)($receipt['resource_type'] ?? '') !== ThemeEditorContext::RESOURCE_LAYOUT) {
                continue;
            }
            $releaseId = (int)($receipt['release_id'] ?? $receipt['effective_release_id'] ?? 0);
            if ($releaseId <= 0) {
                continue;
            }
            $contextClaims = \is_array($receipt['context'] ?? null) ? $receipt['context'] : [];
            $themeId = (int)($contextClaims['theme_id'] ?? $result['theme_id'] ?? 0);
            $scopeArr = \is_array($contextClaims['scope'] ?? null) ? $contextClaims['scope'] : [];
            $scope = \trim((string)($scopeArr['storage_scope'] ?? $receipt['scope'] ?? ''));
            $layoutType = \trim((string)($contextClaims['layout_type'] ?? ''));
            $identityHash = \trim((string)($contextClaims['identity_hash'] ?? $receipt['identity_hash'] ?? ''));
            if ($themeId < 1 || $scope === '' || $layoutType === '' || $identityHash === '') {
                throw new \RuntimeException('theme_layout_entity_bake_identity_invalid:batch_receipt');
            }
            $payload = \is_array($receipt['payload'] ?? null) ? $receipt['payload'] : null;
            if ($payload === null) {
                $release = $this->loadRelease($releaseId);
                $payload = $release instanceof ThemeScopeRelease ? $release->payload() : null;
            }
            if (!\is_array($payload)) {
                throw new \RuntimeException(
                    'theme_layout_entity_bake_payload_missing:release:' . $releaseId,
                );
            }
            $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : $payload;
            $coordinator->afterLayoutWrite(
                $themeId,
                $scope,
                $layoutType,
                $identityHash,
                $nodes,
                [],
                true,
                $releaseId,
                (int)($receipt['revision_id'] ?? 0),
                (string)($contextClaims['layout_option'] ?? 'default'),
                (string)($contextClaims['area'] ?? 'frontend'),
                ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutVersionService::class)
                    ->getPublishedVersion($themeId, $layoutType, [
                        'scope' => $scope,
                        'layout_option' => $contextClaims['layout_option'] ?? 'default',
                        'target_type' => $contextClaims['target_type'] ?? 'global',
                        'target_id' => (int)($contextClaims['target_id'] ?? 0),
                    ])?->getVersionId() ?? 0,
            );
        }
    }

    public function rollbackReleaseBatch(
        int $sourceBatchId,
        ThemeEditorContext $context,
        string $actorId,
        string $actorName = '',
        string $reason = '',
    ): array {
        if ($sourceBatchId <= 0) {
            throw new \InvalidArgumentException('theme_scope_release_batch_id_invalid');
        }
        $this->flushRequestLoadCache();
        $this->assertActor($actorId, $actorName);

        $result = $this->transactions->runWrite(
            $this->workspaces->getConnection(),
            function () use ($sourceBatchId, $context, $actorId, $actorName, $reason): array {
                $source = $this->loadReleaseBatch($sourceBatchId, true);
                if (!$source instanceof ThemeScopeReleaseBatch) {
                    throw new \RuntimeException('theme_scope_release_batch_missing');
                }
                if (!\in_array((string)$source->getData(ThemeScopeReleaseBatch::schema_fields_STATE), [
                    ThemeScopeReleaseBatch::STATE_PUBLISHED,
                    ThemeScopeReleaseBatch::STATE_PUBLISHED_CACHE_DEGRADED,
                ], true)) {
                    throw new \RuntimeException('theme_scope_release_batch_not_committed');
                }
                $this->assertReleaseBatchContext($source, $context);
                $sourceReceipt = $source->receipt();
                $sourceResources = [];
                foreach ((array)($sourceReceipt['resources'] ?? []) as $resourceReceipt) {
                    if (!\is_array($resourceReceipt)) {
                        continue;
                    }
                    $resourceType = (string)($resourceReceipt['resource_type'] ?? '');
                    if (\in_array($resourceType, ThemeEditorContext::RESOURCES, true)) {
                        $sourceResources[$resourceType] = $resourceReceipt;
                    }
                }
                if (\array_keys($sourceResources) !== ThemeEditorContext::RESOURCES) {
                    throw new \RuntimeException('theme_scope_release_batch_receipt_incomplete');
                }

                $prepared = [];
                foreach (ThemeEditorContext::RESOURCES as $resourceType) {
                    $prepared[] = $this->prepareReleaseBatchRollbackItem(
                        $context->withResource($resourceType),
                        $sourceResources[$resourceType],
                        $actorId,
                    );
                }

                $committedAt = \date('Y-m-d H:i:s');
                $digest = \hash('sha256', $this->json([
                    'source_batch_id' => $sourceBatchId,
                    'source_batch_digest' => (string)$source->getData(
                        ThemeScopeReleaseBatch::schema_fields_BATCH_DIGEST,
                    ),
                    'resources' => \array_map(static fn(array $item): array => [
                        'resource_type' => $item['context']->resourceType,
                        'release_id' => $item['target_release_id'],
                        'effective_release_id' => $item['target_effective_release_id'],
                    ], $prepared),
                ]));
                $batchRecord = $this->insertRollbackReleaseBatch(
                    $context,
                    $digest,
                    $sourceBatchId,
                    $actorId,
                    $actorName,
                    $reason,
                    $committedAt,
                );

                $resources = [];
                foreach ($prepared as $preparedItem) {
                    $resourceReceipt = $this->applyPreparedReleaseBatchRollbackItem(
                        $preparedItem,
                        $sourceBatchId,
                        $actorId,
                        $committedAt,
                    );
                    $resourceReceipt['descendants'] = $this->rollbackFrozenDescendantsInTransaction(
                        $preparedItem['descendants'],
                        $preparedItem['source_descendants'],
                        $sourceBatchId,
                        $actorId,
                        $actorName,
                        $committedAt,
                    );
                    $resources[] = $resourceReceipt;
                }

                $receipt = [
                    'batch_id' => $batchRecord->getId(),
                    'source_batch_id' => $sourceBatchId,
                    'batch_digest' => $digest,
                    'state' => ThemeScopeReleaseBatch::STATE_PUBLISHED,
                    'scope' => $context->scope->storageScope,
                    'actor_id' => $actorId,
                    'committed_at' => $committedAt,
                    'resources' => $resources,
                ];
                $batchRecord->setData([
                    ThemeScopeReleaseBatch::schema_fields_RECEIPT_JSON => $this->json($receipt),
                    ThemeScopeReleaseBatch::schema_fields_STATE => ThemeScopeReleaseBatch::STATE_PUBLISHED,
                ])->save();

                // 回退也是发布指针变更；沿用发布事件，在同一事务覆盖全部资源及后代回退/传播。
                $this->dispatchScopedPublishResourceChange(
                    $context,
                    $batchRecord->getId(),
                    'theme_scoped_release_batch_rollback',
                    false,
                );

                return $receipt;
            },
        );
        $this->flushRequestLoadCache();

        return $result;
    }

    public function updateReleaseBatchCacheState(int $batchId, string $state, ?array $error = null): array
    {
        if ($batchId <= 0) {
            throw new \InvalidArgumentException('theme_scope_release_batch_id_invalid');
        }
        if (!\in_array($state, [
            ThemeScopeReleaseBatch::STATE_PUBLISHED,
            ThemeScopeReleaseBatch::STATE_PUBLISHED_CACHE_DEGRADED,
        ], true)) {
            throw new \InvalidArgumentException('theme_scope_release_batch_cache_state_invalid');
        }
        if ($state === ThemeScopeReleaseBatch::STATE_PUBLISHED_CACHE_DEGRADED && ($error ?? []) === []) {
            throw new \InvalidArgumentException('theme_scope_release_batch_cache_error_required');
        }

        return $this->transactions->runWrite(
            $this->releaseBatches->getConnection(),
            function () use ($batchId, $state, $error): array {
                $record = $this->loadReleaseBatch($batchId, true);
                if (!$record instanceof ThemeScopeReleaseBatch) {
                    throw new \RuntimeException('theme_scope_release_batch_missing');
                }
                $record->setData([
                    ThemeScopeReleaseBatch::schema_fields_STATE => $state,
                    ThemeScopeReleaseBatch::schema_fields_CACHE_UPDATED_AT => \date('Y-m-d H:i:s'),
                    ThemeScopeReleaseBatch::schema_fields_CACHE_ERROR_JSON => $error === null
                        ? null
                        : $this->json($error),
                ])->save();

                return $this->releaseBatchReadback($record);
            },
        );
    }

    public function getReleaseBatch(int $batchId): array
    {
        if ($batchId <= 0) {
            throw new \InvalidArgumentException('theme_scope_release_batch_id_invalid');
        }
        $record = $this->loadReleaseBatch($batchId);
        if (!$record instanceof ThemeScopeReleaseBatch) {
            throw new \RuntimeException('theme_scope_release_batch_missing');
        }

        return $this->releaseBatchReadback($record);
    }

    public function resolveValue(ThemeEditorContext $context, string $path, bool $includeDraft = true): ThemeResolvedValue
    {
        // Reuse command path validation without manufacturing a value operation.
        $probe = ThemePatchCommand::fromArray(['op' => ThemePatchCommand::OP_INHERIT, 'path' => $path]);
        $this->assertCommandResource($context, $probe);

        $workspace = $this->findWorkspace($context);
        if (!$includeDraft) {
            $state = $this->publishedState($context);
            $release = $state['release'];
            if ($release instanceof ThemeScopeRelease) {
                return $this->resolveFromReleaseChain($context, $release, $path, $workspace);
            }

            [$exists, $value] = $this->patchEngine->readPath($state['payload'], $path);

            return new ThemeResolvedValue(
                effectiveValue: $exists ? $value : null,
                localValue: null,
                hasLocalValue: false,
                sourceScope: 'theme-package-default',
                sourceReleaseId: null,
                isOwned: false,
                canRestoreInheritance: false,
                conflicts: $workspace?->conflicts() ?? [],
            );
        }

        $revisionId = $workspace instanceof ThemeScopeWorkspace
            ? (int)$workspace->getData(ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID)
            : 0;
        $local = $this->commandOwningPath($revisionId, $path);
        $state = $this->load($context, true);
        [$exists, $value] = $this->patchEngine->readPath($state['draft_payload'], $path);
        if ($local instanceof ThemePatchCommand) {
            return new ThemeResolvedValue(
                effectiveValue: $exists ? $value : null,
                localValue: $exists ? $value : null,
                hasLocalValue: $exists,
                sourceScope: $context->scope->storageScope,
                sourceReleaseId: null,
                isOwned: true,
                canRestoreInheritance: $this->canRestoreCommandAtPath($local, $path),
                conflicts: $workspace?->conflicts() ?? [],
            );
        }

        $parentIdentity = $this->scopes->parentIdentity($context->scope->identity);
        if ($parentIdentity instanceof ScopeIdentity) {
            $parent = $this->resolveValue(
                $context->withScope($this->scopes->contextFromIdentity($parentIdentity)),
                $path,
                false,
            );

            return new ThemeResolvedValue(
                effectiveValue: $exists ? $value : $parent->effectiveValue,
                localValue: null,
                hasLocalValue: false,
                sourceScope: $parent->sourceScope,
                sourceReleaseId: $parent->sourceReleaseId,
                isOwned: false,
                canRestoreInheritance: false,
                conflicts: $workspace?->conflicts() ?? [],
            );
        }

        return new ThemeResolvedValue(
            effectiveValue: $exists ? $value : null,
            localValue: null,
            hasLocalValue: false,
            sourceScope: 'theme-package-default',
            sourceReleaseId: null,
            isOwned: false,
            canRestoreInheritance: false,
            conflicts: [],
        );
    }

    public function resolvePublishedTheme(ScopeContext $scope, string $area): ?ThemeResolvedValue
    {
        $context = new ThemeEditorContext(
            scope: $scope,
            area: $area,
            resourceType: ThemeEditorContext::RESOURCE_THEME_BINDING,
        );
        $resolved = $this->resolveValue($context, '/theme_id', false);

        return \is_int($resolved->effectiveValue) || \is_numeric($resolved->effectiveValue)
            ? $resolved
            : null;
    }

    /** @return array{payload:array<string,mixed>,release_id:?int,source_scope:string,release:?ThemeScopeRelease} */
    private function publishedState(ThemeEditorContext $context, bool $allowRequestCache = true): array
    {
        $workspace = $this->findWorkspace($context, false, $allowRequestCache);
        if ($workspace instanceof ThemeScopeWorkspace) {
            $release = $this->loadRelease((int)$workspace->getData(
                ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID,
            ));
            if ($release instanceof ThemeScopeRelease) {
                // LAYOUT: sparse near-scope Releases must not wipe far-scope slots
                // (e.g. Website chrome publish dropping Global homepage-hero).
                // Compose parent_release_id chain with slot-near-priority.
                $payload = $context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT
                    ? $this->composeLayoutPayloadBySlotNearPriority($release)
                    : $release->payload();

                return [
                    'payload' => $payload,
                    'release_id' => $release->getId(),
                    'source_scope' => (string)$release->getData(ThemeScopeRelease::schema_fields_SCOPE),
                    'release' => $release,
                ];
            }
        }

        // Language-specific layout/meta inherits the default-locale Release at the
        // same Scope before walking Scope parents. Storefront RequestContext locale
        // (e.g. zh_Hans_CN) must not blank published widgets when only locale=default
        // was seeded; i18n overlays still use the original context locale.
        if ($this->shouldInheritDefaultLocalePublished($context)) {
            $defaultLocale = $context->withLocale('default');
            $workspace = $this->findWorkspace($defaultLocale, false, $allowRequestCache);
            if ($workspace instanceof ThemeScopeWorkspace) {
                $release = $this->loadRelease((int)$workspace->getData(
                    ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID,
                ));
                if ($release instanceof ThemeScopeRelease) {
                    $payload = $context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT
                        ? $this->composeLayoutPayloadBySlotNearPriority($release)
                        : $release->payload();

                    return [
                        'payload' => $payload,
                        'release_id' => $release->getId(),
                        'source_scope' => (string)$release->getData(ThemeScopeRelease::schema_fields_SCOPE),
                        'release' => $release,
                    ];
                }
            }
        }

        $parentIdentity = $this->scopes->parentIdentity($context->scope->identity);
        if ($parentIdentity instanceof ScopeIdentity) {
            return $this->publishedState(
                $context->withScope($this->scopes->contextFromIdentity($parentIdentity)),
                $allowRequestCache,
            );
        }

        return [
            'payload' => $this->adapter->loadBase($context),
            'release_id' => null,
            'source_scope' => 'theme-package-default',
            'release' => null,
        ];
    }

    private function shouldInheritDefaultLocalePublished(ThemeEditorContext $context): bool
    {
        // LAYOUT/META identity is always default; I18N keeps its own locale and must not inherit.
        return false;
    }

    /** @return array{payload:array<string,mixed>,release_id:?int,source_scope:string,release:?ThemeScopeRelease} */
    private function parentPublishedState(ThemeEditorContext $context): array
    {
        $parentIdentity = $this->scopes->parentIdentity($context->scope->identity);
        if ($parentIdentity instanceof ScopeIdentity) {
            return $this->publishedState(
                $context->withScope($this->scopes->contextFromIdentity($parentIdentity)),
            );
        }

        return [
            'payload' => $this->adapter->loadBase($context),
            'release_id' => null,
            'source_scope' => 'theme-package-default',
            'release' => null,
        ];
    }

    /**
     * @param array{resource_type:string,context:ThemeEditorContext,expected_revision:int,expected_parent_release_id:?int} $item
     * @return array<string,mixed>
     */
    private function prepareReleaseBatchItem(array $item, string $actorId): array
    {
        $context = $item['context'] ?? null;
        if (!$context instanceof ThemeEditorContext) {
            throw new \InvalidArgumentException('theme_scope_release_batch_context_invalid');
        }
        $workspace = $this->findWorkspace($context, true);
        $actualRevision = $workspace?->getRevision() ?? 0;
        if ($actualRevision !== $item['expected_revision']) {
            throw new \RuntimeException('theme_scope_revision_conflict');
        }
        $parent = $this->parentPublishedState($context);
        $expectedParentReleaseId = $this->nullablePositiveInt($item['expected_parent_release_id']);
        if ($parent['release_id'] !== $expectedParentReleaseId) {
            throw new \RuntimeException('theme_scope_parent_release_conflict');
        }

        $descendants = $this->freezeDescendantWorkspaces($context);
        $published = $this->publishedState($context);
        if (!$workspace instanceof ThemeScopeWorkspace) {
            return [
                'status' => 'unchanged',
                'resource_type' => $context->resourceType,
                'context' => $context,
                'workspace' => null,
                'revision_id' => null,
                'parent' => $parent,
                'current_release' => null,
                'published' => $published,
                'descendants' => $descendants,
            ];
        }

        $revisionId = (int)$workspace->getData(ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID);
        $currentRelease = $this->loadRelease((int)$workspace->getData(
            ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID,
        ));
        if ($revisionId <= 0) {
            if ($actualRevision > 0 && !$currentRelease instanceof ThemeScopeRelease) {
                throw new \RuntimeException('theme_scope_draft_revision_missing');
            }
            return [
                'status' => 'unchanged',
                'resource_type' => $context->resourceType,
                'context' => $context,
                'workspace' => $workspace,
                'revision_id' => null,
                'parent' => $parent,
                'current_release' => $currentRelease,
                'published' => $published,
                'descendants' => $descendants,
            ];
        }
        if ($currentRelease instanceof ThemeScopeRelease
            && (int)$currentRelease->getData(ThemeScopeRelease::schema_fields_REVISION_ID) === $revisionId
            && $this->nullablePositiveInt($currentRelease->getData(
                ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID,
            )) === $parent['release_id']
        ) {
            return [
                'status' => 'unchanged',
                'resource_type' => $context->resourceType,
                'context' => $context,
                'workspace' => $workspace,
                'revision_id' => $revisionId,
                'parent' => $parent,
                'current_release' => $currentRelease,
                'published' => $published,
                'descendants' => $descendants,
            ];
        }

        $commands = $this->commandsForRevision($revisionId);
        $oldParent = $this->payloadForReleaseOrRootBase(
            $this->nullablePositiveInt($workspace->getData(
                ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID,
            )),
            $context,
        );
        $conflicts = $this->patchEngine->structuralConflicts($oldParent, $parent['payload'], $commands);
        if ($conflicts !== []) {
            throw new \RuntimeException('theme_scope_structural_conflict');
        }

        $effective = $this->patchEngine->apply($parent['payload'], $commands);
        $compiled = $this->adapter->compile($context, $effective);
        $effective = \is_array($compiled['payload'] ?? null) ? $compiled['payload'] : $effective;
        $artifact = \is_array($compiled['artifact'] ?? null) ? $compiled['artifact'] : [];
        $this->validateLayoutPublication($context, $effective, $actorId);
        $payloadJson = $this->json($effective);

        return [
            'status' => 'pending',
            'resource_type' => $context->resourceType,
            'context' => $context,
            'workspace' => $workspace,
            'revision_id' => $revisionId,
            'parent' => $parent,
            'current_release' => $currentRelease,
            'published' => $published,
            'effective' => $effective,
            'artifact' => $artifact,
            'content_digest' => \hash('sha256', $payloadJson),
            'fingerprint' => (string)($artifact['fingerprint'] ?? \hash('sha256', $payloadJson)),
            'descendants' => $descendants,
        ];
    }

    /** @param array<string,mixed> $prepared @return array<string,mixed> */
    private function applyPreparedReleaseBatchItem(
        array $prepared,
        string $actorId,
        string $actorName,
        string $reason,
        string $committedAt,
    ): array {
        $workspace = $prepared['workspace'] ?? null;
        $context = $prepared['context'] ?? null;
        $revisionId = (int)($prepared['revision_id'] ?? 0);
        if (!$workspace instanceof ThemeScopeWorkspace
            || !$context instanceof ThemeEditorContext
            || $revisionId <= 0
        ) {
            throw new \RuntimeException('theme_scope_release_batch_prepared_item_invalid');
        }
        $parentReleaseId = $this->nullablePositiveInt($prepared['parent']['release_id'] ?? null);
        $effective = \is_array($prepared['effective'] ?? null) ? $prepared['effective'] : [];
        $artifact = \is_array($prepared['artifact'] ?? null) ? $prepared['artifact'] : [];
        $release = $this->insertRelease(
            $workspace,
            $context,
            $revisionId,
            $parentReleaseId,
            $effective,
            $artifact,
            $actorId,
            $actorName,
            $reason,
        );
        $this->adapter->projectPublished($context, $effective, $release->getId());
        $this->markRevisionPublished($revisionId);
        $workspace->setData([
            ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID => $release->getId(),
            ThemeScopeWorkspace::schema_fields_LAST_GOOD_RELEASE_ID => $release->getId(),
            ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID => $parentReleaseId,
            ThemeScopeWorkspace::schema_fields_STATUS => ThemeScopeWorkspace::STATUS_ACTIVE,
            ThemeScopeWorkspace::schema_fields_CONFLICT_JSON => null,
        ])->save();

        return [
            'resource_type' => $context->resourceType,
            'status' => 'published',
            'workspace_id' => $workspace->getId(),
            'identity_hash' => $context->identityHash(),
            'context' => $context->toArray(),
            'release_id' => $release->getId(),
            'effective_release_id' => $release->getId(),
            'revision_id' => $revisionId,
            'revision' => $workspace->getRevision(),
            'parent_release_id' => $parentReleaseId,
            'fingerprint' => (string)$release->getData(ThemeScopeRelease::schema_fields_FINGERPRINT),
            'content_digest' => (string)($prepared['content_digest'] ?? ''),
            'payload' => $effective,
            'scope' => $context->scope->storageScope,
            'actor_id' => $actorId,
            'committed_at' => $committedAt,
        ];
    }

    /** @param array<string,mixed> $prepared @return array<string,mixed> */
    private function unchangedReleaseBatchReceipt(
        array $prepared,
        string $actorId,
        string $committedAt,
    ): array {
        $context = $prepared['context'] ?? null;
        if (!$context instanceof ThemeEditorContext) {
            throw new \RuntimeException('theme_scope_release_batch_prepared_item_invalid');
        }
        $currentRelease = $prepared['current_release'] ?? null;
        $effectiveRelease = $prepared['published']['release'] ?? null;
        $payload = \is_array($prepared['published']['payload'] ?? null)
            ? $prepared['published']['payload']
            : [];
        $payloadJson = $this->json($payload);
        $receiptRelease = $currentRelease instanceof ThemeScopeRelease ? $currentRelease : null;
        $fingerprintRelease = $receiptRelease instanceof ThemeScopeRelease
            ? $receiptRelease
            : ($effectiveRelease instanceof ThemeScopeRelease ? $effectiveRelease : null);

        return [
            'resource_type' => $context->resourceType,
            'status' => 'unchanged',
            'workspace_id' => $prepared['workspace'] instanceof ThemeScopeWorkspace
                ? $prepared['workspace']->getId()
                : null,
            'identity_hash' => $context->identityHash(),
            'context' => $context->toArray(),
            'release_id' => $receiptRelease?->getId(),
            'effective_release_id' => $fingerprintRelease?->getId(),
            'revision_id' => $receiptRelease instanceof ThemeScopeRelease
                ? $this->nullablePositiveInt($receiptRelease->getData(ThemeScopeRelease::schema_fields_REVISION_ID))
                : null,
            'revision' => $prepared['workspace'] instanceof ThemeScopeWorkspace
                ? $prepared['workspace']->getRevision()
                : 0,
            'parent_release_id' => $receiptRelease instanceof ThemeScopeRelease
                ? $this->nullablePositiveInt($receiptRelease->getData(
                    ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID,
                ))
                : $this->nullablePositiveInt($prepared['parent']['release_id'] ?? null),
            'fingerprint' => $fingerprintRelease instanceof ThemeScopeRelease
                ? (string)$fingerprintRelease->getData(ThemeScopeRelease::schema_fields_FINGERPRINT)
                : \hash('sha256', $payloadJson),
            'content_digest' => \hash('sha256', $payloadJson),
            'scope' => $context->scope->storageScope,
            'actor_id' => $actorId,
            'committed_at' => $committedAt,
            'descendants' => $this->frozenDescendantReceiptSnapshots(
                \is_array($prepared['descendants'] ?? null) ? $prepared['descendants'] : [],
                $committedAt,
            ),
        ];
    }

    /** @param list<array<string,mixed>> $frozen @return list<array<string,mixed>> */
    private function frozenDescendantReceiptSnapshots(array $frozen, string $committedAt): array
    {
        $receipts = [];
        foreach ($frozen as $snapshot) {
            $context = $snapshot['context'] ?? null;
            if (!$context instanceof ThemeEditorContext) {
                continue;
            }
            $receipts[] = [
                'status' => 'unchanged',
                'workspace_id' => (int)($snapshot['workspace_id'] ?? 0),
                'identity_hash' => $context->identityHash(),
                'context' => $context->toArray(),
                'scope' => $context->scope->storageScope,
                'release_id' => $snapshot['published_release_id'] ?? null,
                'revision_id' => $snapshot['release_revision_id'] ?? null,
                'parent_release_id' => $snapshot['release_parent_id'] ?? null,
                'fingerprint' => $snapshot['release_fingerprint'] ?? '',
                'content_digest' => $snapshot['release_content_digest'] ?? '',
                'actor_id' => $snapshot['release_actor_id'] ?? null,
                'committed_at' => $committedAt,
            ];
        }

        return $receipts;
    }

    private function insertReleaseBatch(
        ThemeScopedReleaseBatch $batch,
        string $actorId,
        string $actorName,
        string $reason,
        string $committedAt,
    ): ThemeScopeReleaseBatch {
        $context = $batch->baseContext();
        $record = clone $this->releaseBatches;
        $record->clearData()->clearQuery()->setData([
            ThemeScopeReleaseBatch::schema_fields_BATCH_DIGEST => $batch->digest(),
            ThemeScopeReleaseBatch::schema_fields_SCOPE => $context->scope->storageScope,
            ThemeScopeReleaseBatch::schema_fields_STORE_MODE => $context->scope->storeMode,
            ThemeScopeReleaseBatch::schema_fields_AREA => $context->area,
            ThemeScopeReleaseBatch::schema_fields_THEME_ID => $context->themeId,
            ThemeScopeReleaseBatch::schema_fields_LAYOUT_TYPE => $context->layoutType,
            ThemeScopeReleaseBatch::schema_fields_LAYOUT_OPTION => $context->layoutOption,
            ThemeScopeReleaseBatch::schema_fields_LOCALE => $context->identityLocale(),
            ThemeScopeReleaseBatch::schema_fields_TARGET_TYPE => $context->targetType,
            ThemeScopeReleaseBatch::schema_fields_TARGET_ID => $context->targetId,
            ThemeScopeReleaseBatch::schema_fields_STATE => ThemeScopeReleaseBatch::STATE_PREPARING,
            ThemeScopeReleaseBatch::schema_fields_RECEIPT_JSON => null,
            ThemeScopeReleaseBatch::schema_fields_SOURCE_BATCH_ID => null,
            ThemeScopeReleaseBatch::schema_fields_ACTOR_ID => $actorId,
            ThemeScopeReleaseBatch::schema_fields_ACTOR_NAME => $actorName !== '' ? $actorName : null,
            ThemeScopeReleaseBatch::schema_fields_REASON => $reason !== '' ? $reason : null,
            ThemeScopeReleaseBatch::schema_fields_COMMITTED_AT => $committedAt,
        ])->save();

        return $record;
    }

    private function assertReleaseBatchContext(
        ThemeScopeReleaseBatch $record,
        ThemeEditorContext $context,
    ): void {
        $matches = (string)$record->getData(ThemeScopeReleaseBatch::schema_fields_SCOPE)
                === $context->scope->storageScope
            && (string)$record->getData(ThemeScopeReleaseBatch::schema_fields_STORE_MODE)
                === $context->scope->storeMode
            && (string)$record->getData(ThemeScopeReleaseBatch::schema_fields_AREA) === $context->area
            && (int)$record->getData(ThemeScopeReleaseBatch::schema_fields_THEME_ID) === $context->themeId
            && (string)$record->getData(ThemeScopeReleaseBatch::schema_fields_LAYOUT_TYPE) === $context->layoutType
            && (string)$record->getData(ThemeScopeReleaseBatch::schema_fields_LAYOUT_OPTION) === $context->layoutOption
            && (string)$record->getData(ThemeScopeReleaseBatch::schema_fields_LOCALE) === $context->locale
            && (string)$record->getData(ThemeScopeReleaseBatch::schema_fields_TARGET_TYPE) === $context->targetType
            && (int)$record->getData(ThemeScopeReleaseBatch::schema_fields_TARGET_ID) === $context->targetId;
        if (!$matches) {
            throw new \RuntimeException('theme_scope_release_batch_context_mismatch');
        }
    }

    /** @param array<string,mixed> $sourceReceipt @return array<string,mixed> */
    private function prepareReleaseBatchRollbackItem(
        ThemeEditorContext $context,
        array $sourceReceipt,
        string $actorId,
    ): array {
        if ((string)($sourceReceipt['resource_type'] ?? '') !== $context->resourceType
            || (string)($sourceReceipt['identity_hash'] ?? '') !== $context->identityHash()
            || (string)($sourceReceipt['scope'] ?? '') !== $context->scope->storageScope
        ) {
            throw new \RuntimeException('theme_scope_release_batch_receipt_context_mismatch');
        }
        $workspace = $this->findWorkspace($context, true);
        $targetReleaseId = $this->nullablePositiveInt($sourceReceipt['release_id'] ?? null);
        $targetRelease = $targetReleaseId !== null ? $this->loadRelease($targetReleaseId) : null;
        if ($targetReleaseId !== null) {
            if (!$targetRelease instanceof ThemeScopeRelease
                || (string)$targetRelease->getData(ThemeScopeRelease::schema_fields_IDENTITY_HASH)
                    !== $context->identityHash()
                || (string)$targetRelease->getData(ThemeScopeRelease::schema_fields_RESOURCE_TYPE)
                    !== $context->resourceType
                || !$workspace instanceof ThemeScopeWorkspace
            ) {
                throw new \RuntimeException('theme_scope_release_batch_historical_release_invalid');
            }
            $expectedFingerprint = (string)($sourceReceipt['fingerprint'] ?? '');
            if ($expectedFingerprint !== ''
                && (string)$targetRelease->getData(ThemeScopeRelease::schema_fields_FINGERPRINT)
                    !== $expectedFingerprint
            ) {
                throw new \RuntimeException('theme_scope_release_batch_historical_release_changed');
            }
        }
        $targetEffectiveReleaseId = $this->nullablePositiveInt(
            $sourceReceipt['effective_release_id'] ?? $targetReleaseId,
        );
        $targetEffectiveRelease = $targetEffectiveReleaseId !== null
            ? $this->loadRelease($targetEffectiveReleaseId)
            : null;
        if ($targetEffectiveReleaseId !== null
            && (!$targetEffectiveRelease instanceof ThemeScopeRelease
                || (string)$targetEffectiveRelease->getData(ThemeScopeRelease::schema_fields_RESOURCE_TYPE)
                    !== $context->resourceType)
        ) {
            throw new \RuntimeException('theme_scope_release_batch_effective_release_invalid');
        }
        $effective = $targetRelease instanceof ThemeScopeRelease
            ? $targetRelease->payload()
            : ($targetEffectiveRelease instanceof ThemeScopeRelease
                ? $targetEffectiveRelease->payload()
                : $this->adapter->loadBase($context));
        $compiled = $this->adapter->compile($context, $effective);
        $effective = \is_array($compiled['payload'] ?? null) ? $compiled['payload'] : $effective;
        $this->validateLayoutPublication($context, $effective, $actorId);

        $descendants = $this->freezeDescendantWorkspaces($context);
        $currentDescendants = [];
        foreach ($descendants as $snapshot) {
            $descendantContext = $snapshot['context'] ?? null;
            if ($descendantContext instanceof ThemeEditorContext) {
                $currentDescendants[$descendantContext->identityHash()] = $snapshot;
            }
        }
        $sourceDescendants = [];
        foreach ((array)($sourceReceipt['descendants'] ?? []) as $descendantReceipt) {
            if (!\is_array($descendantReceipt)) {
                continue;
            }
            $identityHash = (string)($descendantReceipt['identity_hash'] ?? '');
            if ($identityHash === '' || !isset($currentDescendants[$identityHash])) {
                throw new \RuntimeException('theme_scope_release_batch_descendant_snapshot_missing');
            }
            $sourceDescendants[$identityHash] = $descendantReceipt;
        }

        return [
            'context' => $context,
            'workspace' => $workspace,
            'target_release_id' => $targetReleaseId,
            'target_release' => $targetRelease,
            'target_effective_release_id' => $targetEffectiveReleaseId,
            'effective' => $effective,
            'source_receipt' => $sourceReceipt,
            'descendants' => $descendants,
            'source_descendants' => $sourceDescendants,
        ];
    }

    /** @param array<string,mixed> $prepared @return array<string,mixed> */
    private function applyPreparedReleaseBatchRollbackItem(
        array $prepared,
        int $sourceBatchId,
        string $actorId,
        string $committedAt,
    ): array {
        $context = $prepared['context'] ?? null;
        if (!$context instanceof ThemeEditorContext) {
            throw new \RuntimeException('theme_scope_release_batch_rollback_item_invalid');
        }
        $workspace = $prepared['workspace'] ?? null;
        $targetRelease = $prepared['target_release'] ?? null;
        $targetReleaseId = $this->nullablePositiveInt($prepared['target_release_id'] ?? null);
        $targetEffectiveReleaseId = $this->nullablePositiveInt(
            $prepared['target_effective_release_id'] ?? null,
        );
        if ($workspace instanceof ThemeScopeWorkspace) {
            $workspace->setData([
                ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID => $targetReleaseId,
                ThemeScopeWorkspace::schema_fields_LAST_GOOD_RELEASE_ID => $targetReleaseId,
            ])->save();
        } elseif ($targetReleaseId !== null) {
            throw new \RuntimeException('theme_scope_release_batch_rollback_workspace_missing');
        }
        $effective = \is_array($prepared['effective'] ?? null) ? $prepared['effective'] : [];
        $this->adapter->projectPublished(
            $context,
            $effective,
            $targetReleaseId ?? $targetEffectiveReleaseId ?? 0,
        );
        $payloadJson = $this->json($effective);

        return [
            'resource_type' => $context->resourceType,
            'status' => 'rolled_back',
            'source_batch_id' => $sourceBatchId,
            'source_release_id' => $targetReleaseId,
            'workspace_id' => $workspace instanceof ThemeScopeWorkspace ? $workspace->getId() : null,
            'identity_hash' => $context->identityHash(),
            'context' => $context->toArray(),
            'release_id' => $targetReleaseId,
            'effective_release_id' => $targetEffectiveReleaseId,
            'revision_id' => $targetRelease instanceof ThemeScopeRelease
                ? $this->nullablePositiveInt($targetRelease->getData(
                    ThemeScopeRelease::schema_fields_REVISION_ID,
                ))
                : null,
            'parent_release_id' => $targetRelease instanceof ThemeScopeRelease
                ? $this->nullablePositiveInt($targetRelease->getData(
                    ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID,
                ))
                : null,
            'fingerprint' => $targetRelease instanceof ThemeScopeRelease
                ? (string)$targetRelease->getData(ThemeScopeRelease::schema_fields_FINGERPRINT)
                : \hash('sha256', $payloadJson),
            'content_digest' => \hash('sha256', $payloadJson),
            'scope' => $context->scope->storageScope,
            'actor_id' => $actorId,
            'committed_at' => $committedAt,
        ];
    }

    private function insertRollbackReleaseBatch(
        ThemeEditorContext $context,
        string $digest,
        int $sourceBatchId,
        string $actorId,
        string $actorName,
        string $reason,
        string $committedAt,
    ): ThemeScopeReleaseBatch {
        $record = clone $this->releaseBatches;
        $record->clearData()->clearQuery()->setData([
            ThemeScopeReleaseBatch::schema_fields_BATCH_DIGEST => $digest,
            ThemeScopeReleaseBatch::schema_fields_SCOPE => $context->scope->storageScope,
            ThemeScopeReleaseBatch::schema_fields_STORE_MODE => $context->scope->storeMode,
            ThemeScopeReleaseBatch::schema_fields_AREA => $context->area,
            ThemeScopeReleaseBatch::schema_fields_THEME_ID => $context->themeId,
            ThemeScopeReleaseBatch::schema_fields_LAYOUT_TYPE => $context->layoutType,
            ThemeScopeReleaseBatch::schema_fields_LAYOUT_OPTION => $context->layoutOption,
            ThemeScopeReleaseBatch::schema_fields_LOCALE => $context->identityLocale(),
            ThemeScopeReleaseBatch::schema_fields_TARGET_TYPE => $context->targetType,
            ThemeScopeReleaseBatch::schema_fields_TARGET_ID => $context->targetId,
            ThemeScopeReleaseBatch::schema_fields_STATE => ThemeScopeReleaseBatch::STATE_PREPARING,
            ThemeScopeReleaseBatch::schema_fields_RECEIPT_JSON => null,
            ThemeScopeReleaseBatch::schema_fields_SOURCE_BATCH_ID => $sourceBatchId,
            ThemeScopeReleaseBatch::schema_fields_ACTOR_ID => $actorId,
            ThemeScopeReleaseBatch::schema_fields_ACTOR_NAME => $actorName !== '' ? $actorName : null,
            ThemeScopeReleaseBatch::schema_fields_REASON => $reason !== '' ? $reason : null,
            ThemeScopeReleaseBatch::schema_fields_COMMITTED_AT => $committedAt,
        ])->save();

        return $record;
    }

    /**
     * @param list<array<string,mixed>> $frozen
     * @param array<string,array<string,mixed>> $sourceDescendants
     * @return list<array<string,mixed>>
     */
    private function rollbackFrozenDescendantsInTransaction(
        array $frozen,
        array $sourceDescendants,
        int $sourceBatchId,
        string $actorId,
        string $actorName,
        string $committedAt,
    ): array {
        $receipts = [];
        foreach ($frozen as $snapshot) {
            $context = $snapshot['context'] ?? null;
            if (!$context instanceof ThemeEditorContext) {
                throw new \RuntimeException('theme_scope_descendant_snapshot_invalid');
            }
            $workspace = $this->findWorkspace($context, true);
            if (!$workspace instanceof ThemeScopeWorkspace
                || $workspace->getId() !== (int)($snapshot['workspace_id'] ?? 0)
                || $workspace->getRevision() !== (int)($snapshot['revision'] ?? -1)
                || $this->nullablePositiveInt($workspace->getData(
                    ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID,
                )) !== ($snapshot['draft_revision_id'] ?? null)
                || $this->nullablePositiveInt($workspace->getData(
                    ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID,
                )) !== ($snapshot['published_release_id'] ?? null)
            ) {
                throw new \RuntimeException('theme_scope_descendant_snapshot_conflict');
            }
            $historical = $sourceDescendants[$context->identityHash()] ?? null;
            if (!\is_array($historical)) {
                $outcome = $this->propagateOneInTransaction($context, $actorId, $actorName, true);
                if (($outcome['updated'] ?? false) !== true) {
                    continue;
                }
                $receipts[] = [
                    'status' => 'rollback_propagated',
                    'source_batch_id' => $sourceBatchId,
                    'source_release_id' => null,
                    'workspace_id' => $workspace->getId(),
                    'identity_hash' => $context->identityHash(),
                    'context' => $context->toArray(),
                    'scope' => $context->scope->storageScope,
                    'release_id' => $outcome['release_id'] ?? null,
                    'revision_id' => $outcome['revision_id'] ?? null,
                    'parent_release_id' => $outcome['parent_release_id'] ?? null,
                    'fingerprint' => $outcome['fingerprint'] ?? '',
                    'actor_id' => 'system:parent-propagation:' . $actorId,
                    'committed_at' => $committedAt,
                ];
                continue;
            }

            $targetReleaseId = $this->nullablePositiveInt($historical['release_id'] ?? null);
            $targetRelease = $targetReleaseId !== null ? $this->loadRelease($targetReleaseId) : null;
            if ($targetReleaseId !== null
                && (!$targetRelease instanceof ThemeScopeRelease
                    || (string)$targetRelease->getData(ThemeScopeRelease::schema_fields_IDENTITY_HASH)
                        !== $context->identityHash()
                    || (string)$targetRelease->getData(ThemeScopeRelease::schema_fields_RESOURCE_TYPE)
                        !== $context->resourceType)
            ) {
                throw new \RuntimeException('theme_scope_release_batch_descendant_release_invalid');
            }
            $effective = $targetRelease instanceof ThemeScopeRelease
                ? $targetRelease->payload()
                : $this->parentPublishedState($context)['payload'];
            $compiled = $this->adapter->compile($context, $effective);
            $effective = \is_array($compiled['payload'] ?? null) ? $compiled['payload'] : $effective;
            $this->validateLayoutPublication($context, $effective, $actorId);
            $workspace->setData([
                ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID => $targetReleaseId,
                ThemeScopeWorkspace::schema_fields_LAST_GOOD_RELEASE_ID => $targetReleaseId,
            ])->save();
            $this->adapter->projectPublished($context, $effective, $targetReleaseId ?? 0);
            $payloadJson = $this->json($effective);
            $receipts[] = [
                'status' => 'rolled_back',
                'source_batch_id' => $sourceBatchId,
                'source_release_id' => $targetReleaseId,
                'workspace_id' => $workspace->getId(),
                'identity_hash' => $context->identityHash(),
                'context' => $context->toArray(),
                'scope' => $context->scope->storageScope,
                'release_id' => $targetReleaseId,
                'revision_id' => $targetRelease instanceof ThemeScopeRelease
                    ? $this->nullablePositiveInt($targetRelease->getData(
                        ThemeScopeRelease::schema_fields_REVISION_ID,
                    ))
                    : null,
                'parent_release_id' => $targetRelease instanceof ThemeScopeRelease
                    ? $this->nullablePositiveInt($targetRelease->getData(
                        ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID,
                    ))
                    : null,
                'fingerprint' => $targetRelease instanceof ThemeScopeRelease
                    ? (string)$targetRelease->getData(ThemeScopeRelease::schema_fields_FINGERPRINT)
                    : \hash('sha256', $payloadJson),
                'content_digest' => \hash('sha256', $payloadJson),
                'actor_id' => $actorId,
                'committed_at' => $committedAt,
            ];
        }

        return $receipts;
    }

    private function loadReleaseBatch(int $batchId, bool $lockingRead = false): ?ThemeScopeReleaseBatch
    {
        $record = clone $this->releaseBatches;
        $record->clearData()->clearQuery()
            ->where(ThemeScopeReleaseBatch::schema_fields_ID, $batchId);
        if ($lockingRead && $this->supportsForUpdate()) {
            $record->additional('FOR UPDATE');
        }
        $record->find()->fetch();

        return $record->getId() > 0 ? $record : null;
    }

    /** @return array<string,mixed> */
    private function releaseBatchReadback(ThemeScopeReleaseBatch $record): array
    {
        $receipt = $record->receipt();
        $cacheError = $record->getData(ThemeScopeReleaseBatch::schema_fields_CACHE_ERROR_JSON);
        if (\is_string($cacheError) && $cacheError !== '') {
            $cacheError = \json_decode($cacheError, true);
        }
        $receipt['batch_id'] = $record->getId();
        $receipt['source_batch_id'] = $this->nullablePositiveInt($record->getData(
            ThemeScopeReleaseBatch::schema_fields_SOURCE_BATCH_ID,
        ));
        $receipt['scope'] = (string)$record->getData(ThemeScopeReleaseBatch::schema_fields_SCOPE);
        $receipt['store_mode'] = (string)$record->getData(
            ThemeScopeReleaseBatch::schema_fields_STORE_MODE,
        );
        $receipt['area'] = (string)$record->getData(ThemeScopeReleaseBatch::schema_fields_AREA);
        $receipt['theme_id'] = (int)$record->getData(ThemeScopeReleaseBatch::schema_fields_THEME_ID);
        $receipt['layout_type'] = (string)$record->getData(
            ThemeScopeReleaseBatch::schema_fields_LAYOUT_TYPE,
        );
        $receipt['layout_option'] = (string)$record->getData(
            ThemeScopeReleaseBatch::schema_fields_LAYOUT_OPTION,
        );
        $receipt['locale'] = (string)$record->getData(ThemeScopeReleaseBatch::schema_fields_LOCALE);
        $receipt['target_type'] = (string)$record->getData(
            ThemeScopeReleaseBatch::schema_fields_TARGET_TYPE,
        );
        $receipt['target_id'] = (int)$record->getData(ThemeScopeReleaseBatch::schema_fields_TARGET_ID);
        $receipt['state'] = (string)$record->getData(ThemeScopeReleaseBatch::schema_fields_STATE);
        $receipt['cache_updated_at'] = $record->getData(
            ThemeScopeReleaseBatch::schema_fields_CACHE_UPDATED_AT,
        );
        $receipt['cache_error'] = \is_array($cacheError) ? $cacheError : null;
        $receipt['cache_retryable'] = $receipt['state']
            === ThemeScopeReleaseBatch::STATE_PUBLISHED_CACHE_DEGRADED;

        return $receipt;
    }

    private function createWorkspace(ThemeEditorContext $context): ThemeScopeWorkspace
    {
        $workspace = clone $this->workspaces;
        $workspace->clearData()->clearQuery()->setData([
            ThemeScopeWorkspace::schema_fields_IDENTITY_HASH => $context->identityHash(),
            ThemeScopeWorkspace::schema_fields_SCOPE => $context->scope->storageScope,
            ThemeScopeWorkspace::schema_fields_SCOPE_KIND => $context->scope->identity->scopeKind,
            ThemeScopeWorkspace::schema_fields_WEBSITE_ID => $context->scope->identity->websiteId,
            ThemeScopeWorkspace::schema_fields_STORE_MODE => $context->scope->storeMode,
            ThemeScopeWorkspace::schema_fields_AREA => $context->area,
            ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE => $context->resourceType,
            ThemeScopeWorkspace::schema_fields_THEME_ID => $context->identityThemeId(),
            ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE => $context->identityLayoutType(),
            ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION => $context->identityLayoutOption(),
            ThemeScopeWorkspace::schema_fields_LOCALE => $context->identityLocale(),
            ThemeScopeWorkspace::schema_fields_TARGET_TYPE => $context->identityTargetType(),
            ThemeScopeWorkspace::schema_fields_TARGET_ID => $context->identityTargetId(),
            ThemeScopeWorkspace::schema_fields_REVISION => 0,
            ThemeScopeWorkspace::schema_fields_STATUS => ThemeScopeWorkspace::STATUS_ACTIVE,
        ])->save();

        return $workspace;
    }

    /**
     * Layout entity bake gate after successful write/publish (ObjectManager to avoid circular DI).
     *
     * @param array<string, mixed> $result
     * @param list<\Weline\Theme\Api\Scoped\ThemePatchCommand>|list<array<string,mixed>> $changes
     */
    private function bakeLayoutEntityAfterWrite(
        ThemeEditorContext $context,
        array $result,
        array $changes = [],
    ): void {
        if ($context->resourceType !== ThemeEditorContext::RESOURCE_LAYOUT) {
            return;
        }

        try {
            $themeId = $context->identityThemeId();
            $scope = $context->scope->storageScope;
            $payload = $result['draft_payload'] ?? $result['payload'] ?? null;
            if (!\is_array($payload)) {
                throw new \RuntimeException('theme_layout_entity_bake_payload_missing');
            }
            $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : $payload;
            $releaseId = $this->nullablePositiveInt($result['release_id'] ?? null);
            // 只要有 release_id 就按已发布物化到 r{id}。publish 结果常同时带 payload/draft_payload，
            // 不能因 draft_payload 键存在就当成草稿，否则店面永远找不到 r{releaseId}。
            $published = $releaseId !== null && $releaseId > 0;
            $draftRevisionId = (int)($result['revision_id'] ?? 0);
            $commands = $changes !== []
                ? $changes
                : (\is_array($result['changes'] ?? null) ? $result['changes'] : []);

            /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator $coordinator */
            $coordinator = ObjectManager::getInstance(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class,
            );
            $coordinator->afterLayoutWrite(
                $themeId,
                $scope,
                $context->identityLayoutType(),
                $context->identityHash(),
                $nodes,
                $commands,
                $published,
                $releaseId,
                $draftRevisionId,
                $context->layoutOption,
                $context->area,
                ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutVersionService::class)
                    ->getCurrentVersion($themeId, $context->identityLayoutType(), [
                        'scope' => $scope,
                        'layout_option' => $context->layoutOption,
                        'target_type' => $context->targetType,
                        'target_id' => $context->targetId,
                    ])?->getVersionId() ?? 0,
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'theme_layout_entity_bake_gate_failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function findWorkspace(ThemeEditorContext $context, bool $lockingRead = false, bool $allowRequestCache = true): ?ThemeScopeWorkspace
    {
        // A single render request resolves the same scoped workspace from
        // several observers (head/header/footer/slot). Reuse the immutable
        // read result, but never reuse it for a locking read: write paths must
        // still obtain a fresh row under FOR UPDATE after the request cache is
        // flushed.
        $requestCacheKey = self::REQUEST_WORKSPACE_CACHE_PREFIX . $context->identityHash();
        if (!$allowRequestCache && RequestContext::isInitialized()) {
            RequestContext::remove($requestCacheKey);
        }
        if ($allowRequestCache && !$lockingRead && RequestContext::isInitialized() && $context->identityHash() !== '') {
            if (RequestContext::has($requestCacheKey)) {
                $cached = RequestContext::get($requestCacheKey);
                return $cached instanceof ThemeScopeWorkspace ? clone $cached : null;
            }
        }

        $workspace = clone $this->workspaces;
        $workspace->clearData()->clearQuery()
            ->where(ThemeScopeWorkspace::schema_fields_IDENTITY_HASH, $context->identityHash());
        if ($lockingRead && $this->supportsForUpdate()) {
            $workspace->additional('FOR UPDATE');
        }
        $workspace->find()->fetch();

        $resolved = $workspace->getId() > 0 ? $workspace : null;
        if ($allowRequestCache && !$lockingRead && RequestContext::isInitialized() && $context->identityHash() !== '') {
            RequestContext::set($requestCacheKey, $resolved);
            $keys = RequestContext::get(self::REQUEST_WORKSPACE_CACHE_KEYS, []);
            if (!is_array($keys)) {
                $keys = [];
            }
            $keys[] = $requestCacheKey;
            RequestContext::set(self::REQUEST_WORKSPACE_CACHE_KEYS, array_values(array_unique($keys)));
        }

        return $resolved;
    }

    private function insertRevision(
        int $workspaceId,
        int $revisionNo,
        int $baseRevision,
        ?int $parentReleaseId,
        string $actorId,
        string $actorName,
        string $summary,
        array $conflicts,
    ): ThemeScopeRevision {
        $revision = clone $this->revisions;
        $revision->clearData()->clearQuery()->setData([
            ThemeScopeRevision::schema_fields_WORKSPACE_ID => $workspaceId,
            ThemeScopeRevision::schema_fields_REVISION_NO => $revisionNo,
            ThemeScopeRevision::schema_fields_BASE_REVISION => $baseRevision,
            ThemeScopeRevision::schema_fields_PARENT_RELEASE_ID => $parentReleaseId,
            ThemeScopeRevision::schema_fields_STATUS => $conflicts === []
                ? ThemeScopeRevision::STATUS_DRAFT
                : ThemeScopeRevision::STATUS_CONFLICT,
            ThemeScopeRevision::schema_fields_SUMMARY => $summary !== '' ? $summary : null,
            ThemeScopeRevision::schema_fields_ACTOR_ID => $actorId,
            ThemeScopeRevision::schema_fields_ACTOR_NAME => $actorName !== '' ? $actorName : null,
            ThemeScopeRevision::schema_fields_CONFLICT_JSON => $conflicts === [] ? null : $this->json($conflicts),
            ThemeScopeRevision::schema_fields_CREATE_TIME => \date('Y-m-d H:i:s'),
        ])->save();

        return $revision;
    }

    private function markRevisionPublished(int $revisionId): void
    {
        $revision = clone $this->revisions;
        $revision->clearData()->clearQuery()->load($revisionId);
        if ($revision->getId() !== $revisionId) {
            throw new \RuntimeException('theme_scope_revision_missing');
        }
        $revision->setData(
            ThemeScopeRevision::schema_fields_STATUS,
            ThemeScopeRevision::STATUS_PUBLISHED,
        )->save();
    }

    /** @param list<array<string,mixed>> $conflicts */
    private function markRevisionConflictState(int $revisionId, array $conflicts): void
    {
        $revision = clone $this->revisions;
        $revision->clearData()->clearQuery()->load($revisionId);
        if ($revision->getId() !== $revisionId) {
            throw new \RuntimeException('theme_scope_revision_missing');
        }
        if ((string)$revision->getData(ThemeScopeRevision::schema_fields_STATUS)
            === ThemeScopeRevision::STATUS_PUBLISHED
        ) {
            return;
        }
        $revision->setData([
            ThemeScopeRevision::schema_fields_STATUS => $conflicts === []
                ? ThemeScopeRevision::STATUS_DRAFT
                : ThemeScopeRevision::STATUS_CONFLICT,
            ThemeScopeRevision::schema_fields_CONFLICT_JSON => $conflicts === []
                ? null
                : $this->json($conflicts),
        ])->save();
    }

    /** @param list<ThemePatchCommand> $commands */
    private function insertPatches(int $workspaceId, int $revisionId, array $commands): void
    {
        foreach ($commands as $sequence => $command) {
            $patch = clone $this->patches;
            $patch->clearData()->clearQuery()->setData([
                ThemeScopePatch::schema_fields_REVISION_ID => $revisionId,
                ThemeScopePatch::schema_fields_WORKSPACE_ID => $workspaceId,
                ThemeScopePatch::schema_fields_OPERATION => $command->operation,
                ThemeScopePatch::schema_fields_PATH => $command->path,
                ThemeScopePatch::schema_fields_PATH_HASH => \hash('sha256', $command->path),
                ThemeScopePatch::schema_fields_NODE_UID => $command->nodeUid,
                ThemeScopePatch::schema_fields_ANCHOR_UID => $command->anchorUid,
                ThemeScopePatch::schema_fields_POSITION => $command->position,
                ThemeScopePatch::schema_fields_HAS_VALUE => $command->hasValue ? 1 : 0,
                ThemeScopePatch::schema_fields_VALUE_JSON => $command->hasValue
                    ? $this->json($command->value)
                    : null,
                ThemeScopePatch::schema_fields_SEQUENCE_NO => $sequence,
                ThemeScopePatch::schema_fields_CREATE_TIME => \date('Y-m-d H:i:s'),
            ])->save();
        }
    }

    private function insertRelease(
        ThemeScopeWorkspace $workspace,
        ThemeEditorContext $context,
        ?int $revisionId,
        ?int $parentReleaseId,
        array $payload,
        array $artifact,
        string $actorId,
        string $actorName,
        string $reason,
    ): ThemeScopeRelease {
        $payloadJson = $this->json($payload);
        $release = clone $this->releases;
        $release->clearData()->clearQuery()->setData([
            ThemeScopeRelease::schema_fields_WORKSPACE_ID => $workspace->getId(),
            ThemeScopeRelease::schema_fields_REVISION_ID => $revisionId,
            ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID => $parentReleaseId,
            ThemeScopeRelease::schema_fields_IDENTITY_HASH => $context->identityHash(),
            ThemeScopeRelease::schema_fields_SCOPE => $context->scope->storageScope,
            ThemeScopeRelease::schema_fields_STORE_MODE => $context->scope->storeMode,
            ThemeScopeRelease::schema_fields_AREA => $context->area,
            ThemeScopeRelease::schema_fields_RESOURCE_TYPE => $context->resourceType,
            ThemeScopeRelease::schema_fields_THEME_ID => isset($payload['theme_id'])
                ? (int)$payload['theme_id']
                : ($context->themeId > 0 ? $context->themeId : null),
            ThemeScopeRelease::schema_fields_EFFECTIVE_PAYLOAD_JSON => $payloadJson,
            ThemeScopeRelease::schema_fields_COMPILED_ARTIFACT_JSON => $artifact === [] ? null : $this->json($artifact),
            ThemeScopeRelease::schema_fields_FINGERPRINT => (string)($artifact['fingerprint'] ?? \hash('sha256', $payloadJson)),
            ThemeScopeRelease::schema_fields_STATUS => ThemeScopeRelease::STATUS_EFFECTIVE,
            ThemeScopeRelease::schema_fields_ACTOR_ID => $actorId,
            ThemeScopeRelease::schema_fields_ACTOR_NAME => $actorName !== '' ? $actorName : null,
            ThemeScopeRelease::schema_fields_REASON => $reason !== '' ? $reason : null,
            ThemeScopeRelease::schema_fields_PUBLISHED_AT => \date('Y-m-d H:i:s'),
        ])->save();

        return $release;
    }

    /** @return list<ThemePatchCommand> */
    private function commandsForRevision(int $revisionId): array
    {
        if ($revisionId <= 0) {
            return [];
        }
        $rows = (clone $this->patches)->clearData()->clearQuery()
            ->where(ThemeScopePatch::schema_fields_REVISION_ID, $revisionId)
            ->order(ThemeScopePatch::schema_fields_SEQUENCE_NO, 'ASC')
            ->order(ThemeScopePatch::schema_fields_ID, 'ASC')
            ->select()->fetchArray();
        $commands = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $data = [
                'op' => (string)($row[ThemeScopePatch::schema_fields_OPERATION] ?? ''),
                'path' => (string)($row[ThemeScopePatch::schema_fields_PATH] ?? ''),
                'node_uid' => $row[ThemeScopePatch::schema_fields_NODE_UID] ?? null,
                'anchor_uid' => $row[ThemeScopePatch::schema_fields_ANCHOR_UID] ?? null,
                'position' => $row[ThemeScopePatch::schema_fields_POSITION] ?? null,
            ];
            if ((int)($row[ThemeScopePatch::schema_fields_HAS_VALUE] ?? 0) === 1) {
                $json = (string)($row[ThemeScopePatch::schema_fields_VALUE_JSON] ?? 'null');
                $data['value'] = \json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            }
            $commands[] = ThemePatchCommand::fromArray($data);
        }

        return $commands;
    }

    private function loadRelease(int $releaseId): ?ThemeScopeRelease
    {
        if ($releaseId <= 0) {
            return null;
        }
        $release = clone $this->releases;
        $release->clearData()->clearQuery()->load($releaseId);

        return $release->getId() > 0 ? $release : null;
    }

    /**
     * Slot-near-priority compose across parent_release_id chain (leaf → root).
     *
     * Sparse Website/Store Releases that only publish chrome must not erase
     * Global homepage-* slots. First claimer of a slot_id (nearest Scope) wins
     * for every node in that slot; unclaimed slots keep flowing from ancestors.
     * A slot that only carries an explicit empty-placement marker still claims
     * ownership so parents cannot refill it.
     *
     * @return array<string,mixed>
     */
    private function composeLayoutPayloadBySlotNearPriority(ThemeScopeRelease $leaf): array
    {
        $chain = [];
        $cursor = $leaf;
        $visited = [];
        while ($cursor instanceof ThemeScopeRelease) {
            $releaseId = $cursor->getId();
            if ($releaseId <= 0 || isset($visited[$releaseId])) {
                break;
            }
            $visited[$releaseId] = true;
            $chain[] = $cursor;
            $parentId = $this->nullablePositiveInt(
                $cursor->getData(ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID),
            );
            $cursor = $parentId !== null ? $this->loadRelease($parentId) : null;
        }

        if ($chain === []) {
            return $leaf->payload();
        }

        $claimedSlots = [];
        $mergedNodes = [];
        $basePayload = $leaf->payload();
        $leafId = $leaf->getId();
        foreach ($chain as $release) {
            $payload = $release->payload();
            $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
            $slotsInRelease = [];
            foreach ($nodes as $uid => $node) {
                if (!\is_array($node)) {
                    continue;
                }
                $slotId = \trim((string)($node['slot_id'] ?? ''));
                if ($slotId === '') {
                    continue;
                }
                $slotsInRelease[$slotId] = true;
            }
            foreach ($slotsInRelease as $slotId => $_true) {
                if (isset($claimedSlots[$slotId])) {
                    continue;
                }
                $claimedSlots[$slotId] = true;
                foreach ($nodes as $uid => $node) {
                    if (!\is_array($node)) {
                        continue;
                    }
                    if (\trim((string)($node['slot_id'] ?? '')) !== $slotId) {
                        continue;
                    }
                    $mergedNodes[(string)$uid] = $node;
                }
            }
            if ($release->getId() === $leafId) {
                $basePayload = $payload;
            }
        }

        $basePayload['nodes'] = $mergedNodes;

        return $basePayload;
    }

    /** @return array<string,mixed> */
    private function payloadForReleaseOrRootBase(?int $releaseId, ThemeEditorContext $context): array
    {
        $release = $releaseId !== null ? $this->loadRelease($releaseId) : null;
        if ($release instanceof ThemeScopeRelease) {
            return $context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT
                ? $this->composeLayoutPayloadBySlotNearPriority($release)
                : $release->payload();
        }
        $root = $context;
        $cursor = $context->scope->identity;
        while (($parent = $this->scopes->parentIdentity($cursor)) instanceof ScopeIdentity) {
            $cursor = $parent;
            $root = $root->withScope($this->scopes->contextFromIdentity($cursor));
        }

        return $this->adapter->loadBase($root);
    }

    /** @return list<ThemeEditorContext> */
    private function descendantContexts(ThemeEditorContext $publishedContext, bool $lockingRead = false): array
    {
        $query = (clone $this->workspaces)->clearData()->clearQuery()
            ->where(ThemeScopeWorkspace::schema_fields_AREA, $publishedContext->area)
            ->where(ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE, $publishedContext->resourceType)
            ->where(ThemeScopeWorkspace::schema_fields_THEME_ID, $publishedContext->identityThemeId())
            ->where(ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE, $publishedContext->identityLayoutType())
            ->where(ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION, $publishedContext->identityLayoutOption())
            ->where(ThemeScopeWorkspace::schema_fields_LOCALE, $publishedContext->identityLocale())
            ->where(ThemeScopeWorkspace::schema_fields_TARGET_TYPE, $publishedContext->identityTargetType())
            ->where(ThemeScopeWorkspace::schema_fields_TARGET_ID, $publishedContext->identityTargetId());
        if ($lockingRead && $this->supportsForUpdate()) {
            $query->additional('FOR UPDATE');
        }
        $rows = $query->select()->fetchArray();
        $candidates = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $context = $this->contextFromWorkspaceRow($row);
            if (!$context instanceof ThemeEditorContext
                || !$this->isStrictDescendant($context->scope->identity, $publishedContext->scope->identity)
            ) {
                continue;
            }
            $candidates[] = $context;
        }
        // Rebase direct parents before their descendants: Website, then Store, then Channel.
        \usort($candidates, static fn(ThemeEditorContext $a, ThemeEditorContext $b): int =>
            \count($a->scope->fallbackStorageScopes) <=> \count($b->scope->fallbackStorageScopes));

        return $candidates;
    }

    /** @return list<array<string,mixed>> */
    private function freezeDescendantWorkspaces(ThemeEditorContext $publishedContext): array
    {
        $frozen = [];
        foreach ($this->descendantContexts($publishedContext, true) as $context) {
            $workspace = $this->findWorkspace($context, true);
            if (!$workspace instanceof ThemeScopeWorkspace) {
                continue;
            }
            $currentReleaseId = $this->nullablePositiveInt($workspace->getData(
                ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID,
            ));
            $currentRelease = $currentReleaseId !== null ? $this->loadRelease($currentReleaseId) : null;
            $frozen[] = [
                'context' => $context,
                'workspace_id' => $workspace->getId(),
                'revision' => $workspace->getRevision(),
                'draft_revision_id' => $this->nullablePositiveInt($workspace->getData(
                    ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID,
                )),
                'published_release_id' => $currentReleaseId,
                'parent_release_id' => $this->nullablePositiveInt($workspace->getData(
                    ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID,
                )),
                'release_parent_id' => $currentRelease instanceof ThemeScopeRelease
                    ? $this->nullablePositiveInt($currentRelease->getData(
                        ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID,
                    ))
                    : null,
                'release_revision_id' => $currentRelease instanceof ThemeScopeRelease
                    ? $this->nullablePositiveInt($currentRelease->getData(
                        ThemeScopeRelease::schema_fields_REVISION_ID,
                    ))
                    : null,
                'release_fingerprint' => $currentRelease instanceof ThemeScopeRelease
                    ? (string)$currentRelease->getData(ThemeScopeRelease::schema_fields_FINGERPRINT)
                    : null,
                'release_content_digest' => $currentRelease instanceof ThemeScopeRelease
                    ? \hash('sha256', $this->json($currentRelease->payload()))
                    : null,
                'release_actor_id' => $currentRelease instanceof ThemeScopeRelease
                    ? (string)$currentRelease->getData(ThemeScopeRelease::schema_fields_ACTOR_ID)
                    : null,
            ];
        }

        return $frozen;
    }

    /**
     * @param list<array<string,mixed>> $frozen
     * @return list<array<string,mixed>>
     */
    private function propagateFrozenDescendantsInTransaction(
        array $frozen,
        string $actorId,
        string $actorName,
        string $committedAt,
    ): array {
        $receipts = [];
        foreach ($frozen as $snapshot) {
            $context = $snapshot['context'] ?? null;
            if (!$context instanceof ThemeEditorContext) {
                throw new \RuntimeException('theme_scope_descendant_snapshot_invalid');
            }
            $workspace = $this->findWorkspace($context, true);
            if (!$workspace instanceof ThemeScopeWorkspace
                || $workspace->getId() !== (int)($snapshot['workspace_id'] ?? 0)
                || $workspace->getRevision() !== (int)($snapshot['revision'] ?? -1)
                || $this->nullablePositiveInt($workspace->getData(
                    ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID,
                )) !== ($snapshot['draft_revision_id'] ?? null)
                || $this->nullablePositiveInt($workspace->getData(
                    ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID,
                )) !== ($snapshot['published_release_id'] ?? null)
                || $this->nullablePositiveInt($workspace->getData(
                    ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID,
                )) !== ($snapshot['parent_release_id'] ?? null)
            ) {
                throw new \RuntimeException('theme_scope_descendant_snapshot_conflict');
            }
            $outcome = $this->propagateOneInTransaction($context, $actorId, $actorName, true);
            if (($outcome['updated'] ?? false) !== true) {
                continue;
            }
            $receipts[] = [
                'status' => 'published',
                'workspace_id' => (int)($snapshot['workspace_id'] ?? 0),
                'identity_hash' => $context->identityHash(),
                'context' => $context->toArray(),
                'scope' => $context->scope->storageScope,
                'release_id' => $outcome['release_id'] ?? null,
                'revision_id' => $outcome['revision_id'] ?? null,
                'parent_release_id' => $outcome['parent_release_id'] ?? null,
                'fingerprint' => $outcome['fingerprint'] ?? '',
                'actor_id' => 'system:parent-propagation:' . $actorId,
                'committed_at' => $committedAt,
            ];
        }

        return $receipts;
    }

    /** @return array{updated:int,conflicted:int,conflicts:list<array<string,mixed>>,errors:list<array<string,string>>} */
    private function propagateToDescendants(ThemeEditorContext $publishedContext, string $actorId, string $actorName): array
    {
        $candidates = $this->descendantContexts($publishedContext);

        $updated = 0;
        $conflicted = 0;
        $conflictsOut = [];
        $errors = [];
        foreach ($candidates as $context) {
            try {
                $outcome = $this->propagateOne($context, $actorId, $actorName);
            } catch (\Throwable $e) {
                // The parent release is already committed. A broken descendant
                // must keep serving its last good release instead of turning the
                // parent publish request into a false failure.
                $errors[] = [
                    'scope' => $context->scope->storageScope,
                    'code' => 'theme_descendant_propagation_failed',
                    'message' => $e->getMessage(),
                ];
                continue;
            }
            if ($outcome['conflicts'] !== []) {
                $conflicted++;
                $conflictsOut[] = [
                    'scope' => $context->scope->storageScope,
                    'conflicts' => $outcome['conflicts'],
                ];
            } elseif ($outcome['updated']) {
                $updated++;
            }
        }

        return [
            'updated' => $updated,
            'conflicted' => $conflicted,
            'conflicts' => $conflictsOut,
            'errors' => $errors,
        ];
    }

    /** @return array{updated:bool,conflicts:list<array<string,mixed>>} */
    private function propagateOne(ThemeEditorContext $context, string $actorId, string $actorName): array
    {
        return $this->transactions->runWrite(
            $this->workspaces->getConnection(),
            fn(): array => $this->propagateOneInTransaction($context, $actorId, $actorName),
        );
    }

    /** @return array<string,mixed> */
    private function propagateOneInTransaction(
        ThemeEditorContext $context,
        string $actorId,
        string $actorName,
        bool $strict = false,
    ): array {
        $workspace = $this->findWorkspace($context, true);
        if (!$workspace instanceof ThemeScopeWorkspace) {
            return ['updated' => false, 'conflicts' => []];
        }
        $currentRelease = $this->loadRelease((int)$workspace->getData(
            ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID,
        ));
        $publishedRevisionId = $currentRelease
            ? (int)$currentRelease->getData(ThemeScopeRelease::schema_fields_REVISION_ID)
            : 0;
        $commands = $this->commandsForRevision($publishedRevisionId);
        $newParent = $this->parentPublishedState($context);
        $oldParentId = $currentRelease
            ? $this->nullablePositiveInt($currentRelease->getData(ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID))
            : null;
        $oldParent = $this->payloadForReleaseOrRootBase($oldParentId, $context);
        $conflicts = $this->patchEngine->structuralConflicts($oldParent, $newParent['payload'], $commands);
        if ($conflicts !== []) {
            if ($strict) {
                throw new \RuntimeException('theme_scope_descendant_structural_conflict');
            }
            $workspace->setData([
                ThemeScopeWorkspace::schema_fields_STATUS => ThemeScopeWorkspace::STATUS_CONFLICT,
                ThemeScopeWorkspace::schema_fields_CONFLICT_JSON => $this->json($conflicts),
            ])->save();

            return ['updated' => false, 'conflicts' => $conflicts];
        }
        $effective = $this->patchEngine->apply($newParent['payload'], $commands);
        $compiled = $this->adapter->compile($context, $effective);
        $effective = \is_array($compiled['payload'] ?? null) ? $compiled['payload'] : $effective;
        $artifact = \is_array($compiled['artifact'] ?? null) ? $compiled['artifact'] : [];
        $this->validateLayoutPublication($context, $effective, $actorId);
        $draftRevisionId = (int)$workspace->getData(ThemeScopeWorkspace::schema_fields_DRAFT_REVISION_ID);
        $draftConflicts = [];
        if ($draftRevisionId > 0 && $draftRevisionId !== $publishedRevisionId) {
            $draftOldParent = $this->payloadForReleaseOrRootBase(
                $this->nullablePositiveInt($workspace->getData(
                    ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID,
                )),
                $context,
            );
            $draftConflicts = $this->patchEngine->structuralConflicts(
                $draftOldParent,
                $newParent['payload'],
                $this->commandsForRevision($draftRevisionId),
            );
            if ($strict && $draftConflicts !== []) {
                throw new \RuntimeException('theme_scope_descendant_draft_conflict');
            }
            $this->markRevisionConflictState($draftRevisionId, $draftConflicts);
        }
        $release = $this->insertRelease(
            $workspace,
            $context,
            $publishedRevisionId > 0 ? $publishedRevisionId : null,
            $newParent['release_id'],
            $effective,
            $artifact,
            'system:parent-propagation:' . $actorId,
            $actorName,
            'parent_release_propagation',
        );
        $this->adapter->projectPublished($context, $effective, $release->getId());
        $updates = [
            ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID => $release->getId(),
            ThemeScopeWorkspace::schema_fields_LAST_GOOD_RELEASE_ID => $release->getId(),
            ThemeScopeWorkspace::schema_fields_STATUS => $draftConflicts === []
                ? ThemeScopeWorkspace::STATUS_ACTIVE
                : ThemeScopeWorkspace::STATUS_CONFLICT,
            ThemeScopeWorkspace::schema_fields_CONFLICT_JSON => $draftConflicts === []
                ? null
                : $this->json($draftConflicts),
        ];
        if ($draftConflicts === []) {
            $updates[ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID] = $newParent['release_id'];
        }
        $workspace->setData($updates)->save();

        return [
            'updated' => true,
            'conflicts' => $draftConflicts,
            'release_id' => $release->getId(),
            'revision_id' => $publishedRevisionId > 0 ? $publishedRevisionId : null,
            'parent_release_id' => $newParent['release_id'],
            'fingerprint' => (string)$release->getData(ThemeScopeRelease::schema_fields_FINGERPRINT),
        ];
    }

    /** @param array<string,mixed> $row */
    private function contextFromWorkspaceRow(array $row): ?ThemeEditorContext
    {
        $decoded = $this->scopes->fromStorageScope((string)($row[ThemeScopeWorkspace::schema_fields_SCOPE] ?? ''), false);
        if (!$decoded instanceof ScopeIdentity) {
            return null;
        }
        $websiteId = isset($row[ThemeScopeWorkspace::schema_fields_WEBSITE_ID])
            ? (int)$row[ThemeScopeWorkspace::schema_fields_WEBSITE_ID]
            : 0;
        $storeMode = (string)($row[ThemeScopeWorkspace::schema_fields_STORE_MODE] ?? ScopeIdentity::MODE_NORMAL);
        $identity = match ($decoded->scopeKind) {
            ScopeIdentity::KIND_GLOBAL => ScopeIdentity::global(),
            ScopeIdentity::KIND_WEBSITE => ScopeIdentity::website($websiteId, (string)$decoded->websiteCode),
            ScopeIdentity::KIND_STORE => ScopeIdentity::store(
                $websiteId,
                (string)$decoded->websiteCode,
                (string)$decoded->storeCode,
                $storeMode,
            ),
            ScopeIdentity::KIND_CHANNEL => ScopeIdentity::channel(
                $websiteId,
                (string)$decoded->websiteCode,
                (string)$decoded->storeCode,
                (string)$decoded->channelCode,
                $storeMode,
            ),
            default => null,
        };
        if (!$identity instanceof ScopeIdentity) {
            return null;
        }

        return new ThemeEditorContext(
            scope: $this->scopes->contextFromIdentity($identity),
            area: (string)$row[ThemeScopeWorkspace::schema_fields_AREA],
            resourceType: (string)$row[ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE],
            themeId: (int)($row[ThemeScopeWorkspace::schema_fields_THEME_ID] ?? 0),
            layoutType: (string)$row[ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE],
            layoutOption: (string)$row[ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION],
            locale: (string)$row[ThemeScopeWorkspace::schema_fields_LOCALE],
            targetType: (string)$row[ThemeScopeWorkspace::schema_fields_TARGET_TYPE],
            targetId: (int)$row[ThemeScopeWorkspace::schema_fields_TARGET_ID],
        );
    }

    /** @param list<mixed> $changes @return list<ThemePatchCommand> */
    private function assertCommands(ThemeEditorContext $context, array $changes): array
    {
        $commands = [];
        foreach ($changes as $change) {
            $command = $change instanceof ThemePatchCommand
                ? $change
                : (\is_array($change) ? ThemePatchCommand::fromArray($change) : null);
            if (!$command instanceof ThemePatchCommand) {
                throw new \InvalidArgumentException('theme_patch_command_type_invalid');
            }
            $this->assertCommandResource($context, $command);
            $commands[] = $command;
        }
        if ($commands === []) {
            throw new \InvalidArgumentException('theme_patch_changes_required');
        }

        return $commands;
    }

    private function assertCommandResource(ThemeEditorContext $context, ThemePatchCommand $command): void
    {
        $mapPath = '(?:/[a-zA-Z0-9_.:@~-]+)+';
        $nodePath = '#^/nodes/[a-f0-9]{32}(?:/[a-zA-Z0-9_.:@~-]+)*$#D';
        $nodeRoot = '#^/nodes/([a-f0-9]{32})$#D';
        $valid = match ($context->resourceType) {
            ThemeEditorContext::RESOURCE_THEME_BINDING => $command->path === '/theme_id',
            ThemeEditorContext::RESOURCE_LAYOUT => \preg_match($nodePath, $command->path) === 1
                || \preg_match('#^/selection' . $mapPath . '$#D', $command->path) === 1,
            ThemeEditorContext::RESOURCE_META => \preg_match('#^/values' . $mapPath . '$#D', $command->path) === 1,
            ThemeEditorContext::RESOURCE_APPEARANCE => \preg_match('#^/(?:tokens|disks|brand)' . $mapPath . '$#D', $command->path) === 1,
            ThemeEditorContext::RESOURCE_I18N => \preg_match('#^/translations' . $mapPath . '$#D', $command->path) === 1,
            default => false,
        };
        if (!$valid) {
            throw new \InvalidArgumentException('theme_patch_resource_path_mismatch');
        }
        if ($context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT
            && \preg_match('#^/nodes/[a-f0-9]{32}/node_uid$#D', $command->path) === 1
        ) {
            throw new \InvalidArgumentException('theme_patch_node_uid_immutable');
        }
        $pathNodeUid = null;
        if (\preg_match('#^/nodes/([a-f0-9]{32})(?:/|$)#D', $command->path, $pathNodeMatches) === 1) {
            $pathNodeUid = $pathNodeMatches[1] ?? null;
        }
        if ($command->nodeUid !== null && $command->nodeUid !== $pathNodeUid) {
            throw new \InvalidArgumentException('theme_patch_node_path_mismatch');
        }
        if ($context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT
            && \in_array($command->operation, [
                ThemePatchCommand::OP_ADD_NODE,
                ThemePatchCommand::OP_REMOVE_NODE,
                ThemePatchCommand::OP_MOVE_NODE,
            ], true)
        ) {
            if (\preg_match($nodeRoot, $command->path, $matches) !== 1
                || $command->nodeUid !== ($matches[1] ?? null)
            ) {
                throw new \InvalidArgumentException('theme_patch_node_path_mismatch');
            }
        }
        if ($context->resourceType !== ThemeEditorContext::RESOURCE_LAYOUT
            && ($command->nodeUid !== null
                || $command->anchorUid !== null
                || $command->position !== null)
        ) {
            throw new \InvalidArgumentException('theme_patch_node_operation_resource_mismatch');
        }
    }

    private function assertActor(string $actorId, string $actorName): void
    {
        if (\trim($actorId) === '' || \strlen($actorId) > 128 || \strlen($actorName) > 128) {
            throw new \InvalidArgumentException('theme_scope_actor_invalid');
        }
    }

    /** @param array<string,mixed> $effectivePayload */
    private function validateLayoutPublication(
        ThemeEditorContext $context,
        array $effectivePayload,
        string $actorId,
    ): void {
        if ($context->resourceType !== ThemeEditorContext::RESOURCE_LAYOUT) {
            return;
        }
        [$fileActorId, $fileRoles] = $this->fileAccessClaims($actorId);
        $this->contentValidators->validate(
            $this->layoutSnapshots->denormalize($context, $effectivePayload),
            [
                'scope_identity' => $context->scope->identity,
                'locale_code' => $this->resolveFileAssetLocale($context),
                'actor_id' => $fileActorId,
                'roles' => $fileRoles,
                'purpose' => 'publish',
                'policy_revision' => 1,
            ],
        );
    }

    /** @param array<string,mixed> $effectivePayload */
    private function indexLayoutDraft(
        ThemeEditorContext $context,
        array $effectivePayload,
        int $revisionId,
        string $actorId,
    ): void {
        if ($context->resourceType !== ThemeEditorContext::RESOURCE_LAYOUT || $revisionId < 1) {
            return;
        }
        $localeCode = $this->resolveFileAssetLocale($context);
        [$fileActorId, $fileRoles] = $this->fileAccessClaims($actorId);
        $validationContext = [
            'scope_identity' => $context->scope->identity,
            'locale_code' => $localeCode,
            'actor_id' => $fileActorId,
            'roles' => $fileRoles,
            'purpose' => 'draft_index',
            'policy_revision' => 1,
        ];
        if ($localeCode !== '') {
            $validationContext += [
                'index_references' => true,
                'reference_only' => true,
                'reference_owner_type' => 'theme_layout_draft',
                'reference_owner_id' => $context->identityHash(),
                'owner_version' => $revisionId,
            ];
        }
        $this->contentValidators->validate(
            $this->layoutSnapshots->denormalize($context, $effectivePayload),
            $validationContext,
        );
    }

    /**
     * Default/all-language editor contexts still stamp file-image usage with the
     * site default locale. File layout validation needs that concrete locale.
     * Do not fall back to Env::default_LANGUAGE_CODE alone — storefront sites
     * such as Chang Hanfu use en_US while the framework default stays zh_Hans_CN.
     */
    private function resolveFileAssetLocale(ThemeEditorContext $context): string
    {
        $localeCode = trim($context->locale === 'default' ? '' : $context->locale);
        if ($localeCode === '' || strcasecmp($localeCode, 'default') === 0) {
            $localeCode = $this->resolveWebsiteDefaultLocale($context->scope->identity);
        }
        if ($localeCode === '' || strcasecmp($localeCode, 'default') === 0) {
            throw new \InvalidArgumentException('theme_scope_file_locale_unresolved');
        }

        return $localeCode;
    }

    /**
     * website_id=0 is the system default site, not "unset".
     */
    private function resolveWebsiteDefaultLocale(ScopeIdentity $identity): string
    {
        $kind = strtolower(trim($identity->scopeKind));
        // website_id=0 is the system default site, not "unset".
        $hasWebsiteScope = $kind !== '' && $kind !== ScopeIdentity::KIND_GLOBAL;
        $websiteId = $hasWebsiteScope ? max(0, (int)($identity->websiteId ?? 0)) : 0;
        try {
            if ($hasWebsiteScope && class_exists(\Weline\Websites\Model\Website::class)) {
                /** @var \Weline\Websites\Model\Website $website */
                $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
                $website->clearData()->load($websiteId);
                $fromWebsite = trim(str_replace('-', '_', (string)($website->getDefaultLanguage() ?? '')));
                if ($fromWebsite !== '') {
                    return $fromWebsite;
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                $fromCurrent = trim(str_replace(
                    '-',
                    '_',
                    (string)(\Weline\Websites\Data\WebsiteData::getDefaultLanguage() ?? '')
                ));
                if ($fromCurrent !== '') {
                    return $fromCurrent;
                }
            }
        } catch (\Throwable) {
        }

        try {
            $fromState = trim(str_replace('-', '_', (string)\Weline\Framework\App\State::resolveWebsiteDefaultLanguage()));
            if ($fromState !== '') {
                return $fromState;
            }
        } catch (\Throwable) {
        }

        return trim((string)Env::default_LANGUAGE_CODE);
    }

    private function numericBackendActorId(string $actorId): ?int
    {
        if (preg_match('/^backend-user:([1-9][0-9]*)$/D', $actorId, $matches) !== 1) {
            return null;
        }
        return (int)$matches[1];
    }

    /** @return array{0:?int,1:list<string>} */
    private function fileAccessClaims(string $actorId): array
    {
        $numericActorId = $this->numericBackendActorId($actorId);
        if ($numericActorId === null) {
            return [null, []];
        }
        try {
            $user = ObjectManager::getInstance(BackendUserContextProviderInterface::class)
                ->find($numericActorId);
            if ($user === null || !$user->getIsEnabled() || $user->getId() !== $numericActorId) {
                return [null, []];
            }
            return [
                $numericActorId,
                $user->getRoleId() > 0 ? ['backend_role:' . $user->getRoleId()] : [],
            ];
        } catch (\Throwable) {
            return [null, []];
        }
    }

    private function commandAtPath(int $revisionId, string $path): ?ThemePatchCommand
    {
        foreach ($this->commandsForRevision($revisionId) as $command) {
            if ($command->path === $path) {
                return $command;
            }
        }

        return null;
    }

    private function commandOwningPath(int $revisionId, string $path): ?ThemePatchCommand
    {
        $match = null;
        $matchLength = -1;
        foreach ($this->commandsForRevision($revisionId) as $command) {
            $candidate = \rtrim($command->path, '/');
            if (!$this->commandOwnsPath($command, $path)) {
                continue;
            }
            $length = \strlen($candidate);
            if ($path === $candidate) {
                $length += 4096;
            } elseif (\str_starts_with($path, $candidate . '/')) {
                $length += 2048;
            }
            if ($length > $matchLength) {
                $match = $command;
                $matchLength = $length;
            }
        }

        return $match;
    }

    private function resolveFromReleaseChain(
        ThemeEditorContext $requestedContext,
        ThemeScopeRelease $release,
        string $path,
        ?ThemeScopeWorkspace $requestedWorkspace,
    ): ThemeResolvedValue {
        $command = $this->commandOwningPath(
            (int)$release->getData(ThemeScopeRelease::schema_fields_REVISION_ID),
            $path,
        );
        [$exists, $value] = $this->patchEngine->readPath($release->payload(), $path);
        $isRequestedScope = (string)$release->getData(ThemeScopeRelease::schema_fields_IDENTITY_HASH)
            === $requestedContext->identityHash();
        if ($command instanceof ThemePatchCommand) {
            return new ThemeResolvedValue(
                effectiveValue: $exists ? $value : null,
                localValue: $isRequestedScope && $exists ? $value : null,
                hasLocalValue: $isRequestedScope && $exists,
                sourceScope: (string)$release->getData(ThemeScopeRelease::schema_fields_SCOPE),
                sourceReleaseId: $release->getId(),
                isOwned: $isRequestedScope,
                canRestoreInheritance: $isRequestedScope
                    && $this->canRestoreCommandAtPath($command, $path),
                conflicts: $requestedWorkspace?->conflicts() ?? [],
            );
        }

        $parentRelease = $this->loadRelease((int)$release->getData(
            ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID,
        ));
        if ($parentRelease instanceof ThemeScopeRelease) {
            $resolved = $this->resolveFromReleaseChain($requestedContext, $parentRelease, $path, $requestedWorkspace);

            return new ThemeResolvedValue(
                effectiveValue: $exists ? $value : $resolved->effectiveValue,
                localValue: null,
                hasLocalValue: false,
                sourceScope: $resolved->sourceScope,
                sourceReleaseId: $resolved->sourceReleaseId,
                isOwned: false,
                canRestoreInheritance: false,
                conflicts: $requestedWorkspace?->conflicts() ?? [],
            );
        }

        return new ThemeResolvedValue(
            effectiveValue: $exists ? $value : null,
            localValue: null,
            hasLocalValue: false,
            sourceScope: 'theme-package-default',
            sourceReleaseId: null,
            isOwned: false,
            canRestoreInheritance: false,
            conflicts: $requestedWorkspace?->conflicts() ?? [],
        );
    }

    /**
     * @return list<array{path:string,operation:string,source_scope:string,source_release_id:int,precedence:int}>
     */
    private function inheritedSourceRules(?ThemeScopeRelease $release): array
    {
        $rules = [];
        $precedence = 0;
        $visited = [];
        while ($release instanceof ThemeScopeRelease) {
            $releaseId = $release->getId();
            if ($releaseId <= 0 || isset($visited[$releaseId])) {
                break;
            }
            $visited[$releaseId] = true;
            $sourceScope = (string)$release->getData(ThemeScopeRelease::schema_fields_SCOPE);
            $revisionId = (int)$release->getData(ThemeScopeRelease::schema_fields_REVISION_ID);
            foreach ($this->commandsForRevision($revisionId) as $command) {
                $rules[] = [
                    'path' => $command->path,
                    'operation' => $command->operation,
                    'source_scope' => $sourceScope,
                    'source_release_id' => $releaseId,
                    'precedence' => $precedence,
                ];
            }
            $release = $this->loadRelease((int)$release->getData(
                ThemeScopeRelease::schema_fields_PARENT_RELEASE_ID,
            ));
            ++$precedence;
        }

        return $rules;
    }

    private function commandOwnsPath(ThemePatchCommand $command, string $path): bool
    {
        $candidate = \rtrim($command->path, '/');
        $path = \rtrim($path, '/');
        if ($path === $candidate || \str_starts_with($candidate, $path . '/')) {
            return true;
        }
        if (!\str_starts_with($path, $candidate . '/')) {
            return false;
        }
        if ($command->operation !== ThemePatchCommand::OP_MOVE_NODE) {
            return true;
        }

        $relative = \substr($path, \strlen($candidate) + 1);
        return \in_array($relative, ['parent_uid', 'anchor_uid', 'position'], true);
    }

    private function canRestoreCommandAtPath(ThemePatchCommand $command, string $path): bool
    {
        $candidate = \rtrim($command->path, '/');
        $path = \rtrim($path, '/');

        return $candidate === $path || \str_starts_with($candidate, $path . '/');
    }

    private function isStrictDescendant(ScopeIdentity $candidate, ScopeIdentity $ancestor): bool
    {
        $cursor = $this->scopes->parentIdentity($candidate);
        while ($cursor instanceof ScopeIdentity) {
            if ($cursor->equals($ancestor)) {
                return true;
            }
            $cursor = $this->scopes->parentIdentity($cursor);
        }

        return false;
    }

    private function supportsForUpdate(): bool
    {
        $type = \strtolower((string)$this->workspaces->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());

        return \in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    private function requestLoadCacheKey(ThemeEditorContext $context, bool $includeDraft): string
    {
        return self::REQUEST_LOAD_CACHE_PREFIX . \hash(
            'xxh3',
            \implode("\0", [
                ...$context->identityParts(),
                $includeDraft ? '1' : '0',
            ]),
        );
    }

    /** @param array<string,mixed> $state */
    private function rememberRequestLoad(string $cacheKey, array $state): void
    {
        if (!RequestContext::isInitialized()) {
            return;
        }
        RequestContext::set($cacheKey, $state);
        $keys = RequestContext::get(self::REQUEST_LOAD_CACHE_KEYS, []);
        if (!\is_array($keys)) {
            $keys = [];
        }
        $keys[] = $cacheKey;
        RequestContext::set(self::REQUEST_LOAD_CACHE_KEYS, \array_values(\array_unique($keys)));
    }

    private function dispatchScopedPublishResourceChange(
        ThemeEditorContext $context,
        int $releaseId,
        string $entry,
        bool $idempotent,
        ?int $themeVersionId = null,
        ?int $contentRevision = null,
    ): void {
        $themeId = max(0, $context->themeId);
        $resourceType = $context->resourceType === ThemeEditorContext::RESOURCE_THEME_BINDING
            ? 'theme'
            : 'theme_layout';
        $resourceId = $themeId > 0 ? (string)$themeId : ('scope:' . $context->scope->storageScope);
        if (\strlen($resourceId) > 191) {
            $resourceId = 'sha256:' . \hash('sha256', $resourceId);
        }
        $identity = $context->scope->identity;
        $websiteId = max(0, (int)($identity->websiteId ?? 0));
        $websiteCode = \trim((string)($identity->websiteCode ?? ''));
        if ($websiteCode === '') {
            $websiteCode = $identity->isGlobal() ? 'default' : ($websiteId === 0 ? 'default' : ('w' . $websiteId));
        }
        $namespacePath = ObjectManager::getInstance(NamespacePath::class);
        $namespaces = [$namespacePath->global('storefront', ['theme'])];
        if ($websiteCode !== '') {
            $namespaces[] = $namespacePath->website($websiteCode, ['theme']);
            if ($themeId > 0) {
                $namespaces[] = $namespacePath->website($websiteCode, ['theme', (string)$themeId]);
            }
        }
        $namespaces = \array_values(\array_unique($namespaces));
        \sort($namespaces, \SORT_STRING);
        $revision = ObjectManager::getInstance(ResourceRevisionService::class)->next($resourceType, $resourceId);
        $after = [
            'theme_id' => $themeId,
            'release_id' => max(0, $releaseId),
            'resource_type' => $context->resourceType,
            'area' => $context->area,
            'layout_type' => $context->layoutType,
            'layout_option' => $context->layoutOption,
            'locale' => $context->locale,
            'storage_scope' => $context->scope->storageScope,
            'scope' => $context->scope->toArray(),
            'idempotent' => $idempotent,
        ];
        if ($themeVersionId !== null && $themeVersionId > 0) {
            $after['theme_version_id'] = $themeVersionId;
        }
        if ($contentRevision !== null && $contentRevision > 0) {
            $after['content_revision'] = $contentRevision;
        }
        $change = ObjectManager::getInstance(ResourceChangeFactory::class)->create(
            resourceType: $resourceType,
            resourceId: $resourceId,
            action: 'publish',
            revision: $revision,
            websiteId: $websiteId,
            websiteCode: $websiteCode,
            before: [],
            after: $after,
            changedFields: ['published_release', 'static_version', 'theme_version_id'],
            impact: [
                'namespaces' => $namespaces,
                'urls' => ['/'],
            ],
            origin: ['entry' => $entry],
            siteId: $websiteId,
        );
        \w_changed($change);
    }

    public function invalidateRequestLoadCache(): void
    {
        $this->flushRequestLoadCache();
    }

    private function flushRequestLoadCache(): void
    {
        if (!RequestContext::isInitialized()) {
            return;
        }
        $keys = RequestContext::get(self::REQUEST_LOAD_CACHE_KEYS, []);
        if (\is_array($keys)) {
            foreach ($keys as $key) {
                if (\is_string($key) && $key !== '') {
                    RequestContext::remove($key);
                }
            }
        }
        RequestContext::remove(self::REQUEST_LOAD_CACHE_KEYS);

        $workspaceKeys = RequestContext::get(self::REQUEST_WORKSPACE_CACHE_KEYS, []);
        if (is_array($workspaceKeys)) {
            foreach ($workspaceKeys as $key) {
                if (is_string($key) && $key !== '') {
                    RequestContext::remove($key);
                }
            }
        }
        RequestContext::remove(self::REQUEST_WORKSPACE_CACHE_KEYS);

        // Fail-closed sweep: tracked key lists can lag behind direct sets
        // (e.g. published-snapshot suffixes). Drop every scoped memo by prefix.
        foreach (RequestContext::all() as $key => $_value) {
            if (!\is_string($key) || $key === '') {
                continue;
            }
            if (\str_starts_with($key, self::REQUEST_LOAD_CACHE_PREFIX)
                || \str_starts_with($key, self::REQUEST_WORKSPACE_CACHE_PREFIX)
            ) {
                RequestContext::remove($key);
            }
        }
    }

    private function json(mixed $value): string
    {
        return \json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
