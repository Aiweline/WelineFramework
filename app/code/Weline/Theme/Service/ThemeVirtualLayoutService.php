<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Model\SystemConfigVersion;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeVirtualLayoutVersion;
use Weline\Theme\Model\WelineTheme;

class ThemeVirtualLayoutService
{
    public const MODULE_CODE = 'Weline_Theme';
    public const DEFAULT_AREA = 'frontend';
    public const DEFAULT_SCOPE = SystemConfig::SCOPE_GLOBAL;
    public const RUNTIME_MODULE_PREFIX = 'runtime/virtual-layouts/';
    private const RUNTIME_DIR = 'theme-virtual-layouts';

    public function __construct(
        private readonly ThemeVirtualLayout $virtualLayout,
        private readonly ThemeVirtualLayoutVersion $virtualLayoutVersion,
        private readonly WelineTheme $welineTheme,
        private readonly SystemConfig $systemConfig,
    ) {
    }

    public function normalizeLayoutOption(string $layoutOption): string
    {
        $layoutOption = strtolower(trim($layoutOption));
        $layoutOption = preg_replace('/[^a-z0-9_-]+/', '-', $layoutOption) ?? '';
        return trim($layoutOption, '-_');
    }

    public function normalizeScope(?string $scope = null): string
    {
        return $this->systemConfig->normalizeScope($scope);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function saveLayoutSelection(
        string $targetType,
        int $targetId,
        string $layoutType,
        string $layoutOption,
        ?string $scope = null,
        ?string $locale = null,
        array $options = []
    ): array {
        $layoutType = $this->normalizeLayoutType($layoutType);
        $layoutOption = $this->normalizeLayoutOption($layoutOption);
        $targetType = $this->normalizeTargetType($targetType);
        if ($targetId <= 0 || $layoutType === '' || $layoutOption === '') {
            return ['success' => false, 'status' => 'invalid_identity'];
        }
        if (!$this->isSelectableLayoutOption($layoutType, $layoutOption, [
            'area' => self::DEFAULT_AREA,
            'scope' => $scope,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ])) {
            return [
                'success' => false,
                'status' => 'invalid_layout_option',
                'message' => (string)__('布局选项不存在或不允许用于当前对象'),
                'layout_type' => $layoutType,
                'layout_option' => $layoutOption,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'scope' => $this->normalizeScope($scope),
            ];
        }

        $result = $this->systemConfig->saveScopeConfig(
            self::MODULE_CODE,
            self::DEFAULT_AREA,
            [$this->selectionKey($targetType, $targetId, $layoutType) => $layoutOption],
            $scope,
            $locale,
            array_replace_recursive([
                'operation' => 'theme_layout_selection_save',
                'reason' => (string)__('保存布局选择'),
                'metadata' => [
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'layout_type' => $layoutType,
                    'layout_option' => $layoutOption,
                ],
            ], $options)
        );
        if (!empty($result['success'])) {
            $this->clearRuntimeCaches(null, 'theme_virtual_layout_selection_save');
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function deleteLayoutSelection(
        string $targetType,
        int $targetId,
        string $layoutType,
        ?string $scope = null,
        ?string $locale = null,
        array $options = []
    ): array {
        $layoutType = $this->normalizeLayoutType($layoutType);
        $targetType = $this->normalizeTargetType($targetType);
        $key = $this->selectionKey($targetType, $targetId, $layoutType);

        $result = $this->systemConfig->saveScopeConfig(
            self::MODULE_CODE,
            self::DEFAULT_AREA,
            [$key => null],
            $scope,
            $locale,
            array_replace_recursive([
                'operation' => 'theme_layout_selection_inherit',
                'inherit_keys' => [$key],
                'reason' => (string)__('删除布局选择并恢复继承'),
                'metadata' => [
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'layout_type' => $layoutType,
                ],
            ], $options)
        );
        if (!empty($result['success'])) {
            $this->clearRuntimeCaches(null, 'theme_virtual_layout_selection_delete');
        }

        return $result;
    }

    /**
     * @return array{layout_code:string, source:string, source_scope:string, version:int}|null
     */
    public function resolveLayoutSelection(
        string $targetType,
        int $targetId,
        string $layoutType,
        ?string $scope = null,
        ?string $locale = null
    ): ?array {
        $layoutType = $this->normalizeLayoutType($layoutType);
        $targetType = $this->normalizeTargetType($targetType);
        if ($targetId <= 0 || $layoutType === '') {
            return null;
        }

        $resolved = $this->systemConfig->resolveConfig(
            $this->selectionKey($targetType, $targetId, $layoutType),
            self::MODULE_CODE,
            self::DEFAULT_AREA,
            $scope,
            $locale
        );
        if (empty($resolved['found'])) {
            return null;
        }

        $layoutCode = $this->normalizeLayoutOption((string)($resolved['value'] ?? ''));
        if ($layoutCode === '') {
            return null;
        }

        $source = is_array($resolved['source'] ?? null) ? $resolved['source'] : [];
        return [
            'layout_code' => $layoutCode,
            'source' => 'theme_config',
            'source_scope' => (string)($source['scope'] ?? ''),
            'version' => (int)($source['version'] ?? 0),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLayoutSelectionVersions(
        string $targetType,
        int $targetId,
        string $layoutType,
        ?string $scope = null,
        ?string $locale = null,
        int $limit = 20,
        bool $withPrecheck = false
    ): array {
        $identity = $this->normalizeSelectionIdentity($targetType, $targetId, $layoutType, $scope, $locale);
        if (!$this->isValidSelectionIdentity($identity)) {
            return [];
        }

        $scanLimit = max($limit * 5, 50);
        $versions = [];
        foreach ($this->systemConfig->getConfigVersions(
            self::MODULE_CODE,
            self::DEFAULT_AREA,
            (string)$identity['scope'],
            (string)$identity['locale'],
            $scanLimit
        ) as $row) {
            $versionId = (int)($row[SystemConfigVersion::schema_fields_ID] ?? 0);
            if ($versionId <= 0) {
                continue;
            }
            $detail = $this->systemConfig->getConfigVersionDetail($versionId);
            if ($detail === null || !$this->selectionVersionMatchesIdentity($detail, $identity)) {
                continue;
            }

            $summary = $this->summarizeSelectionVersion($detail, $identity);
            if ($withPrecheck) {
                $summary['rollback_precheck'] = $this->precheckLayoutSelectionRollback($versionId, $identity);
            }
            $versions[] = $summary;
            if ($limit > 0 && count($versions) >= $limit) {
                break;
            }
        }

        return $versions;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function precheckLayoutSelectionRollback(int $versionId, array $context = []): array
    {
        if ($versionId <= 0) {
            return ['rollbackable' => false, 'status' => 'invalid_version', 'version_id' => $versionId, 'blockers' => ['invalid_version']];
        }

        $detail = $this->systemConfig->getConfigVersionDetail($versionId);
        if ($detail === null) {
            return ['rollbackable' => false, 'status' => 'not_found', 'version_id' => $versionId, 'blockers' => ['not_found']];
        }

        $metadata = is_array($detail['metadata_data'] ?? null) ? $detail['metadata_data'] : [];
        $identity = $this->normalizeSelectionIdentity(
            (string)($context['target_type'] ?? $metadata['target_type'] ?? ''),
            (int)($context['target_id'] ?? $metadata['target_id'] ?? 0),
            (string)($context['layout_type'] ?? $metadata['layout_type'] ?? ''),
            isset($context['scope']) ? (string)$context['scope'] : (string)($detail[SystemConfigVersion::schema_fields_SCOPE] ?? self::DEFAULT_SCOPE),
            isset($context['locale']) ? (string)$context['locale'] : (string)($detail[SystemConfigVersion::schema_fields_LOCALE] ?? SystemConfig::LOCALE_DEFAULT)
        );

        $blockers = [];
        $conflicts = [];
        $expectedRestore = [];

        if (!$this->isValidSelectionIdentity($identity)) {
            $blockers[] = 'invalid_identity';
        }
        if ((string)($detail[SystemConfigVersion::schema_fields_MODULE] ?? '') !== self::MODULE_CODE) {
            $blockers[] = 'module_mismatch';
        }
        if ((string)($detail[SystemConfigVersion::schema_fields_AREA] ?? '') !== self::DEFAULT_AREA) {
            $blockers[] = 'area_mismatch';
        }
        if ((string)($detail[SystemConfigVersion::schema_fields_STATUS] ?? '') !== SystemConfigVersion::STATUS_APPLIED) {
            $blockers[] = 'version_not_applied';
        }
        if ($this->systemConfig->normalizeScope((string)($detail[SystemConfigVersion::schema_fields_SCOPE] ?? self::DEFAULT_SCOPE)) !== (string)$identity['scope']) {
            $blockers[] = 'scope_mismatch';
        }
        if ($this->systemConfig->normalizeLocale((string)($detail[SystemConfigVersion::schema_fields_LOCALE] ?? SystemConfig::LOCALE_DEFAULT)) !== (string)$identity['locale']) {
            $blockers[] = 'locale_mismatch';
        }
        if (!$this->selectionVersionMatchesIdentity($detail, $identity)) {
            $blockers[] = 'identity_mismatch';
        }

        $selectionKey = $this->selectionKey((string)$identity['target_type'], (int)$identity['target_id'], (string)$identity['layout_type']);
        $matchedChanges = $this->selectionChangesFromDetail($detail, $selectionKey);
        if ($matchedChanges === []) {
            $blockers[] = 'selection_key_not_found';
        }

        foreach ($matchedChanges as $change) {
            $oldRow = is_array($change['old_row'] ?? null) ? $change['old_row'] : null;
            $newRow = is_array($change['new_row'] ?? null) ? $change['new_row'] : null;
            $currentRow = $this->systemConfig->getScopedConfigRow(
                $selectionKey,
                self::MODULE_CODE,
                self::DEFAULT_AREA,
                (string)$identity['scope'],
                (string)$identity['locale']
            );

            $expectedVersion = (int)($newRow[SystemConfig::schema_fields_VERSION] ?? 0);
            $currentVersion = (int)($currentRow[SystemConfig::schema_fields_VERSION] ?? 0);
            if ($newRow === null && $currentRow !== null) {
                $conflicts[] = [
                    'key' => $selectionKey,
                    'expected_version' => 0,
                    'current_version' => $currentVersion,
                    'reason' => 'row_recreated_after_inherit',
                ];
                continue;
            }
            if ($expectedVersion > 0 && $currentVersion !== $expectedVersion) {
                $conflicts[] = [
                    'key' => $selectionKey,
                    'expected_version' => $expectedVersion,
                    'current_version' => $currentVersion,
                    'reason' => 'version_changed',
                ];
                continue;
            }

            if ($oldRow === null) {
                $expectedRestore[] = [
                    'key' => $selectionKey,
                    'action' => 'delete_override',
                    'layout_option' => null,
                ];
                continue;
            }

            $restoreOption = $this->normalizeLayoutOption((string)($oldRow[SystemConfig::schema_fields_VALUE] ?? ''));
            if ($restoreOption === '') {
                $blockers[] = 'empty_restore_option';
                continue;
            }
            if (!$this->isSelectableLayoutOption((string)$identity['layout_type'], $restoreOption, [
                'area' => self::DEFAULT_AREA,
                'scope' => (string)$identity['scope'],
                'target_type' => (string)$identity['target_type'],
                'target_id' => (int)$identity['target_id'],
            ])) {
                $blockers[] = 'layout_option_unavailable:' . $restoreOption;
                continue;
            }

            $expectedRestore[] = [
                'key' => $selectionKey,
                'action' => 'restore_option',
                'layout_option' => $restoreOption,
                'restore_row' => $this->systemConfig->maskSensitiveRow($oldRow),
            ];
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'rollbackable' => $blockers === [] && $conflicts === [],
            'status' => $blockers === [] && $conflicts === [] ? 'ready' : 'blocked',
            'version_id' => $versionId,
            'identity' => $identity,
            'selection_key' => $selectionKey,
            'blockers' => $blockers,
            'conflicts' => $conflicts,
            'expected_restore' => $expectedRestore,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function rollbackLayoutSelectionVersion(int $versionId, array $context = []): array
    {
        $precheck = $this->precheckLayoutSelectionRollback($versionId, $context);
        if (empty($precheck['rollbackable'])) {
            return [
                'success' => false,
                'status' => 'precheck_failed',
                'version_id' => $versionId,
                'precheck' => $precheck,
            ];
        }

        $result = $this->systemConfig->rollbackScopeConfigVersion($versionId, array_replace_recursive([
            'operation' => 'theme_layout_selection_rollback',
            'reason' => (string)__('回滚布局选择版本'),
            'metadata' => [
                'selection_rollback' => true,
                'selection_precheck' => $precheck,
            ],
        ], $context));
        $result['precheck'] = $precheck;
        if (!empty($result['success'])) {
            $this->clearRuntimeCaches(null, 'theme_virtual_layout_selection_rollback');
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $versionData
     * @return array<string,mixed>
     */
    public function saveSourceVersion(array $identity, string $sourceCode, array $versionData = [], bool $publish = true): array
    {
        $identity = $this->normalizeIdentity($identity);
        $sourceCode = trim($sourceCode);
        if ($sourceCode === '') {
            return ['success' => false, 'status' => 'empty_source', 'message' => (string)__('布局源码不能为空')];
        }
        if ($identity['layout_option'] === 'default') {
            return ['success' => false, 'status' => 'default_locked', 'message' => (string)__('默认布局不能作为虚拟布局覆盖')];
        }
        if ($this->containsForbiddenLayoutLogic($sourceCode)) {
            return ['success' => false, 'status' => 'forbidden_logic', 'message' => (string)__('布局模板只能保存页面骨架、slot、hook 和样式，不能包含前端业务交互脚本或直接请求')];
        }

        $asset = $this->loadOrCreateAsset($identity);
        $asset->setThemeId($identity['theme_id'])
            ->setArea($identity['area'])
            ->setLayoutType($identity['layout_type'])
            ->setLayoutOption($identity['layout_option'])
            ->setScope($identity['scope'])
            ->setTargetType($identity['target_type'])
            ->setTargetId($identity['target_id'])
            ->setSourceType((string)($versionData['source_type'] ?? ThemeVirtualLayout::SOURCE_TYPE_VIRTUAL))
            ->setName((string)($identity['name'] ?? $this->humanizeLayoutOption($identity['layout_option'])))
            ->setDescription((string)($identity['description'] ?? ''))
            ->setIsActive(true)
            ->setIsAiGenerated((bool)($versionData['is_ai_generated'] ?? false))
            ->setMetadata(is_array($identity['metadata'] ?? null) ? $identity['metadata'] : [])
            ->save();

        $version = clone $this->virtualLayoutVersion;
        $version->clearData()->clearQuery();
        $version->setVirtualLayoutId($asset->getId())
            ->setVersionNo($this->nextVersionNo($asset->getId()))
            ->setStatus(ThemeVirtualLayoutVersion::STATUS_DRAFT)
            ->setSourceCode($sourceCode)
            ->setVisualSchema(is_array($versionData['visual_schema'] ?? null) ? $versionData['visual_schema'] : [])
            ->setGenerationMeta(is_array($versionData['generation_meta'] ?? null) ? $versionData['generation_meta'] : [])
            ->setValidation(is_array($versionData['validation'] ?? null) ? $versionData['validation'] : [])
            ->setAiPrompt((string)($versionData['ai_prompt'] ?? ''))
            ->setParentVersionId((int)($versionData['parent_version_id'] ?? 0) ?: null)
            ->setActorId((string)($versionData['actor_id'] ?? ''))
            ->setActorName((string)($versionData['actor_name'] ?? ''))
            ->setReason((string)($versionData['reason'] ?? ''))
            ->save();

        if ($publish && !$this->publishVersion($version->getId())) {
            return [
                'success' => false,
                'status' => 'publish_failed',
                'message' => (string)__('虚拟布局版本保存成功，但发布失败，当前已发布版本保持不变'),
                'asset_id' => $asset->getId(),
                'version_id' => $version->getId(),
                'version_no' => $version->getVersionNo(),
                'identity' => $identity,
            ];
        }

        ThemeData::clearCache();

        return [
            'success' => true,
            'status' => $publish ? ThemeVirtualLayoutVersion::STATUS_PUBLISHED : ThemeVirtualLayoutVersion::STATUS_DRAFT,
            'asset_id' => $asset->getId(),
            'version_id' => $version->getId(),
            'version_no' => $version->getVersionNo(),
            'identity' => $identity,
        ];
    }

    public function publishVersion(int $versionId): bool
    {
        $version = $this->loadVersion($versionId);
        if (!$version || !$version->getId()) {
            return false;
        }
        $asset = $this->loadAssetById($version->getVirtualLayoutId());
        if (!$asset || !$asset->getId()) {
            return false;
        }

        $assetSnapshot = $this->snapshotAssetPublishState($asset);
        $versionStatusSnapshot = $this->snapshotVersionStatuses($asset->getId());

        try {
            foreach ($this->getVersionsByAsset($asset->getId()) as $versionRow) {
                $rowVersionId = (int)($versionRow[ThemeVirtualLayoutVersion::schema_fields_ID] ?? 0);
                if ($rowVersionId <= 0 || $rowVersionId === $version->getId()) {
                    continue;
                }
                if ((string)($versionRow[ThemeVirtualLayoutVersion::schema_fields_STATUS] ?? '') !== ThemeVirtualLayoutVersion::STATUS_PUBLISHED) {
                    continue;
                }
                $published = clone $this->virtualLayoutVersion;
                $published->clearData()->clearQuery()->load($rowVersionId);
                if ($published->getId()) {
                    $published->setStatus(ThemeVirtualLayoutVersion::STATUS_ARCHIVED)->save();
                }
            }

            $version->setStatus(ThemeVirtualLayoutVersion::STATUS_PUBLISHED)->save();
            $asset->setPublishedVersionId($version->getId())
                ->setVersion($version->getVersionNo())
                ->setIsActive(true)
                ->save();
        } catch (\Throwable $throwable) {
            $this->restorePublishState($asset->getId(), $assetSnapshot, $versionStatusSnapshot);
            ThemeData::clearCache();
            $this->clearRuntimeCaches((int)$assetSnapshot['theme_id'], 'theme_virtual_layout_publish_failed_restore');
            w_log_error('Publish virtual layout version failed and state was restored: {error}', [
                'error' => $throwable->getMessage(),
                'asset_id' => $asset->getId(),
                'version_id' => $version->getId(),
            ]);

            return false;
        }

        ThemeData::clearCache();
        $this->clearRuntimeCaches((int)$asset->getThemeId(), 'theme_virtual_layout_publish');

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rollbackPublishedVersion(int $assetId, int $targetVersionId, array $options = []): array
    {
        $asset = $this->loadAssetById($assetId);
        $targetVersion = $this->loadVersion($targetVersionId);
        if (!$asset || !$asset->getId() || !$targetVersion || !$targetVersion->getId()
            || $targetVersion->getVirtualLayoutId() !== $asset->getId()) {
            return ['success' => false, 'status' => 'not_found'];
        }

        return $this->saveSourceVersion([
            'theme_id' => $asset->getThemeId(),
            'area' => $asset->getArea(),
            'layout_type' => $asset->getLayoutType(),
            'layout_option' => $asset->getLayoutOption(),
            'scope' => $asset->getScope(),
            'target_type' => $asset->getTargetType(),
            'target_id' => $asset->getTargetId(),
            'name' => $asset->getName(),
            'description' => $asset->getDescription(),
            'metadata' => array_merge($asset->getMetadata(), [
                'rollback_from_version_id' => $asset->getPublishedVersionId(),
                'rollback_target_version_id' => $targetVersion->getId(),
            ]),
        ], $targetVersion->getSourceCode(), array_replace_recursive([
            'parent_version_id' => $targetVersion->getId(),
            'reason' => (string)__('回滚虚拟布局版本'),
            'generation_meta' => [
                'rollback_target_version_id' => $targetVersion->getId(),
            ],
        ], $options), true);
    }

    /**
     * @param list<array{target_type:string,target_id:int}> $targetChain
     * @return array<string,mixed>|null
     */
    public function resolvePublishedRuntimeLayout(
        string $layoutType,
        string $layoutOption,
        int $themeId = 0,
        string $area = self::DEFAULT_AREA,
        ?string $scope = null,
        array $targetChain = []
    ): ?array {
        $layoutType = $this->normalizeLayoutType($layoutType);
        $layoutOption = $this->normalizeLayoutOption($layoutOption);
        $area = $this->normalizeArea($area);
        $scope = $this->normalizeScope($scope);
        $themeId = $themeId > 0 ? $themeId : $this->getActiveThemeId($area);
        if ($themeId <= 0 || $layoutType === '' || $layoutOption === '') {
            return null;
        }

        $targets = $this->normalizeTargetChain($targetChain);
        $targets[] = ['target_type' => ThemeVirtualLayout::TARGET_GLOBAL, 'target_id' => 0];

        foreach ($targets as $target) {
            foreach ($this->systemConfig->getFallbackScopes($scope) as $fallbackScope) {
                $asset = $this->loadAssetByIdentity([
                    'theme_id' => $themeId,
                    'area' => $area,
                    'layout_type' => $layoutType,
                    'layout_option' => $layoutOption,
                    'scope' => $fallbackScope,
                    'target_type' => $target['target_type'],
                    'target_id' => $target['target_id'],
                ]);
                if (!$asset || !$asset->getPublishedVersionId()) {
                    continue;
                }
                $version = $this->loadVersion($asset->getPublishedVersionId());
                if (!$version || trim($version->getSourceCode()) === '') {
                    continue;
                }
                $runtime = $this->materializeRuntimeSource($version->getSourceCode(), $asset->getId(), $version->getId());

                return [
                    'asset_id' => $asset->getId(),
                    'version_id' => $version->getId(),
                    'version_no' => $version->getVersionNo(),
                    'theme_id' => $asset->getThemeId(),
                    'area' => $asset->getArea(),
                    'layout_type' => $asset->getLayoutType(),
                    'layout_option' => $asset->getLayoutOption(),
                    'scope' => $asset->getScope(),
                    'target_type' => $asset->getTargetType(),
                    'target_id' => $asset->getTargetId(),
                    'module_path' => $runtime['module_path'],
                    'file_path' => $runtime['file_path'],
                ];
            }
        }

        return null;
    }

    public function layoutExists(string $layoutType, string $layoutOption, array $identity = []): bool
    {
        return $this->resolveAssetForEdit($layoutType, $layoutOption, $identity) !== null;
    }

    public function loadEditableSource(string $layoutType, string $layoutOption, array $identity = []): ?string
    {
        $asset = $this->resolveAssetForEdit($layoutType, $layoutOption, $identity);
        if (!$asset) {
            return null;
        }
        $version = $this->loadLatestVersion($asset->getId());
        if (!$version || !$version->getId()) {
            return null;
        }

        return $version->getSourceCode();
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,mixed>|null
     */
    public function loadLatestVersionDetails(array $identity): ?array
    {
        $identity = $this->normalizeIdentity($identity);
        $asset = $this->resolveAssetForEdit(
            (string)$identity['layout_type'],
            (string)$identity['layout_option'],
            $identity
        );
        if (!$asset || !$asset->getId()) {
            return null;
        }

        $version = $this->loadLatestVersion($asset->getId());
        return $version ? $this->versionDetails($version, $asset) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadVersionDetails(int $versionId): ?array
    {
        $version = $this->loadVersion($versionId);
        if (!$version || !$version->getId()) {
            return null;
        }

        $asset = $this->loadAssetById($version->getVirtualLayoutId());
        return $this->versionDetails($version, $asset);
    }

    /**
     * @param array<string,mixed> $identity
     * @return list<array<string,mixed>>
     */
    public function listVersionDetails(array $identity): array
    {
        $identity = $this->normalizeIdentity($identity);
        $asset = $this->resolveAssetForEdit(
            (string)$identity['layout_type'],
            (string)$identity['layout_option'],
            $identity
        );
        if (!$asset || !$asset->getId()) {
            return [];
        }

        $versions = [];
        foreach ($this->getVersionsByAsset($asset->getId()) as $row) {
            $version = clone $this->virtualLayoutVersion;
            $version->clearData()->clearQuery()->setData($row);
            if ($version->getId()) {
                $versions[] = $this->versionDetails($version, $asset);
            }
        }

        return $versions;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function listLayoutOptions(string $layoutType, int $themeId = 0, string $area = self::DEFAULT_AREA, ?string $scope = null): array
    {
        $layoutType = $this->normalizeLayoutType($layoutType);
        $area = $this->normalizeArea($area);
        $themeId = $themeId > 0 ? $themeId : $this->getActiveThemeId($area);
        $scopeChain = $this->systemConfig->getFallbackScopes($scope);
        $rows = $this->virtualLayout->clear()->reset()
            ->where(ThemeVirtualLayout::schema_fields_THEME_ID, $themeId)
            ->where(ThemeVirtualLayout::schema_fields_AREA, $area)
            ->where(ThemeVirtualLayout::schema_fields_LAYOUT_TYPE, $layoutType)
            ->where(ThemeVirtualLayout::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();

        $options = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!in_array((string)($row[ThemeVirtualLayout::schema_fields_SCOPE] ?? ''), $scopeChain, true)) {
                continue;
            }
            $code = $this->normalizeLayoutOption((string)($row[ThemeVirtualLayout::schema_fields_LAYOUT_OPTION] ?? ''));
            if ($code === '' || isset($options[$code])) {
                continue;
            }
            $options[$code] = [
                'name' => (string)($row[ThemeVirtualLayout::schema_fields_NAME] ?? $this->humanizeLayoutOption($code)),
                'description' => (string)($row[ThemeVirtualLayout::schema_fields_DESCRIPTION] ?? ''),
                'template' => 'Weline_Theme::' . self::RUNTIME_MODULE_PREFIX . $code . '.phtml',
                'preview_image' => '',
                'source' => 'theme_virtual_layout',
                'asset_id' => (int)($row[ThemeVirtualLayout::schema_fields_ID] ?? 0),
                'published_version_id' => (int)($row[ThemeVirtualLayout::schema_fields_PUBLISHED_VERSION_ID] ?? 0),
                'scope' => (string)($row[ThemeVirtualLayout::schema_fields_SCOPE] ?? ''),
                'target_type' => (string)($row[ThemeVirtualLayout::schema_fields_TARGET_TYPE] ?? ThemeVirtualLayout::TARGET_GLOBAL),
                'target_id' => (int)($row[ThemeVirtualLayout::schema_fields_TARGET_ID] ?? 0),
                'config' => [],
            ];
        }
        ksort($options, SORT_STRING);

        return $options;
    }

    public function mapRuntimeViewPath(string $viewPath): ?string
    {
        $normalized = str_replace(['/', '\\'], DS, $viewPath);
        $marker = DS . 'view' . DS . self::RUNTIME_MODULE_PREFIX;
        $marker = str_replace(['/', '\\'], DS, $marker);
        $pos = strpos($normalized, $marker);
        if ($pos === false) {
            return null;
        }

        $relative = substr($normalized, $pos + strlen($marker));
        $relative = trim(str_replace(['/', '\\'], DS, $relative), DS);
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $filePath = $this->runtimeDirectory() . DS . $relative;
        return is_file($filePath) ? $filePath : null;
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    public function normalizeIdentity(array $identity): array
    {
        $area = $this->normalizeArea((string)($identity['area'] ?? self::DEFAULT_AREA));
        return [
            'theme_id' => (int)($identity['theme_id'] ?? 0) > 0 ? (int)$identity['theme_id'] : $this->getActiveThemeId($area),
            'area' => $area,
            'layout_type' => $this->normalizeLayoutType((string)($identity['layout_type'] ?? '')),
            'layout_option' => $this->normalizeLayoutOption((string)($identity['layout_option'] ?? '')),
            'scope' => $this->normalizeScope(isset($identity['scope']) ? (string)$identity['scope'] : null),
            'target_type' => $this->normalizeTargetType((string)($identity['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL)),
            'target_id' => max(0, (int)($identity['target_id'] ?? 0)),
            'name' => (string)($identity['name'] ?? ''),
            'description' => (string)($identity['description'] ?? ''),
            'metadata' => is_array($identity['metadata'] ?? null) ? $identity['metadata'] : [],
        ];
    }

    private function selectionKey(string $targetType, int $targetId, string $layoutType): string
    {
        return 'virtual_layout.selection.' . $this->normalizeTargetType($targetType) . '.' . max(0, $targetId) . '.' . $this->normalizeLayoutType($layoutType);
    }

    /**
     * @return array{target_type:string,target_id:int,layout_type:string,scope:string,locale:string}
     */
    private function normalizeSelectionIdentity(
        string $targetType,
        int $targetId,
        string $layoutType,
        ?string $scope = null,
        ?string $locale = null
    ): array {
        return [
            'target_type' => $this->normalizeTargetType($targetType),
            'target_id' => max(0, $targetId),
            'layout_type' => $this->normalizeLayoutType($layoutType),
            'scope' => $this->normalizeScope($scope),
            'locale' => $this->systemConfig->normalizeLocale($locale),
        ];
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function isValidSelectionIdentity(array $identity): bool
    {
        return (int)($identity['target_id'] ?? 0) > 0
            && (string)($identity['target_type'] ?? '') !== ThemeVirtualLayout::TARGET_GLOBAL
            && (string)($identity['layout_type'] ?? '') !== '';
    }

    /**
     * @param array<string,mixed> $detail
     * @param array<string,mixed> $identity
     */
    private function selectionVersionMatchesIdentity(array $detail, array $identity): bool
    {
        $metadata = is_array($detail['metadata_data'] ?? null) ? $detail['metadata_data'] : [];
        if (isset($metadata['target_type'], $metadata['target_id'], $metadata['layout_type'])) {
            return $this->normalizeTargetType((string)$metadata['target_type']) === (string)$identity['target_type']
                && (int)$metadata['target_id'] === (int)$identity['target_id']
                && $this->normalizeLayoutType((string)$metadata['layout_type']) === (string)$identity['layout_type'];
        }

        return $this->selectionChangesFromDetail(
            $detail,
            $this->selectionKey((string)$identity['target_type'], (int)$identity['target_id'], (string)$identity['layout_type'])
        ) !== [];
    }

    /**
     * @param array<string,mixed> $detail
     * @return list<array<string,mixed>>
     */
    private function selectionChangesFromDetail(array $detail, string $selectionKey): array
    {
        $matches = [];
        foreach (is_array($detail['changes'] ?? null) ? $detail['changes'] : [] as $change) {
            if (!is_array($change) || (string)($change['key'] ?? '') !== $selectionKey) {
                continue;
            }
            $matches[] = $change;
        }

        return $matches;
    }

    /**
     * @param array<string,mixed> $detail
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    private function summarizeSelectionVersion(array $detail, array $identity): array
    {
        $selectionKey = $this->selectionKey((string)$identity['target_type'], (int)$identity['target_id'], (string)$identity['layout_type']);
        $changes = $this->selectionChangesFromDetail($detail, $selectionKey);
        $change = $changes[0] ?? [];
        $oldRow = is_array($change['old_row'] ?? null) ? $change['old_row'] : null;
        $newRow = is_array($change['new_row'] ?? null) ? $change['new_row'] : null;

        return [
            'version_id' => (int)($detail[SystemConfigVersion::schema_fields_ID] ?? 0),
            'operation' => (string)($detail[SystemConfigVersion::schema_fields_OPERATION] ?? ''),
            'status' => (string)($detail[SystemConfigVersion::schema_fields_STATUS] ?? ''),
            'scope' => (string)($detail[SystemConfigVersion::schema_fields_SCOPE] ?? ''),
            'locale' => (string)($detail[SystemConfigVersion::schema_fields_LOCALE] ?? ''),
            'created_at' => (string)($detail[SystemConfigVersion::schema_fields_CREATED_AT] ?? ''),
            'actor_id' => (string)($detail[SystemConfigVersion::schema_fields_ACTOR_ID] ?? ''),
            'actor_name' => (string)($detail[SystemConfigVersion::schema_fields_ACTOR_NAME] ?? ''),
            'reason' => (string)($detail[SystemConfigVersion::schema_fields_REASON] ?? ''),
            'identity' => $identity,
            'selection_key' => $selectionKey,
            'old_layout_option' => $oldRow !== null ? $this->normalizeLayoutOption((string)($oldRow[SystemConfig::schema_fields_VALUE] ?? '')) : null,
            'new_layout_option' => $newRow !== null ? $this->normalizeLayoutOption((string)($newRow[SystemConfig::schema_fields_VALUE] ?? '')) : null,
            'old_row_version' => $oldRow !== null ? (int)($oldRow[SystemConfig::schema_fields_VERSION] ?? 0) : 0,
            'new_row_version' => $newRow !== null ? (int)($newRow[SystemConfig::schema_fields_VERSION] ?? 0) : 0,
            'metadata' => is_array($detail['metadata_data'] ?? null) ? $detail['metadata_data'] : [],
        ];
    }

    private function normalizeArea(string $area): string
    {
        return strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
    }

    private function normalizeLayoutType(string $layoutType): string
    {
        $layoutType = strtolower(trim($layoutType));
        $layoutType = preg_replace('/[^a-z0-9_-]+/', '_', $layoutType) ?? '';
        return trim($layoutType, '_');
    }

    private function normalizeTargetType(string $targetType): string
    {
        $targetType = strtolower(trim($targetType));
        return in_array($targetType, [
            ThemeVirtualLayout::TARGET_PRODUCT,
            ThemeVirtualLayout::TARGET_CATEGORY,
            ThemeVirtualLayout::TARGET_CATEGORY_PRODUCT_DEFAULT,
        ], true) ? $targetType : ThemeVirtualLayout::TARGET_GLOBAL;
    }

    private function getActiveThemeId(string $area): int
    {
        $theme = $this->getActiveTheme($area);
        return $theme ? (int)$theme->getId() : 0;
    }

    private function getActiveTheme(string $area): ?WelineTheme
    {
        try {
            $theme = clone $this->welineTheme;
            $theme->clearData()->clearQuery()->getActiveTheme($area);
            return $theme->getId() ? $theme : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function isSelectableLayoutOption(string $layoutType, string $layoutOption, array $identity): bool
    {
        $identity = $this->normalizeIdentity(array_merge($identity, [
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
        ]));

        if ($identity['layout_type'] === '' || $identity['layout_option'] === '') {
            return false;
        }

        foreach ($this->selectableLayoutTypeCandidates((string)$identity['layout_type']) as $candidateLayoutType) {
            if ($this->fileLayoutOptionExists($identity['area'], $candidateLayoutType, $identity['layout_option'])) {
                return true;
            }

            if ($this->resolveAssetForEdit(
                $candidateLayoutType,
                (string)$identity['layout_option'],
                array_merge($identity, ['layout_type' => $candidateLayoutType])
            ) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function selectableLayoutTypeCandidates(string $layoutType): array
    {
        $layoutType = $this->normalizeLayoutType($layoutType);
        $candidates = [$layoutType];
        if ($layoutType === ThemeVirtualLayout::TARGET_CATEGORY_PRODUCT_DEFAULT
            || $layoutType === 'product_detail') {
            $candidates[] = 'product';
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function fileLayoutOptionExists(string $area, string $layoutType, string $layoutOption): bool
    {
        $theme = $this->getActiveTheme($area);
        if (!$theme) {
            return false;
        }

        $modulePath = 'Weline_Theme::theme/' . $area . '/layouts/' . $layoutType . '/' . $layoutOption . '.phtml';
        return LayoutPathResolver::getLayoutFilePath($modulePath, $theme, $area) !== null;
    }

    private function loadOrCreateAsset(array $identity): ThemeVirtualLayout
    {
        return $this->loadAssetByIdentity($identity) ?? (clone $this->virtualLayout)->clearData()->clearQuery();
    }

    private function loadAssetById(int $assetId): ?ThemeVirtualLayout
    {
        if ($assetId <= 0) {
            return null;
        }
        $asset = clone $this->virtualLayout;
        $asset->clearData()->clearQuery()->load($assetId);
        return $asset->getId() ? $asset : null;
    }

    private function loadAssetByIdentity(array $identity): ?ThemeVirtualLayout
    {
        $rows = $this->virtualLayout->clear()->reset()
            ->where(ThemeVirtualLayout::schema_fields_THEME_ID, (int)$identity['theme_id'])
            ->where(ThemeVirtualLayout::schema_fields_AREA, (string)$identity['area'])
            ->where(ThemeVirtualLayout::schema_fields_LAYOUT_TYPE, (string)$identity['layout_type'])
            ->where(ThemeVirtualLayout::schema_fields_LAYOUT_OPTION, (string)$identity['layout_option'])
            ->where(ThemeVirtualLayout::schema_fields_SCOPE, (string)$identity['scope'])
            ->where(ThemeVirtualLayout::schema_fields_TARGET_TYPE, (string)$identity['target_type'])
            ->where(ThemeVirtualLayout::schema_fields_TARGET_ID, (int)$identity['target_id'])
            ->where(ThemeVirtualLayout::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();
        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        if (!is_array($row)) {
            return null;
        }

        $asset = clone $this->virtualLayout;
        $asset->clearData()->clearQuery()->setData($row);
        return $asset->getId() ? $asset : null;
    }

    private function resolveAssetForEdit(string $layoutType, string $layoutOption, array $identity = []): ?ThemeVirtualLayout
    {
        $identity = $this->normalizeIdentity(array_merge($identity, [
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
        ]));

        $targets = $this->normalizeTargetChain([[
            'target_type' => $identity['target_type'],
            'target_id' => $identity['target_id'],
        ]]);
        $targets[] = ['target_type' => ThemeVirtualLayout::TARGET_GLOBAL, 'target_id' => 0];

        foreach ($targets as $target) {
            foreach ($this->systemConfig->getFallbackScopes($identity['scope']) as $scope) {
                $asset = $this->loadAssetByIdentity(array_merge($identity, [
                    'scope' => $scope,
                    'target_type' => $target['target_type'],
                    'target_id' => $target['target_id'],
                ]));
                if ($asset) {
                    return $asset;
                }
            }
        }

        return null;
    }

    private function loadVersion(int $versionId): ?ThemeVirtualLayoutVersion
    {
        if ($versionId <= 0) {
            return null;
        }
        $version = clone $this->virtualLayoutVersion;
        $version->clearData()->clearQuery()->load($versionId);
        return $version->getId() ? $version : null;
    }

    private function loadLatestVersion(int $assetId): ?ThemeVirtualLayoutVersion
    {
        $rows = $this->virtualLayoutVersion->clear()->reset()
            ->where(ThemeVirtualLayoutVersion::schema_fields_VIRTUAL_LAYOUT_ID, $assetId)
            ->order(ThemeVirtualLayoutVersion::schema_fields_VERSION_NO, 'DESC')
            ->order(ThemeVirtualLayoutVersion::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();
        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        if (!is_array($row)) {
            return null;
        }

        $version = clone $this->virtualLayoutVersion;
        $version->clearData()->clearQuery()->setData($row);
        return $version->getId() ? $version : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function getVersionsByAsset(int $assetId): array
    {
        $rows = $this->virtualLayoutVersion->clear()->reset()
            ->where(ThemeVirtualLayoutVersion::schema_fields_VIRTUAL_LAYOUT_ID, $assetId)
            ->order(ThemeVirtualLayoutVersion::schema_fields_VERSION_NO, 'DESC')
            ->select()
            ->fetchArray();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array{published_version_id:int,version:int,is_active:bool,theme_id:int}
     */
    private function snapshotAssetPublishState(ThemeVirtualLayout $asset): array
    {
        return [
            'published_version_id' => $asset->getPublishedVersionId(),
            'version' => $asset->getVersion(),
            'is_active' => (bool)((int)$asset->getData(ThemeVirtualLayout::schema_fields_IS_ACTIVE)),
            'theme_id' => $asset->getThemeId(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function snapshotVersionStatuses(int $assetId): array
    {
        $statuses = [];
        foreach ($this->getVersionsByAsset($assetId) as $versionRow) {
            $rowVersionId = (int)($versionRow[ThemeVirtualLayoutVersion::schema_fields_ID] ?? 0);
            if ($rowVersionId <= 0) {
                continue;
            }
            $statuses[$rowVersionId] = (string)($versionRow[ThemeVirtualLayoutVersion::schema_fields_STATUS] ?? ThemeVirtualLayoutVersion::STATUS_DRAFT);
        }

        return $statuses;
    }

    /**
     * @param array{published_version_id:int,version:int,is_active:bool,theme_id:int} $assetSnapshot
     * @param array<int,string> $versionStatusSnapshot
     */
    private function restorePublishState(int $assetId, array $assetSnapshot, array $versionStatusSnapshot): void
    {
        foreach ($versionStatusSnapshot as $rowVersionId => $status) {
            try {
                $restoreVersion = clone $this->virtualLayoutVersion;
                $restoreVersion->clearData()->clearQuery()->load($rowVersionId);
                if ($restoreVersion->getId()) {
                    $restoreVersion->setStatus($status)->save();
                }
            } catch (\Throwable $throwable) {
                w_log_error('Restore virtual layout version status failed: {error}', [
                    'error' => $throwable->getMessage(),
                    'asset_id' => $assetId,
                    'version_id' => $rowVersionId,
                ]);
            }
        }

        try {
            $restoreAsset = $this->loadAssetById($assetId);
            if ($restoreAsset && $restoreAsset->getId()) {
                $restoreAsset->setPublishedVersionId($assetSnapshot['published_version_id'])
                    ->setVersion($assetSnapshot['version'])
                    ->setIsActive($assetSnapshot['is_active'])
                    ->save();
            }
        } catch (\Throwable $throwable) {
            w_log_error('Restore virtual layout asset publish pointer failed: {error}', [
                'error' => $throwable->getMessage(),
                'asset_id' => $assetId,
            ]);
        }
    }

    private function nextVersionNo(int $assetId): int
    {
        $latest = $this->loadLatestVersion($assetId);
        return $latest ? $latest->getVersionNo() + 1 : 1;
    }

    /**
     * @param list<array<string,mixed>> $targetChain
     * @return list<array{target_type:string,target_id:int}>
     */
    private function normalizeTargetChain(array $targetChain): array
    {
        $targets = [];
        foreach ($targetChain as $target) {
            if (!is_array($target)) {
                continue;
            }
            $targetType = $this->normalizeTargetType((string)($target['target_type'] ?? ''));
            $targetId = max(0, (int)($target['target_id'] ?? 0));
            if ($targetType === ThemeVirtualLayout::TARGET_GLOBAL || $targetId <= 0) {
                continue;
            }
            $key = $targetType . ':' . $targetId;
            $targets[$key] = ['target_type' => $targetType, 'target_id' => $targetId];
        }

        return array_values($targets);
    }

    /**
     * @return array{module_path:string,file_path:string}
     */
    private function materializeRuntimeSource(string $sourceCode, int $assetId, int $versionId): array
    {
        $hash = sha1($assetId . '|' . $versionId . '|' . $sourceCode);
        $fileName = $hash . '.phtml';
        $dir = $this->runtimeDirectory();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filePath = $dir . DS . $fileName;
        if (!is_file($filePath) || sha1((string)file_get_contents($filePath)) !== sha1(rtrim($sourceCode) . PHP_EOL)) {
            file_put_contents($filePath, rtrim($sourceCode) . PHP_EOL);
        }

        return [
            'module_path' => 'Weline_Theme::' . self::RUNTIME_MODULE_PREFIX . $fileName,
            'file_path' => $filePath,
        ];
    }

    private function runtimeDirectory(): string
    {
        return BP . 'var' . DS . 'runtime' . DS . self::RUNTIME_DIR;
    }

    private function containsForbiddenLayoutLogic(string $source): bool
    {
        foreach ([
            '/<script\b/i',
            '/\baddEventListener\s*\(/i',
            '/\bfetch\s*\(/i',
            '/\bXMLHttpRequest\b/i',
            '/\baxios\s*\./i',
            '/\bnew\s+EventSource\s*\(/i',
        ] as $pattern) {
            if (preg_match($pattern, $source)) {
                return true;
            }
        }

        return false;
    }

    private function clearRuntimeCaches(?int $themeId, string $reason): void
    {
        try {
            ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class)->clearNonGlobalCaches($themeId, $reason);
        } catch (\Throwable $e) {
            w_log_error('Clear virtual layout runtime cache failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function humanizeLayoutOption(string $layoutOption): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $layoutOption));
    }

    /**
     * @return array<string,mixed>
     */
    private function versionDetails(ThemeVirtualLayoutVersion $version, ?ThemeVirtualLayout $asset = null): array
    {
        return [
            'asset_id' => $asset?->getId() ?: $version->getVirtualLayoutId(),
            'version_id' => $version->getId(),
            'version_no' => $version->getVersionNo(),
            'status' => $version->getStatus(),
            'source_code' => $version->getSourceCode(),
            'visual_schema' => $version->getVisualSchema(),
            'generation_meta' => $version->getGenerationMeta(),
            'validation' => $version->getValidation(),
            'ai_prompt' => (string)($version->getData(ThemeVirtualLayoutVersion::schema_fields_AI_PROMPT) ?: ''),
            'parent_version_id' => (int)($version->getData(ThemeVirtualLayoutVersion::schema_fields_PARENT_VERSION_ID) ?: 0),
            'actor_id' => (string)($version->getData(ThemeVirtualLayoutVersion::schema_fields_ACTOR_ID) ?: ''),
            'actor_name' => (string)($version->getData(ThemeVirtualLayoutVersion::schema_fields_ACTOR_NAME) ?: ''),
            'reason' => (string)($version->getData(ThemeVirtualLayoutVersion::schema_fields_REASON) ?: ''),
            'published_version_id' => $asset?->getPublishedVersionId() ?? 0,
            'identity' => $asset ? [
                'theme_id' => $asset->getThemeId(),
                'area' => $asset->getArea(),
                'layout_type' => $asset->getLayoutType(),
                'layout_option' => $asset->getLayoutOption(),
                'scope' => $asset->getScope(),
                'target_type' => $asset->getTargetType(),
                'target_id' => $asset->getTargetId(),
                'name' => $asset->getName(),
                'description' => $asset->getDescription(),
                'metadata' => $asset->getMetadata(),
            ] : [],
        ];
    }
}
