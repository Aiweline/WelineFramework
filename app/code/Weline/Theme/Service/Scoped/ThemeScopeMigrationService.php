<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\I18n\Api\Translation\DictionaryEntry;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\Meta\Model\MetaConfig;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeLegacyIdentityMapperInterface;
use Weline\Theme\Api\Scoped\ThemeLegacyIdentityMapping;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
use Weline\Theme\Service\ThemeTargetTypeRegistry;

/** Idempotent 2.1.1 preflight and legacy snapshot backfill. */
final class ThemeScopeMigrationService
{
    private const ACTOR = 'system:theme-scope-migration-2.1.1';

    /** @var array<string,ThemeLegacyIdentityMapping|null> */
    private array $legacyIdentityMappingCache = [];

    public function __construct(
        private readonly ThemeLayout $layouts,
        private readonly ThemeLayoutVersion $versions,
        private readonly ThemeVirtualLayout $virtualLayouts,
        private readonly ThemeWidgetDefaultInjection $injections,
        private readonly WelineTheme $themes,
        private readonly MetaConfig $metaConfigs,
        private readonly DictionaryRepositoryInterface $dictionary,
        private readonly ThemeLayoutScopeNormalizer $scopeNormalizer,
        private readonly ScopeHierarchyInterface $scopes,
        private readonly ScopeIdentityCatalogInterface $catalog,
        private readonly ThemeScopedResourceAdapterInterface $adapter,
        private readonly ThemeScopedWorkspaceInterface $workspaces,
        private readonly ThemeLayoutPayloadDiffer $layoutDiffer,
        private readonly ThemeResourcePayloadDiffer $resourceDiffer,
        private readonly ThemeTargetTypeRegistry $targetTypes,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    /** @return array<string,mixed> */
    public function preflight(): array
    {
        $rows = $this->layoutRows();
        $rewriteCollisions = [];
        $duplicateNodeUids = [];
        $opaqueScopes = [];
        $migratableLegacyScopes = [];
        $unresolvedCatalogScopes = [];
        $ambiguousNodes = [];
        $components = [];

        foreach ($rows as $row) {
            $scope = (string)($row[ThemeLayout::schema_fields_SCOPE] ?? 'default');
            $plannedRow = $this->rewriteTargetAwareRow(
                $row,
                ThemeLayout::schema_fields_SCOPE,
                ThemeLayout::schema_fields_TARGET_TYPE,
                ThemeLayout::schema_fields_TARGET_ID,
            );
            $rewritten = (string)$plannedRow[ThemeLayout::schema_fields_SCOPE];
            $plannedWritable = $this->isOpaqueScope($rewritten)
                || $this->canWriteMigratedScope($rewritten);
            if (!$this->isOpaqueScope($rewritten) && !$plannedWritable) {
                $unresolvedCatalogScopes[$scope] = $rewritten;
                $plannedRow = $row;
                $rewritten = $scope;
            }
            if ($this->isOpaqueScope($scope) && $this->rowIdentityChanged(
                $row,
                $plannedRow,
                ThemeLayout::schema_fields_SCOPE,
                ThemeLayout::schema_fields_TARGET_TYPE,
                ThemeLayout::schema_fields_TARGET_ID,
            )) {
                $migratableLegacyScopes[$scope] = true;
            } elseif ($this->isOpaqueScope($scope)) {
                $opaqueScopes[$scope] = true;
            }
            if ($this->validUid($row[ThemeLayout::schema_fields_NODE_UID] ?? null) !== null) {
                continue;
            }
            $config = $this->decodeConfig($row[ThemeLayout::schema_fields_CONFIG] ?? []);
            $instance = trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? ''));
            if ($instance === '') {
                $components[$this->componentIdentityKey($plannedRow, $rewritten)][] = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
            }
        }

        foreach ($components as $ids) {
            if (count($ids) > 1) {
                $ambiguousNodes[] = ['layout_ids' => $ids];
            }
        }

        // Predict the post-rewrite/post-backfill identity before mutating any
        // row. This catches collisions involving missing UIDs as well as two
        // legacy rows that both converge on the same canonical Scope.
        $planned = [];
        foreach ($rows as $row) {
            $id = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
            $scope = (string)($row[ThemeLayout::schema_fields_SCOPE] ?? 'default');
            $plannedRow = $this->rewriteTargetAwareRow(
                $row,
                ThemeLayout::schema_fields_SCOPE,
                ThemeLayout::schema_fields_TARGET_TYPE,
                ThemeLayout::schema_fields_TARGET_ID,
            );
            $rewritten = (string)$plannedRow[ThemeLayout::schema_fields_SCOPE];
            if (!$this->isOpaqueScope($rewritten) && !$this->canWriteMigratedScope($rewritten)) {
                $plannedRow = $row;
                $rewritten = $scope;
            }
            $identityChanged = $this->rowIdentityChanged(
                $row,
                $plannedRow,
                ThemeLayout::schema_fields_SCOPE,
                ThemeLayout::schema_fields_TARGET_TYPE,
                ThemeLayout::schema_fields_TARGET_ID,
            );
            $uid = $this->validUid($row[ThemeLayout::schema_fields_NODE_UID] ?? null);
            if ($uid === null) {
                $config = $this->decodeConfig($row[ThemeLayout::schema_fields_CONFIG] ?? []);
                $instance = trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? ''));
                if ($instance !== '') {
                    $uid = md5('theme-node|i18n|' . $this->stableComponentIdentity($plannedRow) . '|' . $instance);
                } elseif (count($components[$this->componentIdentityKey($plannedRow, $rewritten)] ?? []) === 1) {
                    $uid = md5('theme-node|component|' . $this->stableComponentIdentity($plannedRow));
                } else {
                    $uid = md5('theme-node|ambiguous-row|' . $id);
                }
            }
            $key = $this->layoutIdentityKey($plannedRow, $rewritten, $uid);
            $other = $planned[$key] ?? null;
            if (is_array($other) && (int)$other['id'] !== $id) {
                $duplicateNodeUids[] = [
                    'layout_id' => $id,
                    'other_layout_id' => (int)$other['id'],
                    'node_uid' => $uid,
                ];
                if ($identityChanged || (bool)$other['rewritten']) {
                    $rewriteCollisions[] = [
                        'layout_id' => $id,
                        'other_layout_id' => (int)$other['id'],
                        'scope' => $scope,
                        'target_scope' => $rewritten,
                    ];
                }
                continue;
            }
            $planned[$key] = [
                'id' => $id,
                'rewritten' => $identityChanged,
            ];
        }

        $relatedCollisions = $this->relatedScopeRewriteCollisions();
        $rewriteCollisions = array_merge($rewriteCollisions, $relatedCollisions);
        $dictionaryCollisions = $this->dictionaryScopeRewriteCollisions();
        $rewriteCollisions = array_merge($rewriteCollisions, $dictionaryCollisions);
        $ok = $rewriteCollisions === [] && $duplicateNodeUids === [];

        return [
            'ok' => $ok,
            'layout_rows' => count($rows),
            'scope_rewrite_collisions' => count($rewriteCollisions),
            'duplicate_node_uids' => count($duplicateNodeUids),
            'ambiguous_node_groups' => count($ambiguousNodes),
            'migratable_legacy_scopes' => array_keys($migratableLegacyScopes),
            'opaque_compatibility_scopes' => array_keys($opaqueScopes),
            'unresolved_catalog_scopes' => $unresolvedCatalogScopes,
            'samples' => [
                'scope_rewrite_collisions' => array_slice($rewriteCollisions, 0, 100),
                'duplicate_node_uids' => array_slice($duplicateNodeUids, 0, 100),
                'ambiguous_node_groups' => array_slice($ambiguousNodes, 0, 100),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function apply(): array
    {
        $preflight = $this->preflight();
        if (($preflight['ok'] ?? false) !== true) {
            throw new \RuntimeException('theme_scope_migration_preflight_blocked:' . json_encode(
                $preflight['samples'] ?? [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }

        return $this->transactions->runWrite(
            $this->layouts->getConnection(),
            function () use ($preflight): array {
                $rewritten = $this->rewriteLegacyScopes();
                $rewrittenDictionary = $this->rewriteDictionaryScopes();
                $nodes = $this->backfillNodeUids();
                $bindings = $this->seedGlobalThemeBindings();
                [$layoutContexts, $contextWarnings] = $this->legacyLayoutContexts();
                $layouts = $this->migratePublishedLayouts($layoutContexts, $contextWarnings);
                $resources = $this->migrateRelatedResources($layoutContexts);

                return [
                    'ok' => true,
                    'preflight' => $preflight,
                    'rewritten_scopes' => $rewritten,
                    'rewritten_dictionary_scopes' => $rewrittenDictionary,
                    'node_uid_backfill' => $nodes,
                    'global_theme_bindings' => $bindings,
                    'layout_workspaces' => $layouts,
                    'resource_workspaces' => $resources,
                ];
            },
        );
    }

    /** @return array{updated:int,ambiguous:int} */
    private function backfillNodeUids(): array
    {
        $rows = $this->layoutRows();
        $componentCounts = [];
        foreach ($rows as $row) {
            if ($this->validUid($row[ThemeLayout::schema_fields_NODE_UID] ?? null) !== null) {
                continue;
            }
            $config = $this->decodeConfig($row[ThemeLayout::schema_fields_CONFIG] ?? []);
            if (trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? '')) === '') {
                $componentCounts[$this->componentIdentityKey($row)] = ($componentCounts[$this->componentIdentityKey($row)] ?? 0) + 1;
            }
        }

        $updated = 0;
        $ambiguous = 0;
        foreach ($rows as $row) {
            $id = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $uid = $this->validUid($row[ThemeLayout::schema_fields_NODE_UID] ?? null);
            $config = $this->decodeConfig($row[ThemeLayout::schema_fields_CONFIG] ?? []);
            $instance = trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? ''));
            if ($uid === null) {
                if ($instance !== '') {
                    $uid = md5('theme-node|i18n|' . $this->stableComponentIdentity($row) . '|' . $instance);
                } elseif (($componentCounts[$this->componentIdentityKey($row)] ?? 0) === 1) {
                    $uid = md5('theme-node|component|' . $this->stableComponentIdentity($row));
                } else {
                    // Ambiguous legacy siblings stay intact as a local subtree;
                    // a row-backed UID deliberately avoids guessing a parent match.
                    $uid = md5('theme-node|ambiguous-row|' . $id);
                    $ambiguous++;
                }
            }
            if ($instance === '') {
                $config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] = 'wi_' . $uid;
            }
            if ((string)($row[ThemeLayout::schema_fields_NODE_UID] ?? '') === $uid
                && $this->decodeConfig($row[ThemeLayout::schema_fields_CONFIG] ?? []) === $config
            ) {
                continue;
            }
            $layout = clone $this->layouts;
            $layout->clearData();
            $layout->clearQuery();
            $layout->load($id);
            $layout->setNodeUid($uid)->setWidgetConfig($config)->save();
            $updated++;
        }

        return ['updated' => $updated, 'ambiguous' => $ambiguous];
    }

    /** @return array<string,int> */
    private function seedGlobalThemeBindings(): array
    {
        $seeded = ['frontend' => 0, 'backend' => 0];
        $global = $this->scopes->contextFromIdentity(ScopeIdentity::global());
        foreach (array_keys($seeded) as $area) {
            $theme = clone $this->themes;
            try {
                $theme->clearData();
                $theme->clearQuery();
                $theme->getActiveTheme($area);
            } catch (\Throwable) {
                continue;
            }
            $themeId = (int)$theme->getId();
            if ($themeId <= 0) {
                continue;
            }
            $context = new ThemeEditorContext(
                scope: $global,
                area: $area,
                resourceType: ThemeEditorContext::RESOURCE_THEME_BINDING,
            );
            $state = $this->workspaces->load($context, true);
            if ((int)($state['published_release_id'] ?? 0) > 0) {
                continue;
            }
            if ((int)($state['draft_revision_id'] ?? 0) > 0) {
                continue;
            }
            $draft = $this->workspaces->applyChanges(
                $context,
                (int)($state['revision'] ?? 0),
                $this->nullableId($state['expected_parent_release_id'] ?? null),
                [ThemePatchCommand::fromArray(['op' => 'set', 'path' => '/theme_id', 'value' => $themeId])],
                self::ACTOR,
                'Theme 2.1.1 migration',
                'Backfill active Global Theme binding',
            );
            $this->workspaces->publish(
                $context,
                (int)$draft['revision'],
                $this->nullableId($draft['expected_parent_release_id'] ?? $state['expected_parent_release_id'] ?? null),
                self::ACTOR,
                'Theme 2.1.1 migration',
                'Backfill active Global Theme binding',
            );
            $seeded[$area] = 1;
        }

        return $seeded;
    }

    /** @return array{0:list<ThemeEditorContext>,1:list<string>} */
    private function legacyLayoutContexts(): array
    {
        $rows = array_values(array_filter(
            $this->layoutRows(),
            static fn(array $row): bool => (string)($row[ThemeLayout::schema_fields_STATUS] ?? '') === ThemeLayout::STATUS_PUBLISHED,
        ));
        $groups = [];
        foreach ($rows as $row) {
            $scope = (string)($row[ThemeLayout::schema_fields_SCOPE] ?? 'default');
            if ($this->isOpaqueScope($scope)) {
                continue;
            }
            $editorArea = $this->legacyEditorArea($row);
            $key = implode("\0", [
                $editorArea,
                (string)($row[ThemeLayout::schema_fields_THEME_ID] ?? 0),
                (string)($row[ThemeLayout::schema_fields_PAGE_TYPE] ?? 'default'),
                (string)($row[ThemeLayout::schema_fields_LAYOUT_OPTION] ?? 'default'),
                $scope,
                (string)($row[ThemeLayout::schema_fields_LOCALE_CODE] ?? ''),
                (string)($row[ThemeLayout::schema_fields_TARGET_TYPE] ?? 'global'),
                (string)($row[ThemeLayout::schema_fields_TARGET_ID] ?? 0),
            ]);
            $groups[$key] = $row;
        }

        $contexts = [];
        $warnings = array_filter($groups, fn(array $row): bool => $this->legacyEditorArea($row) === 'frontend') === []
            ? []
            : ['legacy_non_dashboard_theme_layout_area_assumed_frontend'];
        foreach ($groups as $row) {
            try {
                $scope = $this->scopeContextForLegacy((string)$row[ThemeLayout::schema_fields_SCOPE]);
            } catch (\Throwable $e) {
                $warnings[] = $e->getMessage();
                continue;
            }
            if (!$scope instanceof ScopeContext) {
                continue;
            }
            $contexts[] = new ThemeEditorContext(
                scope: $scope,
                area: $this->legacyEditorArea($row),
                resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
                themeId: (int)$row[ThemeLayout::schema_fields_THEME_ID],
                layoutType: (string)$row[ThemeLayout::schema_fields_PAGE_TYPE],
                layoutOption: (string)$row[ThemeLayout::schema_fields_LAYOUT_OPTION],
                locale: trim((string)($row[ThemeLayout::schema_fields_LOCALE_CODE] ?? '')) ?: 'default',
                targetType: (string)($row[ThemeLayout::schema_fields_TARGET_TYPE] ?? 'global'),
                targetId: (int)($row[ThemeLayout::schema_fields_TARGET_ID] ?? 0),
            );
        }
        $contexts = $this->uniqueSortedContexts($contexts);

        return [$contexts, $warnings];
    }

    /**
     * @param list<ThemeEditorContext> $contexts
     * @param list<string> $warnings
     * @return array{created:int,inherited:int,skipped:int,warnings:list<string>}
     */
    private function migratePublishedLayouts(array $contexts, array $warnings): array
    {
        $created = 0;
        $inherited = 0;
        $skipped = 0;
        foreach ($contexts as $context) {
            $state = $this->workspaces->load($context, true);
            if ((int)($state['published_release_id'] ?? 0) > 0 || (int)($state['draft_revision_id'] ?? 0) > 0) {
                $skipped++;
                continue;
            }
            $target = $this->adapter->loadLegacyPublished($context);
            // Every legacy snapshot is compared with its actual effective parent;
            // Global therefore compares with the Theme package default. This is
            // the only way unchanged paths remain inherited after migration.
            $parent = is_array($state['published_payload'] ?? null) ? $state['published_payload'] : [];
            $commands = $this->layoutDiffer->diff($parent, $target);
            if ($commands === []) {
                $inherited++;
                continue;
            }
            $draft = $this->workspaces->applyChanges(
                $context,
                (int)($state['revision'] ?? 0),
                $this->nullableId($state['expected_parent_release_id'] ?? null),
                $commands,
                self::ACTOR,
                'Theme 2.1.1 migration',
                'Semantic legacy layout diff',
            );
            $this->workspaces->publish(
                $context,
                (int)$draft['revision'],
                $this->nullableId($draft['expected_parent_release_id'] ?? $state['expected_parent_release_id'] ?? null),
                self::ACTOR,
                'Theme 2.1.1 migration',
                'Semantic legacy layout diff',
            );
            $created++;
        }

        return compact('created', 'inherited', 'skipped', 'warnings');
    }

    /**
     * @param list<ThemeEditorContext> $layoutContexts
     * @return array<string,mixed>
     */
    private function migrateRelatedResources(array $layoutContexts): array
    {
        $contexts = [];
        foreach ($layoutContexts as $layoutContext) {
            $contexts[] = $layoutContext->withResource(ThemeEditorContext::RESOURCE_META);
            $contexts[] = $layoutContext->withResource(ThemeEditorContext::RESOURCE_APPEARANCE);
            if ($layoutContext->locale !== 'default') {
                $contexts[] = $layoutContext->withResource(ThemeEditorContext::RESOURCE_I18N);
            }
        }

        [$metaContexts, $warnings] = $this->contextsFromMetaRows();
        $contexts = array_merge($contexts, $metaContexts);
        [$i18nContexts, $i18nWarnings] = $this->contextsFromDictionary($layoutContexts, $metaContexts);
        $contexts = array_merge($contexts, $i18nContexts);
        $warnings = array_merge($warnings, $i18nWarnings);
        $contexts = array_values(array_filter(
            $this->uniqueSortedContexts($contexts),
            static fn(ThemeEditorContext $context): bool => $context->resourceType !== ThemeEditorContext::RESOURCE_THEME_BINDING,
        ));

        $summary = [];
        foreach (ThemeEditorContext::RESOURCES as $resourceType) {
            if ($resourceType === ThemeEditorContext::RESOURCE_THEME_BINDING) {
                continue;
            }
            $summary[$resourceType] = ['created' => 0, 'inherited' => 0, 'skipped' => 0];
        }

        foreach ($contexts as $context) {
            $bucket = $context->resourceType;
            $state = $this->workspaces->load($context, true);
            if ((int)($state['published_release_id'] ?? 0) > 0
                || (int)($state['draft_revision_id'] ?? 0) > 0
            ) {
                $summary[$bucket]['skipped']++;
                continue;
            }

            $target = $this->adapter->loadLegacyPublished($context);
            $parent = is_array($state['published_payload'] ?? null) ? $state['published_payload'] : [];
            $commands = $this->resourceDiffer->diff($context, $parent, $target);
            if ($commands === []) {
                $summary[$bucket]['inherited']++;
                continue;
            }

            $draft = $this->workspaces->applyChanges(
                $context,
                (int)($state['revision'] ?? 0),
                $this->nullableId($state['expected_parent_release_id'] ?? null),
                $commands,
                self::ACTOR,
                'Theme 2.1.1 migration',
                'Semantic legacy ' . $bucket . ' diff',
            );
            $this->workspaces->publish(
                $context,
                (int)$draft['revision'],
                $this->nullableId($draft['expected_parent_release_id'] ?? $state['expected_parent_release_id'] ?? null),
                self::ACTOR,
                'Theme 2.1.1 migration',
                'Semantic legacy ' . $bucket . ' diff',
            );
            $summary[$bucket]['created']++;
        }

        $summary['warnings'] = array_values(array_unique($warnings));

        return $summary;
    }

    /** @return array{0:list<ThemeEditorContext>,1:list<string>} */
    private function contextsFromMetaRows(): array
    {
        $contexts = [];
        $warnings = [];
        foreach ($this->metaRows() as $row) {
            $namespace = (string)($row[MetaConfig::schema_fields_NAMESPACE] ?? '');
            if (preg_match('/^theme\.(frontend|backend)$/D', $namespace, $areaMatch) !== 1) {
                continue;
            }
            $themeId = (int)($row[MetaConfig::schema_fields_IDENTIFY_ID] ?? 0);
            if ($themeId <= 0) {
                continue;
            }
            $configKey = (string)($row[MetaConfig::schema_fields_CONFIG_KEY] ?? '');
            $scopeRaw = (string)($row[MetaConfig::schema_fields_SCOPE] ?? 'default');
            try {
                $scope = $this->scopeContextForLegacy($scopeRaw);
                if (!$scope instanceof ScopeContext) {
                    continue;
                }
                $base = [
                    'scope' => $scope,
                    'area' => $areaMatch[1],
                    'theme_id' => $themeId,
                ];
                if (str_starts_with($configKey, 'disk_active.')
                    || str_starts_with($configKey, 'disk_custom.')
                ) {
                    $contexts[] = new ThemeEditorContext(
                        scope: $base['scope'],
                        area: $base['area'],
                        resourceType: ThemeEditorContext::RESOURCE_APPEARANCE,
                        themeId: $base['theme_id'],
                    );
                    continue;
                }
                if (preg_match('/^layouts\.([a-zA-Z0-9_.:@-]+)\.value$/D', $configKey, $selection) === 1) {
                    $contexts[] = new ThemeEditorContext(
                        scope: $base['scope'],
                        area: $base['area'],
                        resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
                        themeId: $base['theme_id'],
                        layoutType: $selection[1],
                        layoutOption: 'default',
                    );
                    continue;
                }
                $identity = $this->parseMetaParamContext($configKey);
                if ($identity === null) {
                    continue;
                }
                $contexts[] = new ThemeEditorContext(
                    scope: $base['scope'],
                    area: $base['area'],
                    resourceType: ThemeEditorContext::RESOURCE_META,
                    themeId: $base['theme_id'],
                    layoutType: $identity['layout_type'],
                    layoutOption: $identity['layout_option'],
                    targetType: $identity['target_type'],
                    targetId: $identity['target_id'],
                );
            } catch (\Throwable $e) {
                $warnings[] = 'meta_config_id=' . (int)($row[MetaConfig::schema_fields_ID] ?? 0)
                    . ':' . $e->getMessage();
            }
        }

        return [$this->uniqueSortedContexts($contexts), $warnings];
    }

    /**
     * @param list<ThemeEditorContext> $layoutContexts
     * @param list<ThemeEditorContext> $metaContexts
     * @return array{0:list<ThemeEditorContext>,1:list<string>}
     */
    private function contextsFromDictionary(array $layoutContexts, array $metaContexts): array
    {
        $signatures = [];
        foreach (array_merge($layoutContexts, $metaContexts) as $context) {
            if (!in_array($context->resourceType, [
                ThemeEditorContext::RESOURCE_LAYOUT,
                ThemeEditorContext::RESOURCE_META,
            ], true)) {
                continue;
            }
            $key = implode("\0", [
                (string)$context->themeId,
                $context->area,
                $context->layoutType,
                $context->layoutOption,
                $context->targetType,
                (string)$context->targetId,
            ]);
            $signatures[$key] = $context;
        }

        $contexts = [];
        $warnings = [];
        $scopeLocales = [];
        foreach ($this->dictionary->listByWordPrefix('@meta::theme.') as $entry) {
            if (!$entry instanceof DictionaryEntry
                || preg_match('/^@meta::theme\.(frontend|backend)\./D', $entry->word, $areaMatch) !== 1
            ) {
                continue;
            }
            $scopeRaw = 'default';
            $scopePosition = strrpos($entry->word, '|scope:');
            if ($scopePosition !== false) {
                $scopeRaw = substr($entry->word, $scopePosition + strlen('|scope:'));
            }
            try {
                $scope = $this->scopeContextForLegacy($scopeRaw);
                if (!$scope instanceof ScopeContext) {
                    continue;
                }
                $key = $areaMatch[1] . "\0" . $scope->canonicalKey() . "\0" . $entry->localeCode;
                $scopeLocales[$key] = [
                    'area' => $areaMatch[1],
                    'scope' => $scope,
                    'locale' => $entry->localeCode,
                ];
            } catch (\Throwable $e) {
                $warnings[] = 'dictionary_scope:' . $scopeRaw . ':' . $e->getMessage();
            }
        }

        foreach ($scopeLocales as $scopeLocale) {
            foreach ($signatures as $signature) {
                if ($signature->area !== $scopeLocale['area']) {
                    continue;
                }
                try {
                    $contexts[] = new ThemeEditorContext(
                        scope: $scopeLocale['scope'],
                        area: $signature->area,
                        resourceType: ThemeEditorContext::RESOURCE_I18N,
                        themeId: $signature->themeId,
                        layoutType: $signature->layoutType,
                        layoutOption: $signature->layoutOption,
                        locale: $scopeLocale['locale'],
                        targetType: $signature->targetType,
                        targetId: $signature->targetId,
                    );
                } catch (\Throwable $e) {
                    $warnings[] = 'dictionary_context:' . $e->getMessage();
                }
            }
        }

        return [$this->uniqueSortedContexts($contexts), $warnings];
    }

    /** @return array{layout_type:string,layout_option:string,target_type:string,target_id:int}|null */
    private function parseMetaParamContext(string $configKey): ?array
    {
        if (preg_match(
            '/^targets\.([a-zA-Z0-9_:@-]+)\.([0-9]+)\.layouts\.([a-zA-Z0-9_:@-]+)\.([a-zA-Z0-9_.:@-]+)\.param\./D',
            $configKey,
            $target,
        ) === 1) {
            return [
                'layout_type' => $target[3],
                'layout_option' => $target[4],
                'target_type' => $target[1],
                'target_id' => (int)$target[2],
            ];
        }
        if (preg_match(
            '/^layouts\.([a-zA-Z0-9_:@-]+)\.([a-zA-Z0-9_.:@-]+)\.param\./D',
            $configKey,
            $layout,
        ) === 1) {
            return [
                'layout_type' => $layout[1],
                'layout_option' => $layout[2],
                'target_type' => 'global',
                'target_id' => 0,
            ];
        }

        return null;
    }

    /** @param list<ThemeEditorContext> $contexts @return list<ThemeEditorContext> */
    private function uniqueSortedContexts(array $contexts): array
    {
        $unique = [];
        foreach ($contexts as $context) {
            if ($context instanceof ThemeEditorContext) {
                $unique[$context->identityHash()] = $context;
            }
        }
        $contexts = array_values($unique);
        usort($contexts, static function (ThemeEditorContext $left, ThemeEditorContext $right): int {
            $depth = count($left->scope->fallbackStorageScopes) <=> count($right->scope->fallbackStorageScopes);
            return $depth !== 0 ? $depth : strcmp($left->canonicalKey(), $right->canonicalKey());
        });

        return $contexts;
    }

    private function scopeContextForLegacy(string $rawScope): ?ScopeContext
    {
        if ($this->isOpaqueScope($rawScope)) {
            return null;
        }
        $decoded = $this->scopeNormalizer->decodeStorageScope($rawScope);
        $identity = $this->scopes->fromStorageScope($decoded['storage_scope'], true);
        if (!$identity instanceof ScopeIdentity) {
            throw new \RuntimeException('theme_scope_migration_scope_unresolved:' . $rawScope);
        }
        if ($identity->isGlobal()) {
            return $this->scopes->contextFromIdentity($identity);
        }
        $websiteCode = (string)$identity->websiteCode;
        $websiteId = $this->catalog->websiteIdForCode($websiteCode);
        $mode = $decoded['store_mode'];
        $identity = match ($identity->scopeKind) {
            ScopeIdentity::KIND_WEBSITE => ScopeIdentity::website($websiteId, $websiteCode),
            ScopeIdentity::KIND_STORE => ScopeIdentity::store(
                $websiteId,
                $websiteCode,
                (string)$identity->storeCode,
                $mode,
            ),
            ScopeIdentity::KIND_CHANNEL => ScopeIdentity::channel(
                $websiteId,
                $websiteCode,
                (string)$identity->storeCode,
                (string)$identity->channelCode,
                $mode,
            ),
            default => $identity,
        };
        $this->assertCatalogPath($identity);

        return $this->scopes->contextFromIdentity($identity);
    }

    /** @return array<string,int> */
    private function rewriteLegacyScopes(): array
    {
        return [
            ThemeLayout::schema_table => $this->rewriteTargetAwareModelIdentities(
                $this->layouts,
                ThemeLayout::schema_fields_ID,
                ThemeLayout::schema_fields_SCOPE,
                ThemeLayout::schema_fields_TARGET_TYPE,
                ThemeLayout::schema_fields_TARGET_ID,
            ),
            ThemeLayoutVersion::schema_table => $this->rewriteTargetAwareModelIdentities(
                $this->versions,
                ThemeLayoutVersion::schema_fields_ID,
                ThemeLayoutVersion::schema_fields_SCOPE,
                ThemeLayoutVersion::schema_fields_TARGET_TYPE,
                ThemeLayoutVersion::schema_fields_TARGET_ID,
            ),
            ThemeVirtualLayout::schema_table => $this->rewriteTargetAwareModelIdentities(
                $this->virtualLayouts,
                ThemeVirtualLayout::schema_fields_ID,
                ThemeVirtualLayout::schema_fields_SCOPE,
                ThemeVirtualLayout::schema_fields_TARGET_TYPE,
                ThemeVirtualLayout::schema_fields_TARGET_ID,
            ),
            ThemeWidgetDefaultInjection::schema_table => $this->rewriteTargetAwareModelIdentities(
                $this->injections,
                ThemeWidgetDefaultInjection::schema_fields_ID,
                ThemeWidgetDefaultInjection::schema_fields_SCOPE,
                ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE,
                ThemeWidgetDefaultInjection::schema_fields_TARGET_ID,
            ),
            MetaConfig::schema_table => $this->rewriteModelScopes($this->metaConfigs, MetaConfig::schema_fields_ID, MetaConfig::schema_fields_SCOPE),
        ];
    }

    private function rewriteDictionaryScopes(): int
    {
        $updated = 0;
        foreach ($this->dictionary->listByWordPrefix('@meta::theme.') as $entry) {
            if (!$entry instanceof DictionaryEntry) {
                continue;
            }
            $rewritten = $this->rewriteDictionaryWordScope($entry->word);
            if ($rewritten === $entry->word) {
                continue;
            }
            $scope = $this->dictionaryScopeFromWord($rewritten);
            if ($scope === null || !$this->canWriteMigratedScope($scope)) {
                continue;
            }
            $this->dictionary->upsert($rewritten, $entry->localeCode, $entry->translation);
            $this->dictionary->deleteEntry($entry->word, $entry->localeCode);
            $updated++;
        }

        return $updated;
    }

    private function rewriteModelScopes(object $prototype, string $idField, string $scopeField): int
    {
        $rows = (clone $prototype)->clearData()->clearQuery()->select()->fetchArray();
        $updated = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $scope = (string)($row[$scopeField] ?? '');
            $rewritten = $this->rewriteDefaultStoreScope($scope);
            $id = (int)($row[$idField] ?? 0);
            if ($rewritten === $scope || $id <= 0 || !$this->canWriteMigratedScope($rewritten)) {
                continue;
            }
            $model = clone $prototype;
            $model->clearData();
            $model->clearQuery();
            $model->load($id);
            $model->setData($scopeField, $rewritten)->save();
            $updated++;
        }

        return $updated;
    }

    private function rewriteTargetAwareModelIdentities(
        object $prototype,
        string $idField,
        string $scopeField,
        string $targetTypeField,
        string $targetIdField,
    ): int {
        $rows = (clone $prototype)->clearData()->clearQuery()->select()->fetchArray();
        $updated = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $planned = $this->rewriteTargetAwareRow($row, $scopeField, $targetTypeField, $targetIdField);
            $id = (int)($row[$idField] ?? 0);
            if ($id <= 0 || !$this->rowIdentityChanged(
                $row,
                $planned,
                $scopeField,
                $targetTypeField,
                $targetIdField,
            )) {
                continue;
            }
            if (!$this->canWriteMigratedScope((string)$planned[$scopeField])) {
                continue;
            }
            $model = clone $prototype;
            $model->clearData();
            $model->clearQuery();
            $model->load($id);
            $model->setData($scopeField, $planned[$scopeField]);
            $model->setData($targetTypeField, $planned[$targetTypeField]);
            $model->setData($targetIdField, $planned[$targetIdField]);
            $model->save();
            $updated++;
        }

        return $updated;
    }

    /** @return list<array<string,mixed>> */
    private function relatedScopeRewriteCollisions(): array
    {
        $specs = [
            [$this->versions, ThemeLayoutVersion::schema_fields_ID, ThemeLayoutVersion::schema_fields_SCOPE, ThemeLayoutVersion::schema_fields_TARGET_TYPE, ThemeLayoutVersion::schema_fields_TARGET_ID, [
                ThemeLayoutVersion::schema_fields_THEME_ID,
                ThemeLayoutVersion::schema_fields_PAGE_TYPE,
                ThemeLayoutVersion::schema_fields_LAYOUT_OPTION,
                ThemeLayoutVersion::schema_fields_LOCALE_CODE,
                ThemeLayoutVersion::schema_fields_TARGET_TYPE,
                ThemeLayoutVersion::schema_fields_TARGET_ID,
                ThemeLayoutVersion::schema_fields_VERSION_NUMBER,
            ]],
            [$this->virtualLayouts, ThemeVirtualLayout::schema_fields_ID, ThemeVirtualLayout::schema_fields_SCOPE, ThemeVirtualLayout::schema_fields_TARGET_TYPE, ThemeVirtualLayout::schema_fields_TARGET_ID, [
                ThemeVirtualLayout::schema_fields_THEME_ID,
                ThemeVirtualLayout::schema_fields_AREA,
                ThemeVirtualLayout::schema_fields_LAYOUT_TYPE,
                ThemeVirtualLayout::schema_fields_LAYOUT_OPTION,
                ThemeVirtualLayout::schema_fields_LOCALE_CODE,
                ThemeVirtualLayout::schema_fields_TARGET_TYPE,
                ThemeVirtualLayout::schema_fields_TARGET_ID,
            ]],
            [$this->injections, ThemeWidgetDefaultInjection::schema_fields_ID, ThemeWidgetDefaultInjection::schema_fields_SCOPE, ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE, ThemeWidgetDefaultInjection::schema_fields_TARGET_ID, [
                ThemeWidgetDefaultInjection::schema_fields_THEME_ID,
                ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA,
                ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE,
                ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION,
                ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE,
                ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE,
                ThemeWidgetDefaultInjection::schema_fields_TARGET_ID,
                ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY,
            ]],
            [$this->metaConfigs, MetaConfig::schema_fields_ID, MetaConfig::schema_fields_SCOPE, null, null, [
                MetaConfig::schema_fields_NAMESPACE,
                MetaConfig::schema_fields_CONFIG_KEY,
                MetaConfig::schema_fields_LOCALE,
                MetaConfig::schema_fields_IDENTIFY_ID,
                MetaConfig::schema_fields_META_ID,
                MetaConfig::schema_fields_META_IDENTIFY,
            ]],
        ];
        $collisions = [];
        foreach ($specs as [$prototype, $idField, $scopeField, $targetTypeField, $targetIdField, $fields]) {
            $rows = (clone $prototype)->clearData()->clearQuery()->select()->fetchArray();
            $seen = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $scope = (string)($row[$scopeField] ?? '');
                if (is_string($targetTypeField) && is_string($targetIdField)) {
                    $planned = $this->rewriteTargetAwareRow($row, $scopeField, $targetTypeField, $targetIdField);
                    $rewritten = $this->rowIdentityChanged($row, $planned, $scopeField, $targetTypeField, $targetIdField);
                } else {
                    $planned = $row;
                    $planned[$scopeField] = $this->rewriteDefaultStoreScope($scope);
                    $rewritten = (string)$planned[$scopeField] !== $scope;
                }
                $target = (string)$planned[$scopeField];
                if (!$this->isOpaqueScope($target) && !$this->canWriteMigratedScope($target)) {
                    $planned = $row;
                    $target = $scope;
                    $rewritten = false;
                }
                $parts = [$target];
                foreach ($fields as $field) {
                    $parts[] = (string)($planned[$field] ?? '');
                }
                $key = implode("\0", $parts);
                $id = (int)($row[$idField] ?? 0);
                if (isset($seen[$key])
                    && $seen[$key] !== $id
                    && ($rewritten || ($seen[$key . ':rewritten'] ?? false))
                ) {
                    $collisions[] = ['table' => $prototype::schema_table, 'row_id' => $id, 'scope' => $scope, 'target_scope' => $target];
                }
                $seen[$key] = $id;
                $seen[$key . ':rewritten'] = $rewritten;
            }
        }

        return $collisions;
    }

    /** @return list<array<string,mixed>> */
    private function dictionaryScopeRewriteCollisions(): array
    {
        $entries = array_values(array_filter(
            $this->dictionary->listByWordPrefix('@meta::theme.'),
            static fn(mixed $entry): bool => $entry instanceof DictionaryEntry,
        ));
        $byIdentity = [];
        foreach ($entries as $entry) {
            $byIdentity[$entry->word . "\0" . $entry->localeCode] = $entry;
        }

        $collisions = [];
        foreach ($entries as $entry) {
            $rewritten = $this->rewriteDictionaryWordScope($entry->word);
            if ($rewritten === $entry->word) {
                continue;
            }
            $scope = $this->dictionaryScopeFromWord($rewritten);
            if ($scope === null || !$this->canWriteMigratedScope($scope)) {
                continue;
            }
            $existing = $byIdentity[$rewritten . "\0" . $entry->localeCode] ?? null;
            if ($existing instanceof DictionaryEntry && $existing->translation !== $entry->translation) {
                $collisions[] = [
                    'table' => 'w_i18n_locale_dictionary',
                    'word' => $entry->word,
                    'target_word' => $rewritten,
                    'locale' => $entry->localeCode,
                ];
            }
        }

        return $collisions;
    }

    private function rewriteDictionaryWordScope(string $word): string
    {
        $position = strrpos($word, '|scope:');
        if ($position === false) {
            return $word;
        }
        $scope = substr($word, $position + strlen('|scope:'));
        $rewritten = $this->rewriteDefaultStoreScope($scope);
        if ($rewritten === $scope) {
            return $word;
        }

        return substr($word, 0, $position + strlen('|scope:')) . $rewritten;
    }

    private function dictionaryScopeFromWord(string $word): ?string
    {
        $position = strrpos($word, '|scope:');
        if ($position === false) {
            return null;
        }

        $scope = trim(substr($word, $position + strlen('|scope:')));

        return $scope !== '' ? $scope : null;
    }

    /** @return list<array<string,mixed>> */
    private function layoutRows(): array
    {
        $rows = (clone $this->layouts)->clearData()->clearQuery()
            ->order(ThemeLayout::schema_fields_ID, 'ASC')
            ->select()->fetchArray();

        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    /** @return list<array<string,mixed>> */
    private function metaRows(): array
    {
        $rows = (clone $this->metaConfigs)->clearData()->clearQuery()
            ->order(MetaConfig::schema_fields_ID, 'ASC')
            ->select()->fetchArray();

        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    private function layoutIdentityKey(array $row, string $scope, ?string $uid = null): string
    {
        return implode("\0", [
            (string)($row[ThemeLayout::schema_fields_THEME_ID] ?? 0),
            (string)($row[ThemeLayout::schema_fields_PAGE_TYPE] ?? ''),
            (string)($row[ThemeLayout::schema_fields_LAYOUT_OPTION] ?? ''),
            $scope,
            (string)($row[ThemeLayout::schema_fields_LOCALE_CODE] ?? ''),
            (string)($row[ThemeLayout::schema_fields_TARGET_TYPE] ?? ''),
            (string)($row[ThemeLayout::schema_fields_TARGET_ID] ?? 0),
            (string)($row[ThemeLayout::schema_fields_STATUS] ?? ''),
            $uid ?? (string)($row[ThemeLayout::schema_fields_NODE_UID] ?? '@row:' . ($row[ThemeLayout::schema_fields_ID] ?? 0)),
        ]);
    }

    private function componentIdentityKey(array $row, ?string $scope = null): string
    {
        return ($scope ?? (string)($row[ThemeLayout::schema_fields_SCOPE] ?? '')) . "\0"
            . (string)($row[ThemeLayout::schema_fields_STATUS] ?? '') . "\0"
            . (string)($row[ThemeLayout::schema_fields_LOCALE_CODE] ?? '') . "\0"
            . $this->stableComponentIdentity($row);
    }

    private function stableComponentIdentity(array $row): string
    {
        return implode("\0", [
            (string)($row[ThemeLayout::schema_fields_THEME_ID] ?? 0),
            (string)($row[ThemeLayout::schema_fields_PAGE_TYPE] ?? ''),
            (string)($row[ThemeLayout::schema_fields_LAYOUT_OPTION] ?? ''),
            (string)($row[ThemeLayout::schema_fields_TARGET_TYPE] ?? ''),
            (string)($row[ThemeLayout::schema_fields_TARGET_ID] ?? 0),
            (string)($row[ThemeLayout::schema_fields_AREA] ?? ''),
            (string)($row[ThemeLayout::schema_fields_SLOT_ID] ?? ''),
            (string)($row[ThemeLayout::schema_fields_WIDGET_MODULE] ?? ''),
            (string)($row[ThemeLayout::schema_fields_WIDGET_TYPE] ?? ''),
            (string)($row[ThemeLayout::schema_fields_WIDGET_CODE] ?? ''),
        ]);
    }

    /** @param array<string,mixed> $row */
    private function legacyEditorArea(array $row): string
    {
        $pageType = (string)($row[ThemeLayout::schema_fields_PAGE_TYPE] ?? '');
        $targetType = \strtolower((string)($row[ThemeLayout::schema_fields_TARGET_TYPE] ?? ''));

        return $pageType === ThemeLayout::PAGE_TYPE_DASHBOARD || $targetType === 'dashboard_view'
            ? 'backend'
            : 'frontend';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function rewriteTargetAwareRow(
        array $row,
        string $scopeField,
        string $targetTypeField,
        string $targetIdField,
    ): array {
        $scope = (string)($row[$scopeField] ?? '');
        $targetType = strtolower(trim((string)($row[$targetTypeField] ?? 'global')));
        $targetId = max(0, (int)($row[$targetIdField] ?? 0));
        $mapping = $this->legacyIdentityMapping($scope, $targetType, $targetId);
        if ($mapping instanceof ThemeLegacyIdentityMapping) {
            $row[$scopeField] = $mapping->scope;
            $row[$targetTypeField] = $mapping->targetType;
            $row[$targetIdField] = $mapping->targetId;
            return $row;
        }

        $row[$scopeField] = $this->rewriteDefaultStoreScope($scope);
        return $row;
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $target */
    private function rowIdentityChanged(
        array $source,
        array $target,
        string $scopeField,
        string $targetTypeField,
        string $targetIdField,
    ): bool {
        return (string)($source[$scopeField] ?? '') !== (string)($target[$scopeField] ?? '')
            || strtolower(trim((string)($source[$targetTypeField] ?? ''))) !== strtolower(trim((string)($target[$targetTypeField] ?? '')))
            || (int)($source[$targetIdField] ?? 0) !== (int)($target[$targetIdField] ?? 0);
    }

    private function legacyIdentityMapping(
        string $scope,
        string $targetType,
        int $targetId,
    ): ?ThemeLegacyIdentityMapping {
        $cacheKey = strtolower(trim($scope)) . "\0" . $targetType . "\0" . $targetId;
        if (array_key_exists($cacheKey, $this->legacyIdentityMappingCache)) {
            return $this->legacyIdentityMappingCache[$cacheKey];
        }

        foreach ($this->targetTypes->all() as $provider) {
            if (!$provider instanceof ThemeLegacyIdentityMapperInterface) {
                continue;
            }
            try {
                $mapping = $provider->mapLegacyIdentity($scope, $targetType, $targetId);
                if (!$mapping instanceof ThemeLegacyIdentityMapping) {
                    continue;
                }
                $this->scopes->assertWritableRawScope($mapping->scope);
                if ($mapping->targetType !== strtolower(trim($provider->getCode()))) {
                    continue;
                }
                return $this->legacyIdentityMappingCache[$cacheKey] = $mapping;
            } catch (\Throwable) {
                continue;
            }
        }

        $this->legacyIdentityMappingCache[$cacheKey] = null;
        return null;
    }

    private function rewriteDefaultStoreScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if (preg_match('/^([a-z0-9][a-z0-9_-]{0,63})\.default\.__store__(~(?:normal|dev|test))?$/D', $scope, $matches) !== 1) {
            return $scope;
        }

        return $matches[1] . '.__store__.default' . ($matches[2] ?? '');
    }

    private function isOpaqueScope(string $scope): bool
    {
        return str_contains($scope, ':') || str_starts_with(strtolower(trim($scope)), 'dashboard_view');
    }

    private function assertCatalogPath(ScopeIdentity $identity): void
    {
        try {
            $authoritative = $this->catalog->authoritativeIdentity($identity);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'theme_scope_migration_catalog_identity_missing:' . $identity->canonicalKey(),
                0,
                $e,
            );
        }
        if (!$authoritative->equals($identity)) {
            throw new \RuntimeException(
                'theme_scope_migration_catalog_identity_mismatch:' . $identity->canonicalKey(),
            );
        }
    }

    private function canWriteMigratedScope(string $scope): bool
    {
        if ($this->isOpaqueScope($scope)) {
            return false;
        }
        try {
            return $this->scopeContextForLegacy($scope) instanceof ScopeContext;
        } catch (\Throwable) {
            return false;
        }
    }

    private function validUid(mixed $value): ?string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-f0-9]{32}$/D', $value) === 1 ? $value : null;
    }

    /** @return array<string,mixed> */
    private function decodeConfig(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function nullableId(mixed $value): ?int
    {
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }
}
