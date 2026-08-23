<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\I18n\Api\Translation\DictionaryEntry;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigRecord;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Api\Data\MetaConfigWrite;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Disk\ThemeDiskCompileService;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
use Weline\Theme\Service\ThemeMetaIdentityService;

/**
 * Compatibility adapter for resources that predate scoped releases.
 *
 * The release remains authoritative; this adapter only materializes effective
 * snapshots for existing readers while those readers are migrated.
 */
final class ThemeLegacyResourceAdapter implements ThemeScopedResourceAdapterInterface
{
    public function __construct(
        private readonly WelineTheme $themes,
        private readonly ThemeLayout $layouts,
        private readonly ThemeLayoutScopeNormalizer $scopeNormalizer,
        private readonly MetaConfigRepositoryInterface $metaConfigs,
        private readonly DictionaryRepositoryInterface $dictionary,
        private readonly ThemeMetaIdentityService $metaIdentities,
        private readonly ThemeDiskCompileService $diskCompiler,
        private readonly ThemeNodePlacementResolver $placements,
    ) {
    }

    public function loadBase(ThemeEditorContext $context): array
    {
        return match ($context->resourceType) {
            ThemeEditorContext::RESOURCE_THEME_BINDING => [
                'theme_id' => $this->activeThemeId($context->area),
            ],
            ThemeEditorContext::RESOURCE_LAYOUT => [
                'theme_id' => $context->themeId,
                'nodes' => [],
                'selection' => [],
            ],
            ThemeEditorContext::RESOURCE_META => ['values' => []],
            ThemeEditorContext::RESOURCE_APPEARANCE => ['tokens' => [], 'disks' => []],
            ThemeEditorContext::RESOURCE_I18N => ['translations' => []],
            default => [],
        };
    }

    public function loadLegacyPublished(ThemeEditorContext $context): array
    {
        return match ($context->resourceType) {
            ThemeEditorContext::RESOURCE_THEME_BINDING => [
                'theme_id' => $this->activeThemeId($context->area),
            ],
            ThemeEditorContext::RESOURCE_LAYOUT => $this->loadLegacyLayout($context),
            ThemeEditorContext::RESOURCE_META => $this->loadLegacyMeta($context),
            ThemeEditorContext::RESOURCE_APPEARANCE => $this->loadLegacyAppearance($context),
            ThemeEditorContext::RESOURCE_I18N => $this->loadLegacyI18n($context),
            default => [],
        };
    }

    public function compile(ThemeEditorContext $context, array $effectivePayload): array
    {
        $this->assertPayload($context, $effectivePayload);
        $payload = $this->canonicalize($effectivePayload);
        $encoded = \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'payload' => $payload,
            'artifact' => [
                'schema' => 'theme-scoped-resource.v1',
                'resource_type' => $context->resourceType,
                'fingerprint' => \hash('sha256', $encoded),
                'compiled_at' => \date(DATE_ATOM),
            ],
        ];
    }

    public function projectPublished(ThemeEditorContext $context, array $effectivePayload, int $releaseId): void
    {
        if ($context->resourceType === ThemeEditorContext::RESOURCE_THEME_BINDING) {
            // Only Global is projected into legacy singleton activation flags.
            if ($context->scope->identity->isGlobal()) {
                $this->projectGlobalThemeBinding($context->area, (int)($effectivePayload['theme_id'] ?? 0));
            }

            return;
        }
        if ($context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT) {
            $this->projectLayout($context, $effectivePayload, ThemeLayout::STATUS_PUBLISHED, $releaseId);
            $this->projectLayoutSelection($context, $effectivePayload);
            return;
        }
        if ($context->resourceType === ThemeEditorContext::RESOURCE_META) {
            $this->projectMeta($context, $effectivePayload);
            return;
        }
        if ($context->resourceType === ThemeEditorContext::RESOURCE_APPEARANCE) {
            $this->projectAppearance($context, $effectivePayload);
            return;
        }
        if ($context->resourceType === ThemeEditorContext::RESOURCE_I18N) {
            $this->projectI18n($context, $effectivePayload);
        }
    }

    public function projectDraft(ThemeEditorContext $context, array $effectivePayload): void
    {
        if ($context->resourceType !== ThemeEditorContext::RESOURCE_LAYOUT) {
            return;
        }

        $this->projectLayout($context, $effectivePayload, ThemeLayout::STATUS_DRAFT, null);
    }

    private function activeThemeId(string $area): int
    {
        $theme = clone $this->themes;
        try {
            $theme->clearData()->clearQuery()->getActiveTheme($area);

            return (int)$theme->getId();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertPayload(ThemeEditorContext $context, array $payload): void
    {
        if ($context->resourceType === ThemeEditorContext::RESOURCE_THEME_BINDING) {
            $themeId = $payload['theme_id'] ?? null;
            if (!\is_int($themeId) || $themeId <= 0) {
                throw new \InvalidArgumentException('theme_binding_theme_id_invalid');
            }
            $theme = clone $this->themes;
            $theme->clearData()->clearQuery()->load($themeId);
            if ((int)$theme->getId() !== $themeId || !$this->themeSupportsArea($theme, $context->area)) {
                throw new \InvalidArgumentException('theme_binding_theme_unavailable');
            }

            return;
        }
        if ($context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT) {
            if ((int)($payload['theme_id'] ?? $context->themeId) !== $context->themeId
                || !\is_array($payload['nodes'] ?? null)
                || !\is_array($payload['selection'] ?? null)
            ) {
                throw new \InvalidArgumentException('theme_layout_payload_invalid');
            }
            foreach ($payload['nodes'] as $uid => $node) {
                $uid = \strtolower((string)$uid);
                if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1
                    || !\is_array($node)
                    || \strtolower((string)($node['node_uid'] ?? '')) !== $uid
                ) {
                    throw new \InvalidArgumentException('theme_layout_payload_node_invalid');
                }
            }
            $this->placements->materialize($payload['nodes']);

            return;
        }
        $requiredRoot = match ($context->resourceType) {
            ThemeEditorContext::RESOURCE_META => 'values',
            ThemeEditorContext::RESOURCE_I18N => 'translations',
            default => null,
        };
        if ($requiredRoot !== null && !\is_array($payload[$requiredRoot] ?? null)) {
            throw new \InvalidArgumentException('theme_scoped_payload_root_invalid:' . $requiredRoot);
        }
        if ($context->resourceType === ThemeEditorContext::RESOURCE_APPEARANCE
            && (!\is_array($payload['tokens'] ?? null) || !\is_array($payload['disks'] ?? null))
        ) {
            throw new \InvalidArgumentException('theme_scoped_payload_root_invalid:appearance');
        }
    }

    private function themeSupportsArea(WelineTheme $theme, string $area): bool
    {
        $basePath = \rtrim($theme->getPath(), '/\\');
        if ($basePath === '') {
            return false;
        }
        $separator = \DIRECTORY_SEPARATOR;

        return \is_dir($basePath . $separator . $area)
            || \is_dir($basePath . $separator . 'view' . $separator . 'theme' . $separator . $area)
            || \is_dir($basePath . $separator . 'theme' . $separator . $area);
    }

    private function projectGlobalThemeBinding(string $area, int $themeId): void
    {
        if ($themeId <= 0) {
            return;
        }
        $field = $area === 'backend'
            ? WelineTheme::schema_fields_IS_ACTIVE_BACKEND
            : WelineTheme::schema_fields_IS_ACTIVE_FRONTEND;
        $theme = clone $this->themes;
        $theme->clearData()->clearQuery()->where($field, 1)->update([$field => 0])->fetch();
        $theme->clearData()->clearQuery()->where(WelineTheme::schema_fields_ID, $themeId)->update([$field => 1])->fetch();
        $theme->_cache->delete($area === 'backend' ? 'theme_backend' : 'theme_frontend');
        $theme->_cache->delete('theme');
    }

    /** @return array{theme_id:int,nodes:array<string,array<string,mixed>>,selection:array<string,mixed>} */
    private function loadLegacyLayout(ThemeEditorContext $context, bool $allowFallback = false): array
    {
        $themeId = $context->themeId > 0 ? $context->themeId : $this->activeThemeId($context->area);
        if ($themeId <= 0) {
            return ['theme_id' => 0, 'nodes' => [], 'selection' => []];
        }

        $identity = [
            'scope' => $context->scope->storageScope,
            'store_mode' => $context->scope->storeMode,
        ];
        $encodedScope = $this->scopeNormalizer->encodeStorageScope($identity['scope'], $identity['store_mode']);
        $scopeCandidates = $allowFallback
            ? $this->scopeNormalizer->readFallbackScopes($encodedScope)
            : $this->scopeNormalizer->readCandidateScopes($encodedScope);
        $localeCandidates = $context->locale === 'default' ? [''] : [$context->locale, ''];
        $rows = [];
        foreach ($scopeCandidates as $scope) {
            foreach ($localeCandidates as $localeCode) {
                $query = (clone $this->layouts)->clearData()->clearQuery()
                    ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                    ->where(ThemeLayout::schema_fields_PAGE_TYPE, $context->layoutType)
                    ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $context->layoutOption)
                    ->where(ThemeLayout::schema_fields_SCOPE, $scope)
                    ->where(ThemeLayout::schema_fields_LOCALE_CODE, $localeCode)
                    ->where(ThemeLayout::schema_fields_TARGET_TYPE, $context->targetType)
                    ->where(ThemeLayout::schema_fields_TARGET_ID, $context->targetId)
                    ->where(ThemeLayout::schema_fields_STATUS, ThemeLayout::STATUS_PUBLISHED)
                    ->order(ThemeLayout::schema_fields_SORT_ORDER, 'ASC')
                    ->order(ThemeLayout::schema_fields_ID, 'ASC')
                    ->select()
                    ->fetchArray();
                if (\is_array($query) && $query !== []) {
                    // Exact published rows establish ownership even when every
                    // row is inactive: that is an explicit empty layout and must
                    // stop parent fallback rather than resurrect parent nodes.
                    $rows = \array_values(\array_filter(
                        $query,
                        static fn(mixed $row): bool => \is_array($row)
                            && (int)($row[ThemeLayout::schema_fields_IS_ACTIVE] ?? 0) === 1,
                    ));
                    break 2;
                }
            }
        }

        $nodes = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $uid = \strtolower(\trim((string)($row[ThemeLayout::schema_fields_NODE_UID] ?? '')));
            if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
                $uid = $this->legacyNodeUid($row);
            }
            $config = $row[ThemeLayout::schema_fields_CONFIG] ?? [];
            if (\is_string($config)) {
                $decoded = \json_decode($config, true);
                $config = \is_array($decoded) ? $decoded : [];
            }
            if (\is_array($config)) {
                unset($config['_theme_release_id'], $config['_theme_scope_draft_projection']);
            }
            if (\trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? '')) === '') {
                $config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] = 'wi_' . $uid;
            }
            $nodes[$uid] = [
                'node_uid' => $uid,
                'area' => (string)($row[ThemeLayout::schema_fields_AREA] ?? ThemeLayout::AREA_CONTENT),
                'slot_id' => isset($row[ThemeLayout::schema_fields_SLOT_ID]) ? (string)$row[ThemeLayout::schema_fields_SLOT_ID] : null,
                'widget_code' => (string)($row[ThemeLayout::schema_fields_WIDGET_CODE] ?? ''),
                'widget_module' => (string)($row[ThemeLayout::schema_fields_WIDGET_MODULE] ?? ''),
                'widget_type' => (string)($row[ThemeLayout::schema_fields_WIDGET_TYPE] ?? ''),
                'config' => \is_array($config) ? $config : [],
                'sort_order' => (int)($row[ThemeLayout::schema_fields_SORT_ORDER] ?? 0),
                'is_active' => (bool)($row[ThemeLayout::schema_fields_IS_ACTIVE] ?? true),
            ];
        }

        $selection = $this->loadLegacyLayoutSelection($context, $themeId, $scopeCandidates);

        return ['theme_id' => $themeId, 'nodes' => $nodes, 'selection' => $selection];
    }

    private function projectLayout(
        ThemeEditorContext $context,
        array $payload,
        string $status,
        ?int $releaseId,
    ): void
    {
        if (!\in_array($status, [ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED], true)) {
            throw new \InvalidArgumentException('theme_layout_projection_status_invalid');
        }
        $themeId = (int)($payload['theme_id'] ?? $context->themeId);
        if ($themeId <= 0) {
            $themeId = $this->activeThemeId($context->area);
        }
        if ($themeId <= 0) {
            return;
        }
        $nodes = \is_array($payload['nodes'] ?? null)
            ? $this->placements->materialize($payload['nodes'])
            : [];
        $legacyScope = $this->scopeNormalizer->encodeStorageScope(
            $context->scope->storageScope,
            $context->scope->storeMode,
        );
        $localeCode = $context->locale === 'default' ? '' : $context->locale;

        $existing = (clone $this->layouts)->clearData()->clearQuery()
            ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayout::schema_fields_PAGE_TYPE, $context->layoutType)
            ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $context->layoutOption)
            ->where(ThemeLayout::schema_fields_SCOPE, $legacyScope)
            ->where(ThemeLayout::schema_fields_LOCALE_CODE, $localeCode)
            ->where(ThemeLayout::schema_fields_TARGET_TYPE, $context->targetType)
            ->where(ThemeLayout::schema_fields_TARGET_ID, $context->targetId)
            ->where(ThemeLayout::schema_fields_STATUS, $status)
            ->select()->fetchArray();
        $byUid = [];
        foreach (\is_array($existing) ? $existing : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
            $uid = (string)($row[ThemeLayout::schema_fields_NODE_UID] ?? '');
            if ($id > 0 && $uid !== '') {
                $byUid[$uid] = $id;
            }
            if ($id > 0) {
                $inactive = clone $this->layouts;
                $inactive->clearData()->clearQuery()->load($id);
                $inactive->setIsActive(false)->save();
            }
        }

        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower((string)($node['node_uid'] ?? $uid));
            if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
                throw new \RuntimeException('theme_release_projection_node_uid_invalid');
            }
            $model = clone $this->layouts;
            $model->clearData()->clearQuery();
            if (isset($byUid[$uid])) {
                $model->load($byUid[$uid]);
            }
            $config = \is_array($node['config'] ?? null) ? $node['config'] : [];
            if (\trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? '')) === '') {
                $config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] = 'wi_' . $uid;
            }
            if ($releaseId !== null) {
                $config['_theme_release_id'] = $releaseId;
                unset($config['_theme_scope_draft_projection']);
            } else {
                unset($config['_theme_release_id']);
                $config['_theme_scope_draft_projection'] = true;
            }
            $model
                ->setNodeUid($uid)
                ->setThemeId($themeId)
                ->setPageType($context->layoutType)
                ->setLayoutOption($context->layoutOption)
                ->setScope($legacyScope)
                ->setLocaleCode($localeCode)
                ->setTargetType($context->targetType)
                ->setTargetId($context->targetId)
                ->setArea((string)($node['area'] ?? ThemeLayout::AREA_CONTENT))
                ->setSlotId(isset($node['slot_id']) ? (string)$node['slot_id'] : null)
                ->setWidgetCode((string)($node['widget_code'] ?? ''))
                ->setWidgetModule((string)($node['widget_module'] ?? ''))
                ->setWidgetType((string)($node['widget_type'] ?? ''))
                ->setWidgetConfig($config)
                ->setSortOrder((int)($node['sort_order'] ?? 0))
                ->setIsActive((bool)($node['is_active'] ?? true))
                ->setStatus($status)
                ->save();
        }
    }

    /** @param list<string> $scopeCandidates @return array<string,mixed> */
    private function loadLegacyLayoutSelection(
        ThemeEditorContext $context,
        int $themeId,
        array $scopeCandidates,
    ): array {
        foreach ($scopeCandidates as $scope) {
            $record = $this->metaConfigs->resolve(new MetaConfigIdentity(
                namespace: 'theme.' . $context->area,
                configKey: 'layouts.' . $context->layoutType . '.value',
                scope: $scope,
                locale: null,
                identifyId: (string)$themeId,
            ));
            if ($record instanceof MetaConfigRecord) {
                return ['layout_option' => $record->value];
            }
        }

        return [];
    }

    /** @return array{values:array<string,mixed>} */
    private function loadLegacyMeta(ThemeEditorContext $context): array
    {
        $themeId = $this->contextThemeId($context);
        if ($themeId <= 0) {
            return ['values' => []];
        }
        [$namespace, $identify, $configPrefix] = $this->metaResourceIdentity($context);
        $values = [];
        foreach (array_reverse($this->legacyReadScopes($context)) as $scope) {
            $records = $this->metaConfigs->search(new MetaConfigSearch(
                namespace: $namespace,
                scope: $scope,
                configKeyPrefix: $configPrefix . '.param.',
                allLocales: true,
                identifyId: (string)$themeId,
            ));
            // Meta is the locale-neutral resource. Localized values belong to the
            // separate I18n workspace and must never change this identity's base.
            $resolved = $this->resolveLegacyRecords($records, 'default');
            foreach ($resolved as $configKey => $record) {
                $prefix = $configPrefix . '.param.';
                if (!str_starts_with($configKey, $prefix)) {
                    continue;
                }
                $param = substr($configKey, strlen($prefix));
                if (str_ends_with($param, '.value')) {
                    $param = substr($param, 0, -strlen('.value'));
                }
                if ($param !== '') {
                    $values[$param] = $this->decodeLegacyValue($record->value);
                }
            }
        }

        return ['values' => $values];
    }

    /** @return array{tokens:array<string,mixed>,disks:array<string,mixed>} */
    private function loadLegacyAppearance(ThemeEditorContext $context): array
    {
        $themeId = $this->contextThemeId($context);
        $payload = ['tokens' => [], 'disks' => []];
        if ($themeId <= 0) {
            return $payload;
        }

        foreach (array_reverse($this->legacyReadScopes($context)) as $scope) {
            $records = $this->metaConfigs->search(new MetaConfigSearch(
                namespace: 'theme.' . $context->area,
                scope: $scope,
                configKeyPrefix: 'disk_',
                allLocales: true,
                identifyId: (string)$themeId,
            ));
            foreach ($this->resolveLegacyRecords($records, 'default') as $configKey => $record) {
                if (str_starts_with($configKey, 'disk_active.')) {
                    $panel = substr($configKey, strlen('disk_active.'));
                    if ($this->isAppearanceSegment($panel)) {
                        $payload['tokens'][$panel] = $record->value;
                    }
                    continue;
                }
                if (!str_starts_with($configKey, 'disk_custom.')) {
                    continue;
                }
                $parts = explode('.', substr($configKey, strlen('disk_custom.')), 2);
                if (count($parts) !== 2
                    || !$this->isAppearanceSegment($parts[0])
                    || !$this->isAppearanceSegment($parts[1])
                ) {
                    continue;
                }
                $decoded = json_decode($record->value, true);
                $payload['disks'][$parts[0]][$parts[1]] = is_array($decoded) ? $decoded : [];
            }
        }

        return $payload;
    }

    /** @return array{translations:array<string,mixed>} */
    private function loadLegacyI18n(ThemeEditorContext $context): array
    {
        $translations = [];
        if ($context->locale === 'default') {
            return ['translations' => $translations];
        }
        [, $layoutIdentify] = $this->metaResourceIdentity($context);
        $layoutPayload = $this->loadLegacyLayout(
            $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT),
            true,
        );
        $nodeIdentifies = $this->nodeTranslationIdentifies($layoutPayload['nodes'] ?? [], $context->area);
        $entries = $this->dictionary->listByWordPrefix('@meta::theme.' . $context->area . '.');

        foreach (array_reverse($this->legacyReadScopes($context)) as $scope) {
            foreach ($entries as $entry) {
                if (!$entry instanceof DictionaryEntry
                    || $entry->localeCode !== $context->locale
                    || !$this->wordMatchesScope($entry->word, $scope)
                ) {
                    continue;
                }
                $layoutKey = $this->translationParamForIdentify($entry->word, $layoutIdentify);
                if ($layoutKey !== null) {
                    $translations['layout'][$layoutKey] = ThemeData::decodeProjectedTranslationValue(
                        $entry->translation,
                    );
                    continue;
                }
                foreach ($nodeIdentifies as $nodeUid => $identify) {
                    $param = $this->translationParamForIdentify($entry->word, $identify);
                    if ($param !== null) {
                        $translations[$nodeUid][$param] = ThemeData::decodeProjectedTranslationValue(
                            $entry->translation,
                        );
                        break;
                    }
                }
            }
        }

        return ['translations' => $translations];
    }

    private function projectLayoutSelection(ThemeEditorContext $context, array $payload): void
    {
        $selection = is_array($payload['selection'] ?? null) ? $payload['selection'] : [];
        if (!array_key_exists('layout_option', $selection)) {
            return;
        }
        $themeId = $this->contextThemeId($context);
        if ($themeId <= 0) {
            return;
        }
        $value = (string)$selection['layout_option'];
        $this->metaConfigs->upsert(new MetaConfigWrite(
            new MetaConfigIdentity(
                namespace: 'theme.' . $context->area,
                configKey: 'layouts.' . $context->layoutType . '.value',
                scope: $this->legacyWriteScope($context),
                locale: null,
                identifyId: (string)$themeId,
                metaIdentify: 'theme.' . $context->area . '.layouts.' . $context->layoutType,
            ),
            $value,
        ));
        ThemeData::clearCache();
    }

    private function projectMeta(ThemeEditorContext $context, array $payload): void
    {
        $themeId = $this->contextThemeId($context);
        if ($themeId <= 0) {
            return;
        }
        [$namespace, $identify, $configPrefix] = $this->metaResourceIdentity($context);
        $scope = $this->legacyWriteScope($context);
        $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        $expected = [];
        foreach ($values as $name => $value) {
            $name = (string)$name;
            if (!$this->isConfigPathSegment($name)) {
                throw new \RuntimeException('theme_meta_projection_param_invalid');
            }
            $expected[$configPrefix . '.param.' . $name . '.value'] = $this->encodeLegacyValue($value);
        }

        $existing = $this->metaConfigs->search(new MetaConfigSearch(
            namespace: $namespace,
            scope: $scope,
            configKeyPrefix: $configPrefix . '.param.',
            allLocales: true,
            identifyId: (string)$themeId,
        ));
        foreach ($existing as $record) {
            if ($record->locale !== null) {
                continue;
            }
            if (!array_key_exists($record->configKey, $expected)) {
                $this->metaConfigs->delete($this->identityForRecord($record));
            }
        }
        foreach ($expected as $configKey => $value) {
            $this->metaConfigs->upsert(new MetaConfigWrite(
                new MetaConfigIdentity(
                    namespace: $namespace,
                    configKey: $configKey,
                    scope: $scope,
                    locale: null,
                    identifyId: (string)$themeId,
                    metaIdentify: $identify,
                ),
                $value,
            ));
        }
        ThemeData::clearCache();
    }

    private function projectAppearance(ThemeEditorContext $context, array $payload): void
    {
        $themeId = $this->contextThemeId($context);
        if ($themeId <= 0) {
            return;
        }
        $scope = $this->legacyWriteScope($context);
        $expected = [];
        foreach (is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [] as $panel => $active) {
            if (!$this->isAppearanceSegment((string)$panel)) {
                throw new \RuntimeException('theme_appearance_panel_invalid');
            }
            $expected['disk_active.' . $panel] = (string)$active;
        }
        foreach (is_array($payload['disks'] ?? null) ? $payload['disks'] : [] as $panel => $disks) {
            if (!$this->isAppearanceSegment((string)$panel) || !is_array($disks)) {
                throw new \RuntimeException('theme_appearance_disk_invalid');
            }
            foreach ($disks as $diskKey => $disk) {
                if (!$this->isAppearanceSegment((string)$diskKey)) {
                    throw new \RuntimeException('theme_appearance_disk_invalid');
                }
                if ($disk === null) {
                    continue;
                }
                $expected['disk_custom.' . $panel . '.' . $diskKey] = $this->encodeLegacyValue($disk);
            }
        }

        $existing = $this->metaConfigs->search(new MetaConfigSearch(
            namespace: 'theme.' . $context->area,
            scope: $scope,
            configKeyPrefix: 'disk_',
            allLocales: true,
            identifyId: (string)$themeId,
        ));
        foreach ($existing as $record) {
            if ($record->locale !== null) {
                continue;
            }
            if ((!str_starts_with($record->configKey, 'disk_active.')
                    && !str_starts_with($record->configKey, 'disk_custom.'))
                || array_key_exists($record->configKey, $expected)
            ) {
                continue;
            }
            $this->metaConfigs->delete($this->identityForRecord($record));
        }
        foreach ($expected as $configKey => $value) {
            $this->metaConfigs->upsert(new MetaConfigWrite(
                new MetaConfigIdentity(
                    namespace: 'theme.' . $context->area,
                    configKey: $configKey,
                    scope: $scope,
                    locale: null,
                    identifyId: (string)$themeId,
                ),
                $value,
            ));
        }

        ThemeData::clearCache();
        $theme = clone $this->themes;
        $theme->clearData()->clearQuery()->load($themeId);
        if ((int)$theme->getId() === $themeId) {
            $this->diskCompiler->compileBundle($theme, $context->area, $scope);
        }
    }

    private function projectI18n(ThemeEditorContext $context, array $payload): void
    {
        if ($context->locale === 'default') {
            return;
        }
        [, $layoutIdentify] = $this->metaResourceIdentity($context);
        $layoutPayload = $this->loadLegacyLayout(
            $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT),
            true,
        );
        $nodeIdentifies = $this->nodeTranslationIdentifies($layoutPayload['nodes'] ?? [], $context->area);
        $translations = is_array($payload['translations'] ?? null) ? $payload['translations'] : [];
        $scope = $this->legacyWriteScope($context);
        $expected = [];
        foreach ($translations as $owner => $values) {
            if (!is_array($values)) {
                continue;
            }
            $identify = $owner === 'layout' ? $layoutIdentify : ($nodeIdentifies[(string)$owner] ?? null);
            if (!is_string($identify) || $identify === '') {
                if ($values !== []) {
                    throw new \RuntimeException('theme_i18n_projection_node_identity_missing');
                }
                continue;
            }
            foreach ($values as $name => $value) {
                $name = (string)$name;
                if (!$this->isConfigPathSegment($name)) {
                    throw new \RuntimeException('theme_i18n_projection_param_invalid');
                }
                $kind = str_contains($name, '.') ? 'path' : 'param';
                $word = '@meta::' . $identify . '.' . $kind . '.' . $name . '.value';
                if ($scope !== 'default') {
                    $word .= '|scope:' . $scope;
                }
                $expected[$word] = ThemeData::encodeProjectedTranslationValue($value);
            }
        }

        $relatedIdentifies = array_values(array_unique(array_merge([$layoutIdentify], array_values($nodeIdentifies))));
        foreach ($this->dictionary->listByWordPrefix('@meta::theme.' . $context->area . '.') as $entry) {
            if ($entry->localeCode !== $context->locale || !$this->wordMatchesScope($entry->word, $scope)) {
                continue;
            }
            $related = false;
            foreach ($relatedIdentifies as $identify) {
                if ($this->translationParamForIdentify($entry->word, $identify) !== null) {
                    $related = true;
                    break;
                }
            }
            if ($related && !array_key_exists($entry->word, $expected)) {
                $this->dictionary->deleteEntry($entry->word, $entry->localeCode);
            }
        }
        foreach ($expected as $word => $translation) {
            $this->dictionary->upsert($word, $context->locale, $translation);
        }
        ThemeData::clearCache();
    }

    private function contextThemeId(ThemeEditorContext $context): int
    {
        return $context->themeId > 0 ? $context->themeId : $this->activeThemeId($context->area);
    }

    private function legacyWriteScope(ThemeEditorContext $context): string
    {
        return $this->scopeNormalizer->encodeStorageScope(
            $context->scope->storageScope,
            $context->scope->storeMode,
        );
    }

    /** @return list<string> */
    private function legacyReadScopes(ThemeEditorContext $context): array
    {
        return $this->scopeNormalizer->readCandidateScopes($this->legacyWriteScope($context));
    }

    /** @return array{0:string,1:string,2:string} */
    private function metaResourceIdentity(ThemeEditorContext $context): array
    {
        $identify = $this->metaIdentities->targetIdentify(
            $context->area,
            $context->targetType,
            $context->targetId,
            $context->layoutType,
            $context->layoutOption,
        );
        if ($identify === '') {
            $identify = $this->metaIdentities->layoutIdentify(
                $context->layoutType,
                $context->layoutOption,
            );
        }
        if (!str_starts_with($identify, 'theme.')) {
            $identify = 'theme.' . $context->area . '.' . $identify;
        }
        $namespace = 'theme.' . $context->area;
        $prefix = substr($identify, strlen($namespace . '.'));

        return [$namespace, $identify, $prefix];
    }

    /**
     * @param list<MetaConfigRecord> $records
     * @return array<string,MetaConfigRecord>
     */
    private function resolveLegacyRecords(array $records, string $locale): array
    {
        $localeOrder = $locale === '' || $locale === 'default'
            ? [null]
            : array_values(array_unique([$locale, 'zh_Hans_CN', null], SORT_REGULAR));
        $resolved = [];
        $ranks = [];
        foreach ($records as $record) {
            $rank = array_search($record->locale, $localeOrder, true);
            if ($rank === false || (isset($ranks[$record->configKey]) && $ranks[$record->configKey] <= $rank)) {
                continue;
            }
            $ranks[$record->configKey] = $rank;
            $resolved[$record->configKey] = $record;
        }

        return $resolved;
    }

    /** @param array<string,array<string,mixed>> $nodes @return array<string,string> */
    private function nodeTranslationIdentifies(array $nodes, string $area): array
    {
        $identifies = [];
        foreach ($nodes as $nodeUid => $node) {
            if (!is_array($node)) {
                continue;
            }
            $uid = strtolower((string)($node['node_uid'] ?? $nodeUid));
            if (preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
                continue;
            }
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $instance = trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? ''));
            if ($instance === '') {
                $instance = 'wi_' . $uid;
            }
            $identifies[$uid] = ThemeData::getWidgetInstanceIdentify($instance, $area);
        }

        return $identifies;
    }

    private function wordMatchesScope(string $word, string $scope): bool
    {
        $marker = '|scope:';
        $position = strrpos($word, $marker);
        if ($scope === 'default') {
            return $position === false;
        }

        return $position !== false && substr($word, $position + strlen($marker)) === $scope;
    }

    private function translationParamForIdentify(string $word, string $identify): ?string
    {
        $position = strrpos($word, '|scope:');
        if ($position !== false) {
            $word = substr($word, 0, $position);
        }
        foreach (['param', 'path'] as $kind) {
            $prefix = '@meta::' . $identify . '.' . $kind . '.';
            if (!str_starts_with($word, $prefix) || !str_ends_with($word, '.value')) {
                continue;
            }
            $param = substr($word, strlen($prefix), -strlen('.value'));
            return $param !== '' ? $param : null;
        }

        return null;
    }

    private function identityForRecord(MetaConfigRecord $record): MetaConfigIdentity
    {
        return new MetaConfigIdentity(
            namespace: $record->namespace,
            configKey: $record->configKey,
            scope: $record->scope,
            locale: $record->locale,
            identifyId: $record->identifyId,
            metaId: $record->metaId,
            metaIdentify: $record->metaIdentify,
        );
    }

    private function encodeLegacyValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function decodeLegacyValue(string $value): mixed
    {
        $trimmed = trim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['{', '['], true)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    private function isConfigPathSegment(string $segment): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:@-]{0,254}$/D', $segment) === 1;
    }

    private function isAppearanceSegment(string $segment): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $segment) === 1;
    }

    /** @param array<string,mixed> $row */
    private function legacyNodeUid(array $row): string
    {
        $config = $row[ThemeLayout::schema_fields_CONFIG] ?? '';
        if (\is_string($config)) {
            $decoded = \json_decode($config, true);
            $config = \is_array($decoded) ? $decoded : [];
        }
        if (\is_array($config)) {
            $i18n = (string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? '');
            if ($i18n !== '') {
                return \substr(\hash('sha256', 'i18n:' . $i18n), 0, 32);
            }
        }

        return \substr(\hash('sha256', \implode("\0", [
            (string)($row[ThemeLayout::schema_fields_ID] ?? ''),
            (string)($row[ThemeLayout::schema_fields_WIDGET_MODULE] ?? ''),
            (string)($row[ThemeLayout::schema_fields_WIDGET_TYPE] ?? ''),
            (string)($row[ThemeLayout::schema_fields_WIDGET_CODE] ?? ''),
            (string)($row[ThemeLayout::schema_fields_SLOT_ID] ?? ''),
        ])), 0, 32);
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        if (!\array_is_list($value)) {
            \ksort($value, SORT_STRING);
        }

        return $value;
    }
}
