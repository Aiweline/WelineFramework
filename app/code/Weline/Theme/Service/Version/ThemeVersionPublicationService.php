<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Version;

use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Api\Version\ThemeVersionPublicationInterface;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionSelection;

/**
 * Theme-owned create/save/seal/publish/selectHistory orchestration.
 * Task 3: candidate-then-CAS selection; single-page remainder → D'; named seal without publish.
 */
final class ThemeVersionPublicationService implements ThemeVersionPublicationInterface
{
    public function __construct(
        private readonly ThemeVersionSnapshotBuilder $snapshots,
        private readonly ThemeVersionSelectionResolver $selectionResolver,
    ) {
    }

    public function createDraft(ThemeVersionIdentity $owner, string $creationSourceKind, array $options = []): array
    {
        if (!\in_array($creationSourceKind, self::CREATION_SOURCES, true)) {
            throw new \InvalidArgumentException('creation_source_kind_invalid');
        }
        $existingDraftId = (int)($options['existing_draft_version_id'] ?? 0);
        if (
            $creationSourceKind === self::CREATION_CONTINUE_CURRENT
            && $existingDraftId > 0
            && empty($options['force_new'])
        ) {
            return [
                'theme_version_id' => $existingDraftId,
                'content_revision' => (int)($options['existing_content_revision'] ?? 1),
                'creation_source_kind' => $creationSourceKind,
                'reused_existing_draft' => true,
            ];
        }

        $baseVersionId = (int)($options['base_version_id'] ?? $options['published_version_id'] ?? 0);
        if ($creationSourceKind === self::CREATION_EXPLICIT_HISTORICAL) {
            $baseVersionId = (int)($options['source_theme_version_id'] ?? 0);
            if ($baseVersionId < 1) {
                throw new \InvalidArgumentException('explicit_historical_source_required');
            }
        }
        if ($baseVersionId < 1 && $creationSourceKind !== self::CREATION_PACKAGE_DEFAULTS) {
            throw new \InvalidArgumentException('create_draft_base_required');
        }

        $newVersionId = (int)($options['allocated_version_id'] ?? 0);
        if ($newVersionId < 1) {
            throw new \InvalidArgumentException('create_draft_requires_allocated_version_id');
        }

        $autoBackup = null;
        if ($existingDraftId > 0 && $creationSourceKind !== self::CREATION_CONTINUE_CURRENT) {
            $autoBackup = [
                'theme_version_id' => $existingDraftId,
                'lifecycle' => ThemeScopeVersion::LIFECYCLE_SEALED,
                'version_type' => ThemeScopeVersion::TYPE_AUTO_BACKUP,
                'published' => false,
            ];
        }

        $decisions = $this->snapshots->copyDecisionsToTargetVersion(
            \is_array($options['source_decisions'] ?? null) ? $options['source_decisions'] : [],
            $newVersionId,
            1,
            $baseVersionId > 0 ? $baseVersionId : null,
        );

        return [
            'theme_version_id' => $newVersionId,
            'content_revision' => 1,
            'lifecycle' => ThemeScopeVersion::LIFECYCLE_DRAFT,
            'creation_source_kind' => $creationSourceKind,
            'base_version_id' => $baseVersionId,
            'auto_backup' => $autoBackup,
            'widget_decisions' => $decisions,
            'reused_existing_draft' => false,
            'owner' => $owner->toArray(),
        ];
    }

    public function saveDraft(ThemeVersionIdentity $identity, array $changes, int $expectedContentRevision): array
    {
        if ($identity->mode !== ThemeVersionIdentity::MODE_DRAFT || $identity->themeVersionId < 1) {
            throw new \InvalidArgumentException('save_draft_requires_draft_identity');
        }
        if ($expectedContentRevision < 0) {
            throw new \InvalidArgumentException('expected_content_revision_invalid');
        }
        $actual = $identity->contentRevision;
        if ($actual !== $expectedContentRevision) {
            return [
                'ok' => false,
                'conflict' => true,
                'theme_version_id' => $identity->themeVersionId,
                'content_revision' => $actual,
                'reason' => 'content_revision_cas_failed',
            ];
        }
        $next = $expectedContentRevision + 1;

        return [
            'ok' => true,
            'conflict' => false,
            'theme_version_id' => $identity->themeVersionId,
            'content_revision' => $next,
            'changed_resources' => \array_values(\array_keys($changes)),
            'identity' => $identity->withVersion($identity->themeVersionId, ThemeVersionIdentity::MODE_DRAFT, $next)->toArray(),
        ];
    }

    public function seal(ThemeVersionIdentity $identity, array $options = []): array
    {
        if ($identity->themeVersionId < 1 || $identity->contentRevision < 1) {
            throw new \InvalidArgumentException('seal_requires_version_and_revision');
        }
        $publishedId = (int)($options['published_version_id'] ?? 0);

        return [
            'theme_version_id' => $identity->themeVersionId,
            'content_revision' => $identity->contentRevision,
            'lifecycle' => ThemeScopeVersion::LIFECYCLE_SEALED,
            'published' => false,
            'published_version_id' => $publishedId > 0 ? $publishedId : null,
            'n_equals_d' => true,
            'identity' => $identity->withVersion(
                $identity->themeVersionId,
                ThemeVersionIdentity::MODE_FORMAL,
                $identity->contentRevision,
            )->toArray(),
        ];
    }

    public function publish(ThemeVersionIdentity $identity, array $options = []): array
    {
        $expectedSelectionRevision = (int)($options['expected_selection_revision'] ?? -1);
        $actualSelectionRevision = (int)($options['actual_selection_revision'] ?? 0);
        if ($expectedSelectionRevision >= 0 && $expectedSelectionRevision !== $actualSelectionRevision) {
            return [
                'ok' => false,
                'conflict' => true,
                'reason' => 'selection_revision_cas_failed',
                'published_version_id' => (int)($options['current_published_version_id'] ?? 0),
                'draft_version_id' => (int)($options['current_draft_version_id'] ?? 0),
            ];
        }

        // 这里曾有一个 candidate_write_ok 分支：缺省 true、由请求参数传入。UI 从不发送它，
        // 所以它是一条永不可达的死码 —— 真正的「候选写入失败」由控制器的封存/烘焙步骤
        // 直接判失败并中止（见 ThemeEditor::publishScopeVersionPayload 的 bake 分支），
        // 不再接受客户端用一个布尔值让发布静默变成 no-op。
        $resourcePlan = $this->planPublishedResources($options);
        $publishedResources = $resourcePlan['published_resources'];
        $remainingDraft = $resourcePlan['remaining_draft_resources'];

        $nId = $identity->themeVersionId;
        $sealedRevision = (int)($options['sealed_content_revision'] ?? \max(1, $identity->contentRevision));
        $draftPrimeId = null;
        $draftPrimeRevision = null;
        if ($remainingDraft !== []) {
            $draftPrimeId = (int)($options['allocated_draft_prime_id'] ?? 0);
            if ($draftPrimeId < 1) {
                throw new \InvalidArgumentException('single_page_publish_requires_draft_prime_id');
            }
            $draftPrimeRevision = 1;
        }

        $descendantUpdates = \is_array($options['descendant_updates'] ?? null) ? $options['descendant_updates'] : [];
        $descendantConflicts = \is_array($options['descendant_conflicts'] ?? null) ? $options['descendant_conflicts'] : [];

        $ownerIdentity = $identity->withVersion($nId, ThemeVersionIdentity::MODE_FORMAL, $sealedRevision);
        $changedOwners = [$ownerIdentity->toArray()];
        foreach ($descendantUpdates as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if (!empty($row['identity']) && \is_array($row['identity'])) {
                $changedOwners[] = $row['identity'];
            }
        }

        return [
            'ok' => true,
            'conflict' => false,
            'theme_version_id' => $nId,
            'content_revision' => $sealedRevision,
            'published_version_id' => $nId,
            'selection_revision' => $actualSelectionRevision + 1,
            'draft_version_id' => $draftPrimeId,
            'draft_content_revision' => $draftPrimeRevision,
            'published_resources' => \array_values($publishedResources),
            'remaining_draft_resources' => \array_values($remainingDraft),
            'descendant_updates' => $descendantUpdates,
            'descendant_conflicts' => $descendantConflicts,
            'n_equals_d' => true,
            // Post-commit: caller must invalidate each changed owner (Theme/FPC generation).
            'invalidation' => [
                'reason' => 'theme_version_published',
                'changed_owners' => $changedOwners,
                'theme_version_ids' => \array_values(\array_unique(\array_filter([
                    $nId,
                    ...\array_map(
                        static fn(array $o): int => (int)($o['theme_version_id'] ?? 0),
                        $changedOwners,
                    ),
                ]))),
            ],
        ];
    }

    /**
     * 本次发布实际会占有的资源集合（纯计算，无副作用）。
     *
     * 单独抽出来是为了让后代传播能在 publish() 之前就知道「父这次发布了哪些资源」——
     * 否则要么重复一份合并算法，要么把 publish() 拆成两段。算法只有这一份。
     *
     * @param array<string,mixed> $options
     * @return array{published_resources:list<string>,remaining_draft_resources:list<string>}
     */
    public function planPublishedResources(array $options): array
    {
        $publishSet = $options['publish_set'] ?? 'all';
        $selected = \is_array($publishSet) ? $publishSet : null;
        $draftResources = \is_array($options['draft_resources'] ?? null) ? $options['draft_resources'] : [];
        $preparedPublished = \is_array($options['prepared_published_resources'] ?? null)
            ? $options['prepared_published_resources']
            : [];

        $publishedResources = [];
        $remainingDraft = [];
        if ($selected === null || $publishSet === 'all') {
            $publishedResources = $draftResources !== [] ? $draftResources : ['*'];
        } else {
            foreach ($draftResources as $resourceKey) {
                if (\in_array($resourceKey, $selected, true)) {
                    $publishedResources[] = $resourceKey;
                } else {
                    $remainingDraft[] = $resourceKey;
                }
            }
            // Unselected resources keep prepared-time P, not draft or historical B.
            foreach ($preparedPublished as $resourceKey) {
                if (!\in_array($resourceKey, $publishedResources, true)
                    && !\in_array($resourceKey, $remainingDraft, true)
                ) {
                    $publishedResources[] = $resourceKey;
                }
            }
        }

        return [
            'published_resources' => \array_values($publishedResources),
            'remaining_draft_resources' => \array_values($remainingDraft),
        ];
    }

    public function selectHistory(ThemeVersionIdentity $identity, array $options = []): array
    {
        if ($identity->mode !== ThemeVersionIdentity::MODE_FORMAL || $identity->themeVersionId < 1) {
            throw new \InvalidArgumentException('select_history_requires_sealed_formal');
        }
        $expectedSelectionRevision = (int)($options['expected_selection_revision'] ?? -1);
        $actualSelectionRevision = (int)($options['actual_selection_revision'] ?? 0);
        if ($expectedSelectionRevision >= 0 && $expectedSelectionRevision !== $actualSelectionRevision) {
            return [
                'ok' => false,
                'conflict' => true,
                'reason' => 'selection_revision_cas_failed',
            ];
        }
        $keepDraftId = $options['current_draft_version_id'] ?? null;

        return [
            'ok' => true,
            'published_version_id' => $identity->themeVersionId,
            'content_revision' => \max(1, $identity->contentRevision),
            'draft_version_id' => $keepDraftId !== null && $keepDraftId !== '' ? (int)$keepDraftId : null,
            'selection_revision' => $actualSelectionRevision + 1,
            'rewrote_history' => false,
        ];
    }

    /**
     * Pure planner: parent P→P' descendant outcomes (no DB).
     *
     * @param list<array{owner_hash:string,has_local_override:bool,effective_changed:bool,conflict:bool}> $descendants
     * @return array{updates:list<string>,conflicts:list<string>,fallback:list<string>}
     */
    public function planDescendantPropagation(array $descendants): array
    {
        $updates = [];
        $conflicts = [];
        $fallback = [];
        foreach ($descendants as $row) {
            $owner = (string)($row['owner_hash'] ?? '');
            if ($owner === '') {
                continue;
            }
            if (empty($row['has_local_override'])) {
                $fallback[] = $owner;
                continue;
            }
            if (!empty($row['conflict'])) {
                $conflicts[] = $owner;
                continue;
            }
            if (!empty($row['effective_changed'])) {
                $updates[] = $owner;
            }
        }

        return [
            'updates' => $updates,
            'conflicts' => $conflicts,
            'fallback' => $fallback,
        ];
    }
}
