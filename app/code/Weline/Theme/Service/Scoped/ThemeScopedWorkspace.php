<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Api\Scoped\ThemeResolvedValue;
use Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeScopePatch;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeRevision;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Service\LayoutContentValidationRegistry;

/** Canonical per-path draft, merge and immutable release service. */
final class ThemeScopedWorkspace implements ThemeScopedWorkspaceInterface
{
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
    ) {
    }

    public function load(ThemeEditorContext $context, bool $includeDraft = true): array
    {
        $workspace = $this->findWorkspace($context);
        $parent = $this->parentPublishedState($context);
        $published = $this->publishedState($context);
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

        return [
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
    }

    public function applyChanges(
        ThemeEditorContext $context,
        int $expectedRevision,
        ?int $expectedParentReleaseId,
        array $changes,
        string $actorId,
        string $actorName = '',
        string $summary = '',
    ): array {
        $this->assertActor($actorId, $actorName);
        $changes = $this->assertCommands($context, $changes);
        $expectedParentReleaseId = $this->nullablePositiveInt($expectedParentReleaseId);

        return $this->transactions->runWrite(
            $this->workspaces->getConnection(),
            function () use (
                $context,
                $expectedRevision,
                $expectedParentReleaseId,
                $changes,
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
                $this->indexLayoutDraft($context, $draftPayload, $revision->getId(), $actorId);

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
        $this->assertActor($actorId, $actorName);
        $expectedParentReleaseId = $this->nullablePositiveInt($expectedParentReleaseId);

        return $this->transactions->runWrite(
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
    }

    public function publish(
        ThemeEditorContext $context,
        int $expectedRevision,
        ?int $expectedParentReleaseId,
        string $actorId,
        string $actorName = '',
        string $reason = '',
    ): array {
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
                $this->adapter->projectPublished($context, $effective, $release->getId());
                $this->markRevisionPublished($revisionId);
                $workspace->setData([
                    ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID => $release->getId(),
                    ThemeScopeWorkspace::schema_fields_LAST_GOOD_RELEASE_ID => $release->getId(),
                    ThemeScopeWorkspace::schema_fields_PARENT_RELEASE_ID => $parent['release_id'],
                    ThemeScopeWorkspace::schema_fields_STATUS => ThemeScopeWorkspace::STATUS_ACTIVE,
                    ThemeScopeWorkspace::schema_fields_CONFLICT_JSON => null,
                ])->save();

                return [
                    'release_id' => $release->getId(),
                    'revision' => $workspace->getRevision(),
                    'parent_release_id' => $parent['release_id'],
                    'fingerprint' => (string)$release->getData(ThemeScopeRelease::schema_fields_FINGERPRINT),
                    'payload' => $effective,
                    'conflicts' => [],
                    'idempotent' => false,
                ];
            },
        );

        if (($result['blocked'] ?? false) === true) {
            throw new \RuntimeException('theme_scope_structural_conflict');
        }

        $result['descendants'] = $this->propagateToDescendants($context, $actorId, $actorName);

        return $result;
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
    private function publishedState(ThemeEditorContext $context): array
    {
        $workspace = $this->findWorkspace($context);
        if ($workspace instanceof ThemeScopeWorkspace) {
            $release = $this->loadRelease((int)$workspace->getData(
                ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID,
            ));
            if ($release instanceof ThemeScopeRelease) {
                return [
                    'payload' => $release->payload(),
                    'release_id' => $release->getId(),
                    'source_scope' => (string)$release->getData(ThemeScopeRelease::schema_fields_SCOPE),
                    'release' => $release,
                ];
            }
        }
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

    private function findWorkspace(ThemeEditorContext $context, bool $lockingRead = false): ?ThemeScopeWorkspace
    {
        $workspace = clone $this->workspaces;
        $workspace->clearData()->clearQuery()
            ->where(ThemeScopeWorkspace::schema_fields_IDENTITY_HASH, $context->identityHash());
        if ($lockingRead && $this->supportsForUpdate()) {
            $workspace->additional('FOR UPDATE');
        }
        $workspace->find()->fetch();

        return $workspace->getId() > 0 ? $workspace : null;
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

    /** @return array<string,mixed> */
    private function payloadForReleaseOrRootBase(?int $releaseId, ThemeEditorContext $context): array
    {
        $release = $releaseId !== null ? $this->loadRelease($releaseId) : null;
        if ($release instanceof ThemeScopeRelease) {
            return $release->payload();
        }
        $root = $context;
        $cursor = $context->scope->identity;
        while (($parent = $this->scopes->parentIdentity($cursor)) instanceof ScopeIdentity) {
            $cursor = $parent;
            $root = $root->withScope($this->scopes->contextFromIdentity($cursor));
        }

        return $this->adapter->loadBase($root);
    }

    /** @return array{updated:int,conflicted:int,conflicts:list<array<string,mixed>>,errors:list<array<string,string>>} */
    private function propagateToDescendants(ThemeEditorContext $publishedContext, string $actorId, string $actorName): array
    {
        $rows = (clone $this->workspaces)->clearData()->clearQuery()
            ->where(ThemeScopeWorkspace::schema_fields_AREA, $publishedContext->area)
            ->where(ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE, $publishedContext->resourceType)
            ->where(ThemeScopeWorkspace::schema_fields_THEME_ID, $publishedContext->identityThemeId())
            ->where(ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE, $publishedContext->identityLayoutType())
            ->where(ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION, $publishedContext->identityLayoutOption())
            ->where(ThemeScopeWorkspace::schema_fields_LOCALE, $publishedContext->identityLocale())
            ->where(ThemeScopeWorkspace::schema_fields_TARGET_TYPE, $publishedContext->identityTargetType())
            ->where(ThemeScopeWorkspace::schema_fields_TARGET_ID, $publishedContext->identityTargetId())
            ->select()->fetchArray();
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
            function () use ($context, $actorId, $actorName): array {
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

                return ['updated' => true, 'conflicts' => $draftConflicts];
            },
        );
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
            ThemeEditorContext::RESOURCE_APPEARANCE => \preg_match('#^/(?:tokens|disks)' . $mapPath . '$#D', $command->path) === 1,
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
                'locale_code' => $context->locale === 'default' ? '' : $context->locale,
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
        $localeCode = $context->locale === 'default' ? '' : $context->locale;
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

    private function json(mixed $value): string
    {
        return \json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
