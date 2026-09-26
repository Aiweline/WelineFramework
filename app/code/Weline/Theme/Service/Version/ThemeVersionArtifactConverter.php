<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Version;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeVersionPublicationInterface;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionRevision;
use Weline\Theme\Model\ThemeScopeVersionSelection;
use Weline\Theme\Model\ThemeScopeVersionWidgetDecision;

/**
 * Offline conversion orchestration around ThemeVersionSnapshotBuilder.
 * Task 6: DB snapshot load + apply (idempotent) + already-converted annotation.
 */
final class ThemeVersionArtifactConverter
{
    public function __construct(
        private readonly ThemeVersionSnapshotBuilder $builder,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $versions
     * @param list<array<string, mixed>> $releases
     * @param list<array<string, mixed>> $intents
     * @return array<string, mixed>
     */
    public function dryRun(
        array $versions,
        array $releases = [],
        array $intents = [],
        string $receiptId = '',
    ): array {
        $report = $this->builder->dryRunConvert($versions, $releases, $intents, $receiptId);
        $report = $this->annotateAlreadyConverted($report);

        return $report;
    }

    /**
     * Apply a dry-run receipt (or recompute from the same legacy snapshot).
     * Idempotent: owners with matching selection are skipped.
     *
     * @param array<string, mixed>|null $receipt Prior dry-run report; null recomputes.
     * @param array{versions:list<array<string,mixed>>,releases:list<array<string,mixed>>,intents:list<array<string,mixed>>}|null $legacy
     * @return array<string, mixed>
     */
    public function apply(?array $receipt = null, ?array $legacy = null, string $receiptId = ''): array
    {
        $schema = $this->inspectSchemaPrerequisites();
        if (!$schema['ready']) {
            return [
                'mode' => 'apply',
                'ok' => false,
                'blocked_by_schema' => true,
                'schema' => $schema,
                'message' => 'schema_prerequisites_missing:' . \implode(',', $schema['missing']),
                'applied' => [],
                'skipped' => [],
                'pending_mappings' => 0,
            ];
        }

        if ($receipt === null) {
            $legacy ??= $this->loadLegacySnapshotFromDatabase();
            $receipt = $this->dryRun(
                $legacy['versions'],
                $legacy['releases'],
                $legacy['intents'],
                $receiptId,
            );
        }

        if (!empty($receipt['blocked'])) {
            return [
                'mode' => 'apply',
                'ok' => false,
                'blocked_by_intent' => true,
                'blocked' => $receipt['blocked'],
                'message' => 'apply_refused_while_blocked_entries_remain',
                'receipt_id' => (string)($receipt['receipt_id'] ?? ''),
                'fingerprint' => (string)($receipt['fingerprint'] ?? ''),
                'applied' => [],
                'skipped' => [],
                'pending_mappings' => \count($receipt['mappings'] ?? []),
            ];
        }

        $applied = [];
        $skipped = [];
        $byOwner = [];
        foreach ($receipt['mappings'] ?? [] as $mapping) {
            if (!\is_array($mapping)) {
                continue;
            }
            $ownerHash = (string)($mapping['owner_hash'] ?? '');
            if ($ownerHash === '') {
                continue;
            }
            $byOwner[$ownerHash]['owner'] = \is_array($mapping['owner'] ?? null) ? $mapping['owner'] : [];
            $byOwner[$ownerHash]['mappings'][] = $mapping;
        }

        foreach ($byOwner as $ownerHash => $group) {
            $owner = $group['owner'];
            $themeId = (int)($owner['theme_id'] ?? 0);
            $scope = (string)($owner['canonical_scope'] ?? $owner['scope'] ?? '');
            $storeMode = (string)($owner['store_mode'] ?? 'normal');
            $area = (string)($owner['area'] ?? 'frontend');
            if ($themeId < 1 || $scope === '') {
                continue;
            }

            $publishedId = 0;
            $draftId = null;
            $touched = [];
            foreach ($group['mappings'] as $mapping) {
                $legacyId = (int)($mapping['legacy_version_id'] ?? 0);
                $targetId = (int)($mapping['new_theme_version_id'] ?? 0);
                // In-place retain: DB row identity stays; receipt documents explicit mapping.
                if (!empty($mapping['id_strategy']) && $mapping['id_strategy'] === 'in_place_retain') {
                    $targetId = $legacyId;
                }
                if ($targetId < 1) {
                    $targetId = $legacyId;
                }
                if ($legacyId < 1) {
                    continue;
                }

                $existingSelection = $this->loadSelectionRow($themeId, $scope, $storeMode, $area);
                if ($existingSelection !== null) {
                    $selPub = (int)($existingSelection['published_version_id'] ?? 0);
                    $selDraft = $existingSelection['draft_version_id'] ?? null;
                    $selDraft = $selDraft === null || $selDraft === '' ? null : (int)$selDraft;
                    if ($selPub > 0) {
                        $skipped[] = [
                            'owner_hash' => $ownerHash,
                            'legacy_version_id' => $legacyId,
                            'reason' => 'selection_already_present',
                            'published_version_id' => $selPub,
                            'draft_version_id' => $selDraft,
                        ];
                        continue;
                    }
                }

                // Row already cut over (lifecycle+area) — idempotent no-op.
                $existingLifecycle = $this->loadVersionLifecycle($legacyId);
                if (\in_array($existingLifecycle, [ThemeScopeVersion::LIFECYCLE_DRAFT, ThemeScopeVersion::LIFECYCLE_SEALED], true)
                    && $this->versionHasCutoverArea($legacyId)
                ) {
                    $skipped[] = [
                        'owner_hash' => $ownerHash,
                        'legacy_version_id' => $legacyId,
                        'reason' => 'version_already_cutover',
                        'lifecycle' => $existingLifecycle,
                    ];
                    if (!empty($mapping['is_published_candidate'])) {
                        $publishedId = $targetId;
                    }
                    if (!empty($mapping['is_draft_candidate'])) {
                        $draftId = $targetId;
                    }
                    $touched[] = $targetId;
                    continue;
                }

                $this->updateVersionRowForCutover($legacyId, $mapping, $area);
                $this->ensureRevisionHead(
                    $targetId,
                    (int)($mapping['content_revision'] ?? 1),
                    (string)($mapping['lifecycle'] ?? ThemeScopeVersion::LIFECYCLE_SEALED),
                );
                $this->persistWidgetDecisions(
                    \is_array($mapping['widget_decisions'] ?? null) ? $mapping['widget_decisions'] : [],
                );

                if (!empty($mapping['is_published_candidate'])) {
                    $publishedId = $targetId;
                }
                if (!empty($mapping['is_draft_candidate'])) {
                    $draftId = $targetId;
                }
                $touched[] = $targetId;

                $applied[] = [
                    'owner_hash' => $ownerHash,
                    'legacy_version_id' => $legacyId,
                    'applied_theme_version_id' => $targetId,
                    'id_strategy' => (string)($mapping['id_strategy'] ?? 'in_place_retain'),
                    'lifecycle' => (string)($mapping['lifecycle'] ?? ''),
                ];
            }

            if ($publishedId < 1) {
                // Prefer first sealed mapping as published baseline when flags were sparse.
                foreach ($group['mappings'] as $mapping) {
                    if ((string)($mapping['lifecycle'] ?? '') === ThemeScopeVersion::LIFECYCLE_SEALED) {
                        $publishedId = (int)($mapping['new_theme_version_id'] ?? $mapping['legacy_version_id'] ?? 0);
                        break;
                    }
                }
            }
            // Draft-only owner cutover: promote the sole current draft to published sealed P.
            if ($publishedId < 1 && $draftId !== null && $draftId > 0) {
                $publishedId = $draftId;
                $this->forceSealVersion($publishedId);
                $draftId = null;
            }
            if ($publishedId > 0) {
                $this->upsertSelection($themeId, $scope, $storeMode, $area, $publishedId, $draftId);
            }
        }

        $result = [
            'mode' => 'apply',
            'ok' => true,
            'blocked_by_schema' => false,
            'receipt_id' => (string)($receipt['receipt_id'] ?? ''),
            'fingerprint' => (string)($receipt['fingerprint'] ?? ''),
            'applied' => $applied,
            'skipped' => $skipped,
            'archive_only_count' => \count($receipt['archive_only'] ?? []),
            'schema' => $schema,
        ];
        $result['pending_mappings'] = 0;
        $path = $this->persistReceipt($result + [
            'owners' => $receipt['owners'] ?? [],
            'mappings' => [],
            'already_converted' => \array_merge($receipt['already_converted'] ?? [], $applied),
            'archive_only' => $receipt['archive_only'] ?? [],
            'blocked' => [],
        ]);
        $result['receipt_path'] = $path;

        return $result;
    }

    /**
     * @return array{versions:list<array<string,mixed>>,releases:list<array<string,mixed>>,intents:list<array<string,mixed>>}
     */
    public function loadLegacySnapshotFromJsonFile(string $path): array
    {
        if (!\is_file($path)) {
            throw new \InvalidArgumentException('convert_input_json_missing:' . $path);
        }
        $decoded = \json_decode((string)\file_get_contents($path), true);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('convert_input_json_invalid');
        }

        return [
            'versions' => \is_array($decoded['versions'] ?? null) ? $decoded['versions'] : [],
            'releases' => \is_array($decoded['releases'] ?? null) ? $decoded['releases'] : [],
            'intents' => \is_array($decoded['intents'] ?? null) ? $decoded['intents'] : [],
        ];
    }

    /**
     * @return array{versions:list<array<string,mixed>>,releases:list<array<string,mixed>>,intents:list<array<string,mixed>>}
     */
    public function loadLegacySnapshotFromDatabase(): array
    {
        /** @var ThemeScopeVersion $versionModel */
        $versionModel = ObjectManager::getInstance(ThemeScopeVersion::class);
        $rawVersions = $versionModel->reset()->clearData()->select()->fetchArray();
        $rawVersions = \is_array($rawVersions) ? $rawVersions : [];
        if ($rawVersions !== [] && !isset($rawVersions[0]) && isset($rawVersions['version_id'])) {
            $rawVersions = [$rawVersions];
        }

        $versions = [];
        foreach ($rawVersions as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $legacyId = (int)($row['version_id'] ?? 0);
            $area = \trim((string)($row['area'] ?? ''));
            $isPublished = !empty($row['is_published']);
            $isCurrent = !empty($row['is_current']);
            $chrome = $row['chrome_payload_json'] ?? null;
            $chromeArr = [];
            if (\is_string($chrome) && $chrome !== '') {
                $decoded = \json_decode($chrome, true);
                $chromeArr = \is_array($decoded) ? $decoded : [];
            } elseif (\is_array($chrome)) {
                $chromeArr = $chrome;
            }
            $structureKey = \trim((string)($row['structure_key'] ?? ''));
            $hasChrome = $chromeArr !== [] || $structureKey !== '';
            $lifecycle = \trim((string)($row['lifecycle'] ?? ''));

            $entry = [
                'version_id' => $legacyId,
                'theme_id' => (int)($row['theme_id'] ?? 0),
                'scope' => (string)($row['scope'] ?? ''),
                'store_mode' => (string)($row['store_mode'] ?? 'normal') ?: 'normal',
                'area' => $area,
                'is_published' => $isPublished ? 1 : 0,
                'is_current' => $isCurrent ? 1 : 0,
                'lifecycle' => $lifecycle,
                'content_revision' => (int)($row['content_revision'] ?? 0),
                'has_revision_patch_evidence' => $hasChrome,
                'has_explicit_user_ops' => $this->chromeHasUserOps($chromeArr),
                'retain_version_id' => true,
                'id_strategy' => 'in_place_retain',
                'widget_decisions' => $this->extractLegacyWidgetDecisions($chromeArr, $legacyId),
            ];

            // Cutover baseline only: empty area + active flag ⇒ prove frontend consumer (not a historical multi-area claim).
            if ($area === '' && ($isPublished || $isCurrent)) {
                $entry['proven_consumer_owners'] = [
                    ['area' => 'frontend', 'proof' => 'active_is_published_or_is_current_cutover'],
                ];
            }

            $versions[] = $entry;
        }

        $releases = [];
        try {
            /** @var ThemeScopeRelease $releaseModel */
            $releaseModel = ObjectManager::getInstance(ThemeScopeRelease::class);
            $rawReleases = $releaseModel->reset()->clearData()->select()->fetchArray();
            $rawReleases = \is_array($rawReleases) ? $rawReleases : [];
            if ($rawReleases !== [] && !isset($rawReleases[0]) && isset($rawReleases['release_id'])) {
                $rawReleases = [$rawReleases];
            }
            foreach ($rawReleases as $rel) {
                if (!\is_array($rel)) {
                    continue;
                }
                $themeVersionId = (int)($rel['theme_version_id'] ?? 0);
                $batchId = \trim((string)($rel['batch_receipt_id'] ?? $rel['batch_id'] ?? ''));
                $releases[] = [
                    'release_id' => (int)($rel['release_id'] ?? 0),
                    'theme_version_id' => $themeVersionId,
                    'legacy_version_id' => $themeVersionId,
                    'identity_hash' => (string)($rel['identity_hash'] ?? ''),
                    'batch_receipt_id' => $batchId,
                    'complete_page_chrome_evidence' => $batchId !== '' || $themeVersionId > 0,
                ];
            }
        } catch (\Throwable) {
            // Release table optional for baseline conversion.
        }

        return [
            'versions' => $versions,
            'releases' => $releases,
            'intents' => [],
        ];
    }

    /**
     * @return array{ready:bool,missing:list<string>,present:list<string>}
     */
    public function inspectSchemaPrerequisites(): array
    {
        $missing = [];
        $present = [];
        $versionCols = $this->listTableColumns('theme_scope_version');
        foreach (['area', 'lifecycle', 'content_revision', 'creation_source_kind'] as $col) {
            if (\in_array($col, $versionCols, true)) {
                $present[] = 'theme_scope_version.' . $col;
            } else {
                $missing[] = 'theme_scope_version.' . $col;
            }
        }
        foreach ([
            'theme_scope_version_selection',
            'theme_scope_version_revision',
            'theme_scope_version_widget_decision',
        ] as $table) {
            if ($this->tableExists($table)) {
                $present[] = $table;
            } else {
                $missing[] = $table;
            }
        }

        return [
            'ready' => $missing === [],
            'missing' => $missing,
            'present' => $present,
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    public function persistReceipt(array $report): string
    {
        $dir = BP . '/var/runtime/theme-version-convert';
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('convert_receipt_dir_unwritable:' . $dir);
        }
        $receiptId = (string)($report['receipt_id'] ?? 'unknown');
        $safe = \preg_replace('/[^a-zA-Z0-9._-]+/', '_', $receiptId) ?: 'unknown';
        $path = $dir . '/receipt-' . $safe . '.json';
        if (\is_file($path)) {
            $existing = \json_decode((string)\file_get_contents($path), true);
            if (\is_array($existing) && ($existing['fingerprint'] ?? null) === ($report['fingerprint'] ?? null)) {
                return $path;
            }
            $path = $dir . '/receipt-' . $safe . '-' . \substr((string)($report['fingerprint'] ?? 'x'), 0, 12) . '.json';
        }
        $json = \json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false || \file_put_contents($path, $json) === false) {
            throw new \RuntimeException('convert_receipt_write_failed:' . $path);
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function annotateAlreadyConverted(array $report): array
    {
        $already = [];
        $pending = [];
        foreach ($report['mappings'] ?? [] as $mapping) {
            if (!\is_array($mapping)) {
                continue;
            }
            $owner = \is_array($mapping['owner'] ?? null) ? $mapping['owner'] : [];
            $themeId = (int)($owner['theme_id'] ?? 0);
            $scope = (string)($owner['canonical_scope'] ?? $owner['scope'] ?? '');
            $storeMode = (string)($owner['store_mode'] ?? 'normal');
            $area = (string)($owner['area'] ?? 'frontend');
            $legacyId = (int)($mapping['legacy_version_id'] ?? 0);
            $selection = null;
            try {
                $selection = $this->loadSelectionRow($themeId, $scope, $storeMode, $area);
            } catch (\Throwable) {
                $selection = null;
            }
            $lifecycle = '';
            try {
                $lifecycle = $this->loadVersionLifecycle($legacyId);
            } catch (\Throwable) {
                $lifecycle = '';
            }
            $selPub = (int)($selection['published_version_id'] ?? 0);
            $rowCutover = \in_array($lifecycle, [ThemeScopeVersion::LIFECYCLE_DRAFT, ThemeScopeVersion::LIFECYCLE_SEALED], true)
                && $this->versionHasCutoverArea($legacyId);
            // Prefer owner selection when present; otherwise row-level cutover is enough for idempotent dry-run.
            if ($rowCutover || ($selection !== null && $selPub > 0 && $rowCutover)) {
                $mapping['status'] = 'already_converted';
                $already[] = $mapping;
            } else {
                $pending[] = $mapping;
            }
        }
        $report['mappings'] = $pending;
        $report['already_converted'] = \array_merge($report['already_converted'] ?? [], $already);
        $report['pending_count'] = \count($pending);
        $report['already_converted_count'] = \count($report['already_converted']);
        // Recompute fingerprint after annotation.
        $fpPayload = $report;
        unset($fpPayload['fingerprint']);
        $report['fingerprint'] = \hash(
            'sha256',
            \json_encode($fpPayload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        return $report;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function updateVersionRowForCutover(int $legacyId, array $mapping, string $area): void
    {
        /** @var ThemeScopeVersion $model */
        $model = ObjectManager::getInstance(ThemeScopeVersion::class);
        $rows = $model->reset()->clearData()
            ->where(ThemeScopeVersion::schema_fields_ID, $legacyId)
            ->find()
            ->fetch();
        if (!$rows instanceof ThemeScopeVersion || $rows->getVersionId() < 1) {
            throw new \RuntimeException('convert_apply_version_missing:' . $legacyId);
        }
        $lifecycle = (string)($mapping['lifecycle'] ?? ThemeScopeVersion::LIFECYCLE_SEALED);
        $revision = \max(1, (int)($mapping['content_revision'] ?? 1));
        $creationKind = (string)($mapping['creation_source_kind']
            ?? ThemeVersionPublicationInterface::CREATION_CONTINUE_CURRENT);
        $rows->setArea($area !== '' ? $area : 'frontend')
            ->setLifecycle($lifecycle)
            ->setContentRevision($revision)
            ->setCreationSourceKind($creationKind);
        $source = (int)($mapping['legacy_version_id'] ?? 0);
        if ($source > 0 && method_exists($rows, 'setCreationSourceVersionId')) {
            $rows->setCreationSourceVersionId($source);
        }
        $rows->save();
    }

    private function ensureRevisionHead(int $themeVersionId, int $contentRevision, string $lifecycle): void
    {
        if ($themeVersionId < 1 || $contentRevision < 1) {
            return;
        }
        /** @var ThemeScopeVersionRevision $model */
        $model = ObjectManager::getInstance(ThemeScopeVersionRevision::class);
        $existing = $model->reset()->clearData()
            ->where(ThemeScopeVersionRevision::schema_fields_THEME_VERSION_ID, $themeVersionId)
            ->where(ThemeScopeVersionRevision::schema_fields_CONTENT_REVISION, $contentRevision)
            ->find()
            ->fetch();
        if ($existing instanceof ThemeScopeVersionRevision && $existing->getId() > 0) {
            return;
        }
        $row = clone $model;
        $row->reset()->clearData()
            ->setData(ThemeScopeVersionRevision::schema_fields_THEME_VERSION_ID, $themeVersionId)
            ->setData(ThemeScopeVersionRevision::schema_fields_CONTENT_REVISION, $contentRevision)
            ->setData(ThemeScopeVersionRevision::schema_fields_BASE_VERSION_ID, $themeVersionId)
            ->setData(
                ThemeScopeVersionRevision::schema_fields_KIND,
                $lifecycle === ThemeScopeVersion::LIFECYCLE_SEALED
                    ? ThemeScopeVersionRevision::KIND_SEALED
                    : ThemeScopeVersionRevision::KIND_DRAFT,
            )
            ->setData(ThemeScopeVersionRevision::schema_fields_ACTOR_ID, 'convert-version-artifacts')
            ->setData(ThemeScopeVersionRevision::schema_fields_MANIFEST_DIGEST, '')
            ->save();
    }

    /**
     * @param list<array<string, mixed>> $decisions
     */
    private function persistWidgetDecisions(array $decisions): void
    {
        if ($decisions === []) {
            return;
        }
        /** @var ThemeScopeVersionWidgetDecision $model */
        $model = ObjectManager::getInstance(ThemeScopeVersionWidgetDecision::class);
        foreach ($decisions as $decision) {
            if (!\is_array($decision)) {
                continue;
            }
            $themeVersionId = (int)($decision['theme_version_id'] ?? 0);
            $revision = (int)($decision['content_revision'] ?? 0);
            $resourceHash = \trim((string)($decision['resource_identity_hash'] ?? ''));
            $injectionKey = \trim((string)($decision['injection_key'] ?? ''));
            if ($themeVersionId < 1 || $revision < 1 || $resourceHash === '' || $injectionKey === '') {
                continue;
            }
            $existing = $model->reset()->clearData()
                ->where(ThemeScopeVersionWidgetDecision::schema_fields_THEME_VERSION_ID, $themeVersionId)
                ->where(ThemeScopeVersionWidgetDecision::schema_fields_CONTENT_REVISION, $revision)
                ->where(ThemeScopeVersionWidgetDecision::schema_fields_RESOURCE_IDENTITY_HASH, $resourceHash)
                ->where(ThemeScopeVersionWidgetDecision::schema_fields_INJECTION_KEY, $injectionKey)
                ->find()
                ->fetch();
            if ($existing instanceof ThemeScopeVersionWidgetDecision && $existing->getId() > 0) {
                continue;
            }
            $row = clone $model;
            $row->reset()->clearData()
                ->setData(ThemeScopeVersionWidgetDecision::schema_fields_THEME_VERSION_ID, $themeVersionId)
                ->setData(ThemeScopeVersionWidgetDecision::schema_fields_CONTENT_REVISION, $revision)
                ->setData(ThemeScopeVersionWidgetDecision::schema_fields_RESOURCE_IDENTITY_HASH, $resourceHash)
                ->setData(ThemeScopeVersionWidgetDecision::schema_fields_INJECTION_KEY, $injectionKey)
                ->setData(
                    ThemeScopeVersionWidgetDecision::schema_fields_DECISION,
                    (string)($decision['decision'] ?? ThemeScopeVersionWidgetDecision::DECISION_UNINSTALL),
                )
                ->setData(ThemeScopeVersionWidgetDecision::schema_fields_SLOT_IDENTITY, (string)($decision['slot_identity'] ?? ''))
                ->setData(ThemeScopeVersionWidgetDecision::schema_fields_WIDGET_IDENTITY, (string)($decision['widget_identity'] ?? ''))
                ->setData(ThemeScopeVersionWidgetDecision::schema_fields_ACTOR_ID, (string)($decision['actor_id'] ?? 'convert'))
                ->setData(
                    ThemeScopeVersionWidgetDecision::schema_fields_SOURCE_VERSION_ID,
                    isset($decision['source_version_id']) ? (int)$decision['source_version_id'] : null,
                )
                ->save();
        }
    }

    private function upsertSelection(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
        int $publishedId,
        ?int $draftId,
    ): void {
        /** @var ThemeScopeVersionSelection $model */
        $model = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
        $existing = $model->reset()->clearData()
            ->where(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersionSelection::schema_fields_SCOPE, $scope)
            ->where(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode)
            ->where(ThemeScopeVersionSelection::schema_fields_AREA, $area)
            ->find()
            ->fetch();
        if ($existing instanceof ThemeScopeVersionSelection && $existing->getSelectionId() > 0) {
            $existing->setData(ThemeScopeVersionSelection::schema_fields_PUBLISHED_VERSION_ID, $publishedId);
            $existing->setData(ThemeScopeVersionSelection::schema_fields_DRAFT_VERSION_ID, $draftId);
            $existing->setData(
                ThemeScopeVersionSelection::schema_fields_SELECTION_REVISION,
                $existing->getSelectionRevision() + 1,
            );
            $existing->save();

            return;
        }
        $row = clone $model;
        $row->reset()->clearData()
            ->setData(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId)
            ->setData(ThemeScopeVersionSelection::schema_fields_SCOPE, $scope)
            ->setData(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode)
            ->setData(ThemeScopeVersionSelection::schema_fields_AREA, $area)
            ->setData(ThemeScopeVersionSelection::schema_fields_PUBLISHED_VERSION_ID, $publishedId)
            ->setData(ThemeScopeVersionSelection::schema_fields_DRAFT_VERSION_ID, $draftId)
            ->setData(ThemeScopeVersionSelection::schema_fields_SELECTION_REVISION, 1)
            ->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSelectionRow(int $themeId, string $scope, string $storeMode, string $area): ?array
    {
        if ($themeId < 1 || $scope === '' || !$this->tableExists('theme_scope_version_selection')) {
            return null;
        }
        /** @var ThemeScopeVersionSelection $model */
        $model = ObjectManager::getInstance(ThemeScopeVersionSelection::class);
        $row = $model->reset()->clearData()
            ->where(ThemeScopeVersionSelection::schema_fields_THEME_ID, $themeId)
            ->where(ThemeScopeVersionSelection::schema_fields_SCOPE, $scope)
            ->where(ThemeScopeVersionSelection::schema_fields_STORE_MODE, $storeMode)
            ->where(ThemeScopeVersionSelection::schema_fields_AREA, $area)
            ->find()
            ->fetchArray();
        if (!\is_array($row) || $row === []) {
            return null;
        }
        if (!isset($row['published_version_id']) && isset($row[0]) && \is_array($row[0])) {
            $row = $row[0];
        }

        return isset($row['published_version_id']) ? $row : null;
    }

    private function loadVersionLifecycle(int $versionId): string
    {
        if ($versionId < 1 || !\in_array('lifecycle', $this->listTableColumns('theme_scope_version'), true)) {
            return '';
        }
        /** @var ThemeScopeVersion $model */
        $model = ObjectManager::getInstance(ThemeScopeVersion::class);
        $row = $model->reset()->clearData()
            ->where(ThemeScopeVersion::schema_fields_ID, $versionId)
            ->find()
            ->fetchArray();
        if (!\is_array($row) || $row === []) {
            return '';
        }
        if (!isset($row['lifecycle']) && isset($row[0]) && \is_array($row[0])) {
            $row = $row[0];
        }

        return \trim((string)($row['lifecycle'] ?? ''));
    }

    private function versionHasCutoverArea(int $versionId): bool
    {
        if ($versionId < 1 || !\in_array('area', $this->listTableColumns('theme_scope_version'), true)) {
            return false;
        }
        /** @var ThemeScopeVersion $model */
        $model = ObjectManager::getInstance(ThemeScopeVersion::class);
        $row = $model->reset()->clearData()
            ->where(ThemeScopeVersion::schema_fields_ID, $versionId)
            ->find()
            ->fetchArray();
        if (!\is_array($row) || $row === []) {
            return false;
        }
        if (!isset($row['area']) && isset($row[0]) && \is_array($row[0])) {
            $row = $row[0];
        }
        $area = \trim((string)($row['area'] ?? ''));

        return \in_array($area, ['frontend', 'backend'], true);
    }

    private function forceSealVersion(int $versionId): void
    {
        if ($versionId < 1) {
            return;
        }
        /** @var ThemeScopeVersion $model */
        $model = ObjectManager::getInstance(ThemeScopeVersion::class);
        $row = $model->reset()->clearData()
            ->where(ThemeScopeVersion::schema_fields_ID, $versionId)
            ->find()
            ->fetch();
        if (!$row instanceof ThemeScopeVersion || $row->getVersionId() < 1) {
            return;
        }
        if ($row->getLifecycle() !== ThemeScopeVersion::LIFECYCLE_SEALED) {
            $row->setLifecycle(ThemeScopeVersion::LIFECYCLE_SEALED);
            if ($row->getContentRevision() < 1) {
                $row->setContentRevision(1);
            }
            $row->save();
        }
        $this->ensureRevisionHead($versionId, \max(1, $row->getContentRevision()), ThemeScopeVersion::LIFECYCLE_SEALED);
    }

    /**
     * @param array<string, mixed> $chrome
     */
    private function chromeHasUserOps(array $chrome): bool
    {
        $json = \json_encode($chrome) ?: '';

        return \str_contains($json, 'user_deleted')
            || \str_contains($json, '"source":"user"')
            || \str_contains($json, '"_source":"user"');
    }

    /**
     * @param array<string, mixed> $chrome
     * @return list<array<string, mixed>>
     */
    private function extractLegacyWidgetDecisions(array $chrome, int $legacyVersionId): array
    {
        $out = [];
        $json = \json_encode($chrome) ?: '';
        if ($json === '' || !\str_contains($json, 'user_deleted')) {
            return $out;
        }
        // Best-effort: scan nested arrays for source=user_deleted@*
        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($chrome));
        foreach ($iterator as $key => $value) {
            if (!\is_string($value)) {
                continue;
            }
            if (!\str_starts_with($value, 'user_deleted@') && $value !== 'user_deleted') {
                continue;
            }
            $path = [];
            foreach (\range(0, $iterator->getDepth()) as $depth) {
                $path[] = $iterator->getSubIterator($depth)->key();
            }
            $injectionKey = \is_string($key) ? $key : \implode('.', \array_map('strval', $path));
            $out[] = [
                'resource_identity_hash' => \hash('sha256', 'chrome'),
                'injection_key' => $injectionKey !== '' ? $injectionKey : ('legacy_deleted_' . \count($out)),
                'decision' => ThemeScopeVersionWidgetDecision::DECISION_UNINSTALL,
                'slot_identity' => '',
                'widget_identity' => '',
                'actor_id' => 'convert-legacy',
                'theme_version_id' => $legacyVersionId,
            ];
        }

        return $out;
    }

    private function tableExists(string $logicalTable): bool
    {
        $physical = $this->physicalTableName($logicalTable);
        try {
            $pdo = $this->pdo();
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_name = :t LIMIT 1'
            );
            $stmt->execute(['t' => $physical]);

            return (bool)$stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function listTableColumns(string $logicalTable): array
    {
        $physical = $this->physicalTableName($logicalTable);
        try {
            $pdo = $this->pdo();
            $stmt = $pdo->prepare(
                'SELECT column_name FROM information_schema.columns WHERE table_name = :t'
            );
            $stmt->execute(['t' => $physical]);
            $cols = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            return \is_array($cols) ? \array_map('strval', $cols) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function pdo(): \PDO
    {
        /** @var ThemeScopeVersion $probe */
        $probe = ObjectManager::getInstance(ThemeScopeVersion::class);
        $connector = $probe->getConnection()->getConnector();
        if (\method_exists($connector, 'getLink')) {
            $link = $connector->getLink();
            if ($link instanceof \PDO) {
                return $link;
            }
        }
        throw new \RuntimeException('convert_schema_probe_pdo_unavailable');
    }

    private function physicalTableName(string $logicalTable): string
    {
        $logicalTable = \trim($logicalTable);
        if (\str_starts_with($logicalTable, 'w_')) {
            return $logicalTable;
        }

        return 'w_' . $logicalTable;
    }
}
