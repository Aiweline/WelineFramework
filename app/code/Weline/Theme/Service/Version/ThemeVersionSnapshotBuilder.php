<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Version;

use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Api\Version\ThemeVersionPublicationInterface;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionWidgetDecision;
use Weline\Theme\Model\ThemeScopeWorkspace;

/**
 * Builds logical owner/V/R mappings and conversion dry-run receipts.
 * Does not switch formal reads (Task 6). Pure mapping helpers are DB-free for unit tests.
 */
final class ThemeVersionSnapshotBuilder
{
    public const RECEIPT_STATUS_CONVERTIBLE = 'convertible';
    public const RECEIPT_STATUS_ARCHIVE_ONLY = 'archive_only';
    public const RECEIPT_STATUS_NEEDS_INTENT_MAP = 'needs_intent_map';

    /**
     * Chrome resource key shared by all pages under one owner/version revision.
     */
    public function chromeResourceIdentityHash(ThemeVersionIdentity $owner): string
    {
        return \hash('sha256', \implode("\0", [
            $owner->canonicalScope,
            $owner->storeMode,
            $owner->area,
            'chrome',
            (string)$owner->themeId,
        ]));
    }

    /**
     * 主题绑定必须留在版本外：绑定先选主题、再选该主题的版本，
     * 绑定一旦自己占有版本，就会形成「先知道版本才能选主题」的循环。
     *
     * 本方法是写入口的边界断言；真正的落库强制在
     * ThemeScopeWorkspace::save_before()（绑定行带版本号直接拒绝）。
     *
     * @throws \InvalidArgumentException 绑定身份混入主题 id，或绑定试图占有版本
     */
    public function assertThemeBindingHasNoVersionCycle(ThemeEditorContext $context, int $themeVersionId): void
    {
        if ($context->resourceType !== ThemeEditorContext::RESOURCE_THEME_BINDING) {
            return;
        }
        // 绑定身份不得内嵌主题 id。identityThemeId() 对绑定恒返回 0，
        // 这里把它固定成显式契约，避免日后有人改掉该访问器而静默放行。
        if ($context->identityThemeId() !== 0) {
            throw new \InvalidArgumentException('theme_binding_identity_must_drop_theme_id');
        }
        // 唯一可能失败的检查：绑定不得占有版本。
        // 绑定身份哈希取自 identityParts()，其中版本相关位恒为 0，
        // 因此哈希不会随版本号变化 —— 即不存在 theme↔version 循环。
        if ($themeVersionId !== ThemeScopeWorkspace::THEME_VERSION_EXTERNAL) {
            throw new \InvalidArgumentException('theme_binding_must_not_own_theme_version');
        }
    }

    /**
     * Widget uninstall/install decisions always target the destination version.
     *
     * @param list<array<string, mixed>> $sourceDecisions
     * @return list<array<string, mixed>>
     */
    public function copyDecisionsToTargetVersion(
        array $sourceDecisions,
        int $targetThemeVersionId,
        int $contentRevision,
        ?int $auditSourceVersionId = null,
    ): array {
        if ($targetThemeVersionId < 1 || $contentRevision < 1) {
            throw new \InvalidArgumentException('decision_target_version_invalid');
        }
        $out = [];
        foreach ($sourceDecisions as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $injectionKey = \trim((string)($row['injection_key'] ?? ''));
            $resourceHash = \trim((string)($row['resource_identity_hash'] ?? ''));
            $decision = \trim((string)($row['decision'] ?? ThemeScopeVersionWidgetDecision::DECISION_UNINSTALL));
            if ($injectionKey === '' || $resourceHash === '') {
                continue;
            }
            $out[] = [
                'theme_version_id' => $targetThemeVersionId,
                'content_revision' => $contentRevision,
                'resource_identity_hash' => $resourceHash,
                'injection_key' => $injectionKey,
                'decision' => $decision,
                'slot_identity' => (string)($row['slot_identity'] ?? ''),
                'widget_identity' => (string)($row['widget_identity'] ?? ''),
                'actor_id' => (string)($row['actor_id'] ?? ''),
                // Audit only — runtime must not treat this as target authority.
                'source_version_id' => $auditSourceVersionId ?? (isset($row['theme_version_id']) ? (int)$row['theme_version_id'] : null),
            ];
        }

        return $out;
    }

    /**
     * Dry-run conversion from legacy scoped / chrome version rows to owner/V/R mappings.
     *
     * @param list<array<string, mixed>> $legacyVersions ThemeScopeVersion-like rows
     * @param list<array<string, mixed>> $legacyReleases ThemeScopeRelease-like rows with evidence flags
     * @param list<array<string, mixed>> $legacyIntents patch/revision evidence (optional)
     * @return array{
     *   receipt_id:string,
     *   mode:string,
     *   owners:list<array<string,mixed>>,
     *   mappings:list<array<string,mixed>>,
     *   archive_only:list<array<string,mixed>>,
     *   blocked:list<array<string,mixed>>,
     *   fingerprint:string
     * }
     */
    public function dryRunConvert(
        array $legacyVersions,
        array $legacyReleases = [],
        array $legacyIntents = [],
        string $receiptId = '',
    ): array {
        $receiptId = $receiptId !== '' ? $receiptId : ('cvt-' . \bin2hex(\random_bytes(8)));
        $owners = [];
        $mappings = [];
        $archiveOnly = [];
        $blocked = [];

        $releaseEvidence = [];
        foreach ($legacyReleases as $release) {
            if (!\is_array($release)) {
                continue;
            }
            $legacyVersionId = (int)($release['theme_version_id'] ?? $release['legacy_version_id'] ?? 0);
            $hasCompleteLink = !empty($release['complete_page_chrome_evidence'])
                || !empty($release['batch_receipt_id']);
            if ($legacyVersionId > 0) {
                $releaseEvidence[$legacyVersionId] = [
                    'has_complete_link' => $hasCompleteLink,
                    'release_id' => (int)($release['release_id'] ?? 0),
                    'identity_hash' => (string)($release['identity_hash'] ?? ''),
                ];
            }
        }

        $intentByVersion = [];
        foreach ($legacyIntents as $intent) {
            if (!\is_array($intent)) {
                continue;
            }
            $legacyVersionId = (int)($intent['legacy_version_id'] ?? $intent['theme_version_id'] ?? 0);
            if ($legacyVersionId > 0) {
                $intentByVersion[$legacyVersionId] = $intent;
            }
        }

        $nextSyntheticId = 1;
        foreach ($legacyVersions as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $legacyId = (int)($row['version_id'] ?? 0);
            $themeId = (int)($row['theme_id'] ?? 0);
            $scope = \trim((string)($row['scope'] ?? ''));
            $storeMode = \trim((string)($row['store_mode'] ?? 'normal')) ?: 'normal';
            $area = \trim((string)($row['area'] ?? ''));
            $isPublished = !empty($row['is_published']);
            $isCurrent = !empty($row['is_current']);

            if ($themeId < 1 || $scope === '') {
                $blocked[] = [
                    'legacy_version_id' => $legacyId,
                    'status' => self::RECEIPT_STATUS_NEEDS_INTENT_MAP,
                    'reason' => 'missing_owner_fields',
                ];
                continue;
            }

            // Old chrome rows without area: only build a cutover baseline when consumer proof exists.
            if ($area === '') {
                $provenConsumers = $row['proven_consumer_owners'] ?? null;
                if (!\is_array($provenConsumers) || $provenConsumers === []) {
                    $archiveOnly[] = [
                        'legacy_version_id' => $legacyId,
                        'status' => self::RECEIPT_STATUS_ARCHIVE_ONLY,
                        'reason' => 'chrome_missing_area_without_consumer_proof',
                        'label' => '旧记录，仅归档',
                    ];
                    continue;
                }
                $area = (string)($provenConsumers[0]['area'] ?? 'frontend');
                if (!\in_array($area, ThemeVersionIdentity::AREAS, true)) {
                    $area = ThemeVersionIdentity::AREA_FRONTEND;
                }
            }

            try {
                $owner = new ThemeVersionIdentity(
                    themeId: $themeId,
                    canonicalScope: $scope,
                    storeMode: $storeMode,
                    area: $area,
                );
            } catch (\InvalidArgumentException $e) {
                $blocked[] = [
                    'legacy_version_id' => $legacyId,
                    'status' => self::RECEIPT_STATUS_NEEDS_INTENT_MAP,
                    'reason' => $e->getMessage(),
                ];
                continue;
            }

            $ownerHash = $owner->ownerHash();
            if (!isset($owners[$ownerHash])) {
                $owners[$ownerHash] = $owner->toArray();
            }

            $evidence = $releaseEvidence[$legacyId] ?? null;
            $isBaseline = $isPublished || $isCurrent;
            $hasIntent = isset($intentByVersion[$legacyId])
                || !empty($row['has_revision_patch_evidence'])
                || !empty($row['has_explicit_user_ops']);

            // Unproven history: archive only — never forge a renderable H.
            if (!$isBaseline && ($evidence === null || empty($evidence['has_complete_link']))) {
                $archiveOnly[] = [
                    'legacy_version_id' => $legacyId,
                    'owner_hash' => $ownerHash,
                    'status' => self::RECEIPT_STATUS_ARCHIVE_ONLY,
                    'reason' => 'insufficient_page_chrome_decision_evidence',
                    'label' => '旧记录，仅归档',
                ];
                continue;
            }

            // Current baseline without explainable intent cannot silently drop user content.
            if ($isBaseline && !$hasIntent && !empty($row['effective_payload_without_source'])) {
                $blocked[] = [
                    'legacy_version_id' => $legacyId,
                    'owner_hash' => $ownerHash,
                    'status' => self::RECEIPT_STATUS_NEEDS_INTENT_MAP,
                    'reason' => 'current_state_lacks_intent_provenance',
                ];
                continue;
            }

            $retainInPlace = !empty($row['retain_version_id']) || (($row['id_strategy'] ?? '') === 'in_place_retain');
            if ($retainInPlace && $legacyId > 0) {
                $newVersionId = $legacyId;
                $idStrategy = 'in_place_retain';
            } else {
                $newVersionId = $nextSyntheticId++;
                $idStrategy = 'synthetic_allocate';
            }
            $contentRevision = \max(1, (int)($row['content_revision'] ?? 0) ?: 1);
            $lifecycle = $isPublished || (!$isCurrent && $evidence)
                ? ThemeScopeVersion::LIFECYCLE_SEALED
                : ThemeScopeVersion::LIFECYCLE_DRAFT;
            $creationKind = ThemeVersionPublicationInterface::CREATION_CONTINUE_CURRENT;
            if (!empty($row['from_package_defaults'])) {
                $creationKind = ThemeVersionPublicationInterface::CREATION_PACKAGE_DEFAULTS;
            }

            $decisions = $this->copyDecisionsToTargetVersion(
                \is_array($row['widget_decisions'] ?? null) ? $row['widget_decisions'] : [],
                $newVersionId,
                $contentRevision,
                $legacyId > 0 ? $legacyId : null,
            );

            $mappings[] = [
                'status' => self::RECEIPT_STATUS_CONVERTIBLE,
                'legacy_version_id' => $legacyId,
                'new_theme_version_id' => $newVersionId,
                'content_revision' => $contentRevision,
                'lifecycle' => $lifecycle,
                'creation_source_kind' => $creationKind,
                'owner' => $owner->toArray(),
                'owner_hash' => $ownerHash,
                'is_published_candidate' => $isPublished,
                'is_draft_candidate' => $isCurrent && !$isPublished,
                'widget_decisions' => $decisions,
                // Synthetic allocate never copies coincident numbers; in-place retain is an explicit strategy.
                'legacy_number_reused' => false,
                'id_strategy' => $idStrategy,
            ];
        }

        $payload = [
            'receipt_id' => $receiptId,
            'mode' => 'dry-run',
            'owners' => \array_values($owners),
            'mappings' => $mappings,
            'archive_only' => $archiveOnly,
            'blocked' => $blocked,
        ];
        $payload['fingerprint'] = \hash(
            'sha256',
            \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        return $payload;
    }

    /**
     * Owners with identical theme/layout but different scope/store_mode/area/V stay isolated.
     *
     * @return array{same_layout:bool,isolated:bool}
     */
    public function compareOwnerIsolation(ThemeVersionIdentity $a, ThemeVersionIdentity $b): array
    {
        return [
            'same_layout' => true,
            'isolated' => $a->ownerHash() !== $b->ownerHash()
                || $a->themeVersionId !== $b->themeVersionId
                || $a->mode !== $b->mode
                || $a->contentRevision !== $b->contentRevision,
        ];
    }
}
