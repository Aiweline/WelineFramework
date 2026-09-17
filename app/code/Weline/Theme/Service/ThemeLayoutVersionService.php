<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题布局版本管理服务
 * 
 * 提供版本控制功能：
 * - 保存新版本（快照当前工作区）
 * - 切换到历史版本
 * - 恢复原始布局（带自动备份）
 * - 发布版本
 * - 获取版本列表
 */
readonly class ThemeLayoutVersionService
{
    private const SNAPSHOT_I18N_KEY = '_i18n';

    public function __construct(
        private ThemeLayoutVersion $versionModel,
        private ThemeLayoutService $layoutService,
        private ThemeLayout $themeLayout,
        private WelineTheme $welineTheme,
    ) {}

    /**
     * @param array<string,mixed> $identity
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string,store_mode?:string,storage_scope?:string}
     */
    private function normalizeLayoutIdentity(array $identity = []): array
    {
        if (!isset($identity['target_type']) && isset($identity['theme_layout_target_type'])) {
            $identity['target_type'] = $identity['theme_layout_target_type'];
        }
        if (!isset($identity['target_id']) && isset($identity['theme_layout_target_id'])) {
            $identity['target_id'] = $identity['theme_layout_target_id'];
        }

        /** @var ThemeLayoutScopeNormalizer $normalizer */
        $normalizer = new ThemeLayoutScopeNormalizer(new \Weline\SystemConfig\Service\SystemConfigScopeResolver());

        return $normalizer->normalize($identity);
    }

    private function applyVersionIdentityFilters(mixed $query, array $identity): mixed
    {
        $identity = $this->normalizeLayoutIdentity($identity);

        return $query
            ->where(ThemeLayoutVersion::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
            ->where(ThemeLayoutVersion::schema_fields_SCOPE, $identity['scope'])
            ->where(ThemeLayoutVersion::schema_fields_LOCALE_CODE, $identity['locale_code'])
            ->where(ThemeLayoutVersion::schema_fields_TARGET_TYPE, $identity['target_type'])
            ->where(ThemeLayoutVersion::schema_fields_TARGET_ID, $identity['target_id']);
    }

    /**
     * 保存当前工作区为新版本
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param string|null $name 版本名称（可选）
     * @param string|null $description 版本描述（可选）
     * @param int|null $userId 创建者用户ID（可选）
     * @return ThemeLayoutVersion 创建的版本对象
     */
    public function saveVersion(
        int $themeId,
        string $pageType,
        ?string $name = null,
        ?string $description = null,
        ?int $userId = null,
        array $identity = [],
        string $type = ThemeLayoutVersion::TYPE_MANUAL,
    ): ThemeLayoutVersion {
        $identity = $this->normalizeLayoutIdentity($identity);
        /** @var ThemeRuntimeLayoutResolver $runtimeLayout */
        $runtimeLayout = ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class);
        $draftData = $runtimeLayout->resolveLayout(
            $themeId,
            $pageType,
            ThemeLayout::STATUS_DRAFT,
            'frontend',
            $identity,
        );
        $draftData = $this->attachTranslationSnapshot($draftData);

        return $this->saveSnapshotVersion(
            $themeId,
            $pageType,
            $draftData,
            $name,
            $description,
            $userId,
            $identity,
            $type,
        );
    }

    /** Save an already-normalized compatibility snapshot without rereading mutable draft rows. */
    public function saveSnapshotVersion(
        int $themeId,
        string $pageType,
        array $snapshotData,
        ?string $name = null,
        ?string $description = null,
        ?int $userId = null,
        array $identity = [],
        string $type = ThemeLayoutVersion::TYPE_MANUAL,
    ): ThemeLayoutVersion {
        $identity = $this->normalizeLayoutIdentity($identity);
        $allowedTypes = [
            ThemeLayoutVersion::TYPE_MANUAL,
            ThemeLayoutVersion::TYPE_AUTO_BACKUP,
            ThemeLayoutVersion::TYPE_RESTORE,
            ThemeLayoutVersion::TYPE_PUBLISH,
        ];
        $versionType = \in_array($type, $allowedTypes, true) ? $type : ThemeLayoutVersion::TYPE_MANUAL;

        $currentVersion = $this->getCurrentVersion($themeId, $pageType, $identity);
        $parentVersionId = $currentVersion?->getVersionId();
        $nextVersionNumber = $this->getNextVersionNumber($themeId, $pageType, $identity);
        $this->unsetCurrentVersion($themeId, $pageType, $identity);

        return $this->createVersion(
            themeId: $themeId,
            pageType: $pageType,
            versionNumber: $nextVersionNumber,
            snapshotData: $snapshotData,
            type: $versionType,
            name: $name ?: "v{$nextVersionNumber}",
            description: $description,
            parentVersionId: $parentVersionId,
            isCurrent: true,
            isPublished: false,
            userId: $userId,
            identity: $identity,
        );
    }

    /**
     * 切换到指定版本继续编辑
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int $versionId 目标版本ID
     * @return bool
     */
    public function switchToVersion(
        int $themeId,
        string $pageType,
        int $versionId,
        array $identity = [],
        ?array $snapshotOverride = null,
    ): bool
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        // 1. 加载目标版本
        $version = $this->versionModel->reset()->load($versionId);
        if (!$version->getVersionId()) {
            return false;
        }

        // 验证版本属于指定的主题和页面类型
        if ($version->getThemeId() !== $themeId
            || $version->getPageType() !== $pageType
            || $version->getLayoutOption() !== $identity['layout_option']
            || $version->getScope() !== $identity['scope']
            || $version->getLocaleCode() !== $identity['locale_code']
            || $version->getTargetType() !== $identity['target_type']
            || $version->getTargetId() !== $identity['target_id']) {
            return false;
        }

        // 2. 获取版本快照数据
        $snapshotData = $snapshotOverride ?? $version->getSnapshotData();

        // 3. 清空当前 draft
        $this->clearDraft($themeId, $pageType, $identity);

        // 4. 将快照恢复到 draft
        $this->restoreSnapshotToDraft($themeId, $pageType, $snapshotData, $identity);
        $this->restoreSnapshotTranslations($snapshotData);
        $this->clearRenderCaches();

        // 5. 更新 is_current 标记
        $this->unsetCurrentVersion($themeId, $pageType, $identity);
        $version->setIsCurrent(true)->save();

        return true;
    }

    /**
     * 恢复原始布局
     * 
     * 流程：
     * 1. 获取当前版本或 draft 数据作为备份来源
     * 2. 创建备份版本（如果有数据）
     * 3. 清空 draft 工作区
     * 4. 创建新的"原始布局"版本（空布局，不添加任何部件）
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int|null $userId 操作用户ID
     * @return array ['backup_version' => ThemeLayoutVersion, 'new_version' => ThemeLayoutVersion]
     */
    public function restoreOriginal(
        int $themeId,
        string $pageType,
        ?int $userId = null,
        array $identity = [],
        ?array $currentSnapshot = null,
    ): array
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        // 1. 获取当前版本
        $currentVersion = $this->getCurrentVersion($themeId, $pageType, $identity);
        
        $backupData = null;
        $backupSource = null;
        if ($currentSnapshot !== null) {
            foreach ($currentSnapshot as $areaData) {
                if (!empty($areaData['widgets'])) {
                    $backupData = $currentSnapshot;
                    $backupSource = 'scoped';
                    break;
                }
            }
        } else {
            // 旧路径优先使用 draft，如果 draft 为空则使用当前版本快照。
            $currentDraftData = $this->layoutService->getLayout(
                $themeId,
                $pageType,
                ThemeLayout::STATUS_DRAFT,
                $identity,
            );
            foreach ($currentDraftData as $areaData) {
                if (!empty($areaData['widgets'])) {
                    $backupData = $currentDraftData;
                    $backupSource = 'draft';
                    break;
                }
            }
        }
        if ($backupData === null && $currentSnapshot === null && $currentVersion) {
            $versionSnapshot = $currentVersion->getSnapshotData();
            $versionHasWidgets = false;
            if (is_array($versionSnapshot)) {
                foreach ($versionSnapshot as $area => $areaData) {
                    if (!empty($areaData['widgets'])) {
                        $versionHasWidgets = true;
                        break;
                    }
                }
            }
            if ($versionHasWidgets) {
                $backupData = $versionSnapshot;
                $backupSource = 'version';
            }
        }

        $backupVersion = null;

        // 4. 如果有数据需要备份，创建备份版本
        if ($backupData !== null) {
            $backupVersionNumber = $this->getNextVersionNumber($themeId, $pageType, $identity);

            $backupVersion = $this->createVersion(
                themeId: $themeId,
                pageType: $pageType,
                versionNumber: $backupVersionNumber,
                snapshotData: $backupData,
                type: ThemeLayoutVersion::TYPE_AUTO_BACKUP,
                name: __('备份') . ' - ' . date('Y-m-d H:i:s'),
                description: __('恢复原始布局前的自动备份') . ($backupSource === 'version' ? ' (' . __('来自版本') . ')' : ''),
                parentVersionId: $currentVersion?->getVersionId(),
                isCurrent: false,
                isPublished: false,
                userId: $userId,
                identity: $identity,
            );
        }

        // 5. 清空当前 draft
        $this->clearDraft($themeId, $pageType, $identity);

        // 6. 取消所有 is_current 标记（备份版本之后再取消，确保备份版本能正确引用父版本）
        $this->unsetCurrentVersion($themeId, $pageType, $identity);

        // 7. 创建新的"原始布局"版本（空快照）
        $emptySnapshot = $this->getEmptyLayoutSnapshot();
        $newVersionNumber = $this->getNextVersionNumber($themeId, $pageType, $identity);

        $newVersion = $this->createVersion(
            themeId: $themeId,
            pageType: $pageType,
            versionNumber: $newVersionNumber,
            snapshotData: $emptySnapshot,
            type: ThemeLayoutVersion::TYPE_RESTORE,
            name: __('原始布局'),
            description: __('恢复到主题模板的原始状态'),
            parentVersionId: $backupVersion?->getVersionId(),
            isCurrent: true,
            isPublished: false,
            userId: $userId,
            identity: $identity,
        );

        return [
            'backup_version' => $backupVersion,
            'new_version' => $newVersion,
        ];
    }

    /**
     * 发布版本
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int|null $versionId 要发布的版本ID，null则发布当前版本
     * @return bool
     */
    public function publishVersion(
        int $themeId,
        string $pageType,
        ?int $versionId = null,
        array $identity = [],
        array $publicationContext = [],
    ): bool
    {
        try {
            return $this->atomicLegacyPublish(function () use (
                $themeId,
                $pageType,
                $versionId,
                $identity,
                $publicationContext,
            ): bool {
                $identity = $this->normalizeLayoutIdentity($identity);
                $version = $this->resolvePublishVersion($themeId, $pageType, $versionId, $identity);
                if (!$version instanceof ThemeLayoutVersion) {
                    throw new \RuntimeException((string)__('待发布的 Theme 布局版本不存在。'));
                }

                // 兼容路径：将目标版本快照写入 draft，再由旧布局服务发布。
                // 整条路径必须位于同一写事务中；否则验证或投影失败会留下
                // 已替换的 draft 或半完成的 published 行。
                $snapshotData = $version->getSnapshotData();
                $this->clearDraft($themeId, $pageType, $identity);
                $this->restoreSnapshotToDraft($themeId, $pageType, $snapshotData, $identity);
                $this->restoreSnapshotTranslations($snapshotData);
                $this->clearRenderCaches();

                $result = $this->layoutService->publishLayout(
                    $themeId,
                    $pageType,
                    $identity,
                    true,
                    $publicationContext,
                );
                if (!$result) {
                    throw new \RuntimeException((string)__('Theme 旧版本发布校验或投影失败。'));
                }

                $this->markPublishedVersion($themeId, $pageType, $identity, $version);
                $this->updateStaticVersion($themeId);

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /** @template T @param callable():T $operation @return T */
    private function atomicLegacyPublish(callable $operation): mixed
    {
        /** @var WriteIntentTransactionCoordinatorInterface $transactions */
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $connection = $this->themeLayout->getConnection();
        if ($transactions->isActive($connection)) {
            if (!$transactions->isWriteIntent($connection)) {
                throw new \LogicException((string)__('Theme 旧版本发布必须位于写意图事务内。'));
            }
            return $transactions->withSavepoint($connection, 'theme_legacy_version_publish', $operation);
        }

        return $transactions->runWrite($connection, $operation);
    }

    /**
     * Scoped Release 已成为运行时权威后，仅同步旧版本列表的发布标记。
     *
     * 该方法不恢复快照、不写 theme_layout published，避免完整快照反向覆盖逐路径继承结果。
     */
    public function markVersionPublished(
        int $themeId,
        string $pageType,
        ?int $versionId = null,
        array $identity = [],
    ): bool {
        $identity = $this->normalizeLayoutIdentity($identity);
        $version = $this->resolvePublishVersion($themeId, $pageType, $versionId, $identity);
        if (!$version instanceof ThemeLayoutVersion) {
            return false;
        }

        $this->markPublishedVersion($themeId, $pageType, $identity, $version);
        $this->updateStaticVersion($themeId);

        return true;
    }

    /** @param array<string,mixed> $identity */
    private function resolvePublishVersion(
        int $themeId,
        string $pageType,
        ?int $versionId,
        array $identity,
    ): ?ThemeLayoutVersion {
        $version = $versionId
            ? $this->versionModel->reset()->load($versionId)
            : $this->getCurrentVersion($themeId, $pageType, $identity);
        if (!$version || !$version->getVersionId()) {
            $version = $this->saveVersion(
                $themeId,
                $pageType,
                null,
                __('发布时自动创建'),
                null,
                $identity,
                ThemeLayoutVersion::TYPE_PUBLISH,
            );
        }

        if ($version->getThemeId() !== $themeId
            || $version->getPageType() !== $pageType
            || $version->getLayoutOption() !== $identity['layout_option']
            || $version->getScope() !== $identity['scope']
            || $version->getLocaleCode() !== $identity['locale_code']
            || $version->getTargetType() !== $identity['target_type']
            || $version->getTargetId() !== $identity['target_id']) {
            return null;
        }

        return $version;
    }

    /** @param array<string,mixed> $identity */
    private function markPublishedVersion(
        int $themeId,
        string $pageType,
        array $identity,
        ThemeLayoutVersion $version,
    ): void {
        $this->unsetPublishedVersion($themeId, $pageType, $identity);
        // Storefront resolves nearest published version on the Scope fallback chain.
        // A stale published marker on a descendant Scope (e.g. store「原始布局」)
        // would shadow a newly published ancestor (e.g. website「test」).
        $this->unsetPublishedDescendantVersions($themeId, $pageType, $identity);
        $version->setIsPublished(true)->save();
    }

    /**
     * Clear is_published on descendant Scopes of the published identity.
     *
     * @param array<string,mixed> $identity
     */
    private function unsetPublishedDescendantVersions(int $themeId, string $pageType, array $identity): void
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        $ancestorScope = (string)($identity['scope'] ?? '');
        if ($ancestorScope === '') {
            return;
        }

        try {
            /** @var ScopeHierarchyInterface $scopes */
            $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
            $ancestorIdentity = $scopes->fromStorageScope($ancestorScope, true);
            if (!$ancestorIdentity instanceof ScopeIdentity) {
                return;
            }

            $rows = $this->versionModel->reset()
                ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayoutVersion::schema_fields_IS_PUBLISHED, 1)
                ->where(ThemeLayoutVersion::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeLayoutVersion::schema_fields_LOCALE_CODE, $identity['locale_code'])
                ->where(ThemeLayoutVersion::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeLayoutVersion::schema_fields_TARGET_ID, $identity['target_id'])
                ->select()
                ->fetchArray();

                $publishedRows = \is_array($rows) ? $rows : [];
            foreach ($publishedRows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $candidateScope = \trim((string)($row[ThemeLayoutVersion::schema_fields_SCOPE] ?? ''));
                if ($candidateScope === '' || $candidateScope === $ancestorScope) {
                    continue;
                }
                if (!$this->isDescendantStorageScope($scopes, $ancestorIdentity, $candidateScope)) {
                    continue;
                }
                $versionId = (int)($row[ThemeLayoutVersion::schema_fields_ID] ?? 0);
                if ($versionId <= 0) {
                    continue;
                }
                // Clone: $this->versionModel is often the same instance as the
                // markPublishedVersion() target; reset/load must not clobber it.
                $descendant = clone $this->versionModel;
                $descendant->reset()->load($versionId);
                if ($descendant->getVersionId() > 0) {
                    $descendant->setIsPublished(false)->save();
                }
            }
        } catch (\Throwable) {
            // Best-effort: ancestor published marker still applies for exact Scope matches.
        }
    }

    private function isDescendantStorageScope(
        ScopeHierarchyInterface $scopes,
        ScopeIdentity $ancestor,
        string $candidateStorageScope,
    ): bool {
        $cursor = $scopes->fromStorageScope($candidateStorageScope, true);
        if (!$cursor instanceof ScopeIdentity) {
            return false;
        }
        $ancestorKey = $ancestor->canonicalKey();
        while (($parent = $scopes->parentIdentity($cursor)) instanceof ScopeIdentity) {
            if ($parent->canonicalKey() === $ancestorKey) {
                return true;
            }
            $cursor = $parent;
        }

        return false;
    }
    
    /**
     * 按主题版本 bump 静态资源 URL 版本号（可视化/布局发布后调用）。
     *
     * 版本号格式：{themeVersion}_{timestamp}
     * 例如：1.0.0_1738500000
     *
     * @return string 写入的 theme.static_version；失败时返回空串
     */
    public function bumpStaticVersion(int $themeId): string
    {
        try {
            if ($themeId <= 0) {
                return '';
            }
            $theme = $this->welineTheme->reset()->load($themeId);
            $themeVersion = (string)($theme->getData('version') ?: '1.0.0');
            if ($themeVersion === '') {
                $themeVersion = '1.0.0';
            }

            $staticVersion = $themeVersion . '_' . time();
            // 顶层键：避免被 WelineTheme::setConfig('theme', row) 整表覆盖。
            Env::getInstance()->setConfig('theme_static_version', $staticVersion);
            // 兼容旧读者；可能被主题元数据刷新冲掉，不以它为准。
            try {
                Env::getInstance()->setConfig('theme.static_version', $staticVersion);
            } catch (\Throwable) {
            }

            return $staticVersion;
        } catch (\Throwable) {
            // 静默失败，不影响发布流程
            return '';
        }
    }

    /** @deprecated 使用 bumpStaticVersion */
    private function updateStaticVersion(int $themeId): void
    {
        $this->bumpStaticVersion($themeId);
    }

    /**
     * 获取版本列表
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int $limit 限制数量，0表示不限制
     * @return array 版本数组
     */
    public function getVersions(int $themeId, string $pageType, int $limit = 0, array $identity = []): array
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        $query = $this->versionModel->reset()
            ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType);
        $query = $this->applyVersionIdentityFilters($query, $identity)
            ->order(ThemeLayoutVersion::schema_fields_VERSION_NUMBER, 'DESC');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $results = $query->select()->fetchArray();

        if (!is_array($results)) {
            return [];
        }

        $versions = [];
        foreach ($results as $row) {
            $versionObj = clone $this->versionModel;
            $versionObj->setData($row);
            $versions[] = $versionObj->toArray();
        }

        return $versions;
    }

    public function getVersion(int $themeId, string $pageType, int $versionId, array $identity = []): ?ThemeLayoutVersion
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        if ($themeId <= 0 || $pageType === '' || $versionId <= 0) {
            return null;
        }

        $version = $this->versionModel->reset()->load($versionId);
        if (!$version->getVersionId()) {
            return null;
        }

        if ((int)$version->getThemeId() !== $themeId
            || (string)$version->getPageType() !== $pageType
            || $version->getLayoutOption() !== $identity['layout_option']
            || $version->getScope() !== $identity['scope']
            || $version->getLocaleCode() !== $identity['locale_code']
            || $version->getTargetType() !== $identity['target_type']
            || $version->getTargetId() !== $identity['target_id']) {
            return null;
        }

        return $version;
    }

    public function getVersionSnapshot(int $themeId, string $pageType, int $versionId, array $identity = []): ?array
    {
        $version = $this->getVersion($themeId, $pageType, $versionId, $identity);
        if (!$version) {
            return null;
        }

        $snapshot = $version->getSnapshotData();
        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * 获取当前版本
     */
    public function getCurrentVersion(int $themeId, string $pageType, array $identity = []): ?ThemeLayoutVersion
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        $result = $this->versionModel->reset()
            ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayoutVersion::schema_fields_IS_CURRENT, 1);
        $result = $this->applyVersionIdentityFilters($result, $identity)
            ->select()
            ->fetchArray();

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $row = is_array($result[0] ?? null) ? $result[0] : $result;
        $version = clone $this->versionModel;
        $version->setData($row);

        return $version->getVersionId() ? $version : null;
    }

    /**
     * 获取已发布版本
     */
    public function getPublishedVersion(int $themeId, string $pageType, array $identity = []): ?ThemeLayoutVersion
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        $result = $this->versionModel->reset()
            ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayoutVersion::schema_fields_IS_PUBLISHED, 1);
        $result = $this->applyVersionIdentityFilters($result, $identity)
            ->select()
            ->fetchArray();

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $row = is_array($result[0] ?? null) ? $result[0] : $result;
        $version = clone $this->versionModel;
        $version->setData($row);

        return $version->getVersionId() ? $version : null;
    }

    /**
     * Return the newest published version regardless of scope identity.
     *
     * This is a metadata-only fallback for runtime diagnostics after the
     * request scope chain has been exhausted; layout rendering still resolves
     * through the scoped workspace.
     */
    public function findAnyPublishedVersion(int $themeId, string $pageType): ?ThemeLayoutVersion
    {
        if ($themeId <= 0 || trim($pageType) === '') {
            return null;
        }

        $result = $this->versionModel->reset()
            ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayoutVersion::schema_fields_IS_PUBLISHED, 1)
            ->order(ThemeLayoutVersion::schema_fields_VERSION_NUMBER, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        if (!is_array($result) || count($result) === 0) {
            return null;
        }

        $row = is_array($result[0] ?? null) ? $result[0] : $result;
        $version = clone $this->versionModel;
        $version->setData($row);

        return $version->getVersionId() ? $version : null;
    }

    /**
     * 删除版本
     * 
     * @param int $versionId 版本ID
     * @return bool
     */
    public function deleteVersion(
        int $versionId,
        ?int $themeId = null,
        ?string $pageType = null,
        array $identity = []
    ): bool {
        if ($themeId !== null && $themeId > 0 && $pageType !== null && $pageType !== '') {
            $version = $this->getVersion($themeId, $pageType, $versionId, $identity);
            if (!$version) {
                return false;
            }
        } else {
            $version = $this->versionModel->reset()->load($versionId);
        }
        if (!$version->getVersionId()) {
            return false;
        }

        // 不允许删除当前版本
        if ($version->isCurrent()) {
            return false;
        }

        // 不允许删除已发布版本
        if ($version->isPublished()) {
            return false;
        }

        $version->delete()->fetch();
        return true;
    }

    /**
     * 重命名版本
     */
    public function renameVersion(
        int $versionId,
        string $newName,
        ?int $themeId = null,
        ?string $pageType = null,
        array $identity = []
    ): bool {
        if ($themeId !== null && $themeId > 0 && $pageType !== null && $pageType !== '') {
            $version = $this->getVersion($themeId, $pageType, $versionId, $identity);
            if (!$version) {
                return false;
            }
        } else {
            $version = $this->versionModel->reset()->load($versionId);
        }
        if (!$version->getVersionId()) {
            return false;
        }

        $version->setVersionName($newName)->save();
        return true;
    }

    /**
     * 初始化版本（首次进入编辑器时）
     * 
     * 如果没有版本记录，从当前 draft/published 数据创建 v1
     */
    public function initializeVersionIfNeeded(int $themeId, string $pageType, ?int $userId = null, array $identity = []): ?ThemeLayoutVersion
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        // 检查是否已有版本
        $versions = $this->getVersions($themeId, $pageType, 1, $identity);
        if (!empty($versions)) {
            return null; // 已有版本，无需初始化
        }

        // 获取当前布局数据（优先 draft，其次 published）
        $layoutData = $this->layoutService->getLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT, $identity);

        $hasWidgets = false;
        foreach ($layoutData as $area => $areaData) {
            if (!empty($areaData['widgets'])) {
                $hasWidgets = true;
                break;
            }
        }

        if (!$hasWidgets) {
            $layoutData = $this->layoutService->getLayout($themeId, $pageType, ThemeLayout::STATUS_PUBLISHED, $identity);
        }

        // 创建初始版本
        return $this->createVersion(
            themeId: $themeId,
            pageType: $pageType,
            versionNumber: 1,
            snapshotData: $layoutData,
            type: ThemeLayoutVersion::TYPE_MANUAL,
            name: 'v1',
            description: __('初始版本'),
            parentVersionId: null,
            isCurrent: true,
            isPublished: false,
            userId: $userId,
            identity: $identity,
        );
    }

    // ==================== 私有辅助方法 ====================

    /**
     * 创建版本记录
     */
    private function createVersion(
        int $themeId,
        string $pageType,
        int $versionNumber,
        array $snapshotData,
        string $type,
        ?string $name,
        ?string $description,
        ?int $parentVersionId,
        bool $isCurrent,
        bool $isPublished,
        ?int $userId,
        array $identity,
    ): ThemeLayoutVersion {
        $identity = $this->normalizeLayoutIdentity($identity);
        $version = clone $this->versionModel;
        $version->reset()
            ->clearData()
            ->setThemeId($themeId)
            ->setPageType($pageType)
            ->setLayoutOption($identity['layout_option'])
            ->setScope($identity['scope'])
            ->setLocaleCode($identity['locale_code'])
            ->setTargetType($identity['target_type'])
            ->setTargetId($identity['target_id'])
            ->setVersionNumber($versionNumber)
            ->setVersionName($name)
            ->setVersionType($type)
            ->setSnapshotData($snapshotData)
            ->setParentVersionId($parentVersionId)
            ->setIsCurrent($isCurrent)
            ->setIsPublished($isPublished)
            ->setCreatedBy($userId)
            ->setDescription($description)
            ->save();

        return $version;
    }

    public function peekNextVersionNumber(int $themeId, string $pageType, array $identity = []): int
    {
        return $this->getNextVersionNumber($themeId, $pageType, $identity);
    }

    /**
     * 获取下一个版本号
     */
    private function getNextVersionNumber(int $themeId, string $pageType, array $identity): int
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        $result = $this->versionModel->reset()
            ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType);
        $result = $this->applyVersionIdentityFilters($result, $identity)
            ->order(ThemeLayoutVersion::schema_fields_VERSION_NUMBER, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        if (!is_array($result) || count($result) === 0) {
            return 1;
        }

        $row = is_array($result[0] ?? null) ? $result[0] : $result;
        $maxNumber = (int)($row[ThemeLayoutVersion::schema_fields_VERSION_NUMBER] ?? 0);

        return $maxNumber + 1;
    }

    /**
     * 取消当前版本标记
     */
    private function unsetCurrentVersion(int $themeId, string $pageType, array $identity): void
    {
        try {
            $query = $this->versionModel->reset()
                ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayoutVersion::schema_fields_IS_CURRENT, 1);
            $this->applyVersionIdentityFilters($query, $identity)
                ->update([ThemeLayoutVersion::schema_fields_IS_CURRENT => 0])
                ->fetch();
        } catch (\Exception $e) {
            // 静默失败
        }
    }

    /**
     * 取消已发布版本标记
     */
    private function unsetPublishedVersion(int $themeId, string $pageType, array $identity): void
    {
        try {
            $query = $this->versionModel->reset()
                ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayoutVersion::schema_fields_IS_PUBLISHED, 1);
            $this->applyVersionIdentityFilters($query, $identity)
                ->update([ThemeLayoutVersion::schema_fields_IS_PUBLISHED => 0])
                ->fetch();
        } catch (\Exception $e) {
            // 静默失败
        }
    }

    /**
     * @param array<string, mixed> $layoutData
     */
    private function layoutHasWidgets(array $layoutData): bool
    {
        foreach ($layoutData as $areaData) {
            if (!empty($areaData['widgets'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 清空 draft 工作区
     */
    private function clearDraft(int $themeId, string $pageType, array $identity): void
    {
        try {
            $identity = $this->normalizeLayoutIdentity($identity);
            /** @var ThemeRuntimeLayoutResolver $runtimeLayout */
            $runtimeLayout = ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class);
            /** @var \Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService $layoutWriter */
            $layoutWriter = ObjectManager::getInstance(\Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService::class);
            $context = $runtimeLayout->buildContext($themeId, $pageType, 'frontend', $identity);
            $layoutWriter->clearDraftNodes($context, 'system:theme-layout-version', '');
        } catch (\Exception $e) {
            // 静默失败
        }
    }

    /**
     * 将快照数据恢复到 draft 工作区
     */
    private function restoreSnapshotToDraft(int $themeId, string $pageType, array $snapshotData, array $identity): void
    {
        $identity = $this->normalizeLayoutIdentity($identity);
        /** @var ThemeRuntimeLayoutResolver $runtimeLayout */
        $runtimeLayout = ObjectManager::getInstance(ThemeRuntimeLayoutResolver::class);
        /** @var \Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService $layoutWriter */
        $layoutWriter = ObjectManager::getInstance(\Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService::class);
        $context = $runtimeLayout->buildContext($themeId, $pageType, 'frontend', $identity);
        $layoutWriter->replaceDraftFromSnapshot(
            $context,
            $snapshotData,
            'system:theme-layout-version',
            '',
            'layout_draft_restored_from_version',
        );
    }

    private function attachTranslationSnapshot(array $layoutData): array
    {
        foreach ($layoutData as $area => $areaData) {
            if (empty($areaData['widgets']) || !is_array($areaData['widgets'])) {
                continue;
            }

            foreach ($areaData['widgets'] as $index => $widget) {
                if (!is_array($widget)) {
                    continue;
                }

                $identify = $this->resolveWidgetTranslationIdentify($widget, (string)$area);
                if ($identify === '') {
                    continue;
                }

                $rows = $this->collectTranslationRows($identify);
                if ($rows === []) {
                    continue;
                }

                $layoutData[$area]['widgets'][$index][self::SNAPSHOT_I18N_KEY] = [
                    'identify' => $identify,
                    'rows' => $rows,
                ];
            }
        }

        return $layoutData;
    }

    private function restoreSnapshotTranslations(array $snapshotData): void
    {
        $restored = false;
        foreach ($snapshotData as $areaData) {
            $widgets = is_array($areaData) ? ($areaData['widgets'] ?? []) : [];
            if (!is_array($widgets)) {
                continue;
            }

            foreach ($widgets as $widget) {
                if (!is_array($widget)) {
                    continue;
                }

                $translationSnapshot = $widget[self::SNAPSHOT_I18N_KEY] ?? null;
                $rows = is_array($translationSnapshot) ? ($translationSnapshot['rows'] ?? []) : [];
                if (!is_array($rows)) {
                    continue;
                }

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $word = (string)($row['word'] ?? '');
                    $locale = (string)($row['locale_code'] ?? '');
                    if ($word === '' || $locale === '') {
                        continue;
                    }

                    $this->saveDictionaryTranslation($word, $locale, (string)($row['translate'] ?? ''));
                    $restored = true;
                }
            }
        }

        if ($restored) {
            $this->clearRenderCaches();
        }
    }

    private function clearRenderCaches(): void
    {
        try {
            ThemeData::clearCache();
        } catch (\Throwable) {
        }

        try {
            ObjectManager::getInstance(SlotRendererService::class)->clearCache();
        } catch (\Throwable) {
        }
    }

    private function resolveWidgetTranslationIdentify(array $widget, string $area): string
    {
        $module = (string)($widget['widget_module'] ?? '');
        $code = (string)($widget['widget_code'] ?? '');
        $type = (string)($widget['widget_type'] ?? '');
        if ($module === '' || $code === '') {
            return '';
        }

        $config = is_array($widget['config'] ?? null) ? $widget['config'] : [];
        $instanceId = trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? ''));
        if ($instanceId !== '') {
            return $this->normalizeThemeIdentify(ThemeData::getWidgetInstanceIdentify($instanceId, $area), $area);
        }

        if ($module === 'Weline_Theme' && ($type === 'theme_component' || str_contains($code, '/'))) {
            /** @var ThemePlaceableRegistry $placeableRegistry */
            $placeableRegistry = ObjectManager::getInstance(ThemePlaceableRegistry::class);
            foreach (['frontend', $area] as $candidateArea) {
                $definition = $placeableRegistry->find($module, 'theme_component', $code, null, $candidateArea);
                if ($definition) {
                    return $this->normalizeThemeIdentify((string)$definition->getMetaIdentify(), $candidateArea);
                }
            }
        }

        return $this->normalizeThemeIdentify(ThemeData::getWidgetIdentify($module, $code, $area), $area);
    }

    private function collectTranslationRows(string $identify): array
    {
        $prefix = '@meta::' . $identify . '.';
        $translations = [];
        foreach ($this->dictionaryRepository()->listByWordPrefix($prefix) as $entry) {
            $word = $entry->word;
            $locale = $entry->localeCode;
            if ($word === '' || $locale === '') {
                continue;
            }
            $translations[] = [
                'word' => $word,
                'locale_code' => $locale,
                'translate' => $entry->translation,
            ];
        }

        return $translations;
    }

    private function saveDictionaryTranslation(string $word, string $localeCode, string $translate): void
    {
        $this->dictionaryRepository()->upsert($word, $localeCode, $translate);
    }

    private function dictionaryRepository(): DictionaryRepositoryInterface
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(DictionaryRepositoryInterface::class);
        if (!$provider instanceof DictionaryRepositoryInterface) {
            throw new \RuntimeException('Weline_I18n dictionary repository provider is unavailable.');
        }
        return $provider;
    }

    private function normalizeThemeIdentify(string $identify, string $area): string
    {
        $identify = trim($identify);
        if ($identify === '') {
            return '';
        }
        if (preg_match('/^theme\.(frontend|backend)\./', $identify)) {
            return $identify;
        }

        $area = strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
        if (str_starts_with($identify, 'theme.')) {
            return 'theme.' . $area . '.' . substr($identify, 6);
        }

        if (preg_match('/^(frontend|backend)\./', $identify)) {
            return 'theme.' . $identify;
        }

        return 'theme.' . $area . '.' . $identify;
    }

    /**
     * 获取空的布局快照结构
     */
    private function getEmptyLayoutSnapshot(): array
    {
        $snapshot = [];
        foreach (ThemeLayout::getAreas() as $areaCode => $areaLabel) {
            $snapshot[$areaCode] = [
                'label' => $areaLabel,
                'widgets' => [],
            ];
        }
        return $snapshot;
    }
}
