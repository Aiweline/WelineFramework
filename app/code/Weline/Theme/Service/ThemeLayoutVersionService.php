<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
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
    public function __construct(
        private ThemeLayoutVersion $versionModel,
        private ThemeLayoutService $layoutService,
        private ThemeLayout $themeLayout,
        private WelineTheme $welineTheme,
    ) {}

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
    ): ThemeLayoutVersion {
        // 1. 获取当前 draft 数据
        $draftData = $this->layoutService->getLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT);

        // 2. 获取当前版本作为父版本
        $currentVersion = $this->getCurrentVersion($themeId, $pageType);
        $parentVersionId = $currentVersion?->getVersionId();

        // 3. 获取下一个版本号
        $nextVersionNumber = $this->getNextVersionNumber($themeId, $pageType);

        // 4. 取消旧版本的 is_current 标记
        $this->unsetCurrentVersion($themeId, $pageType);

        // 5. 创建新版本
        $version = $this->createVersion(
            themeId: $themeId,
            pageType: $pageType,
            versionNumber: $nextVersionNumber,
            snapshotData: $draftData,
            type: ThemeLayoutVersion::TYPE_MANUAL,
            name: $name ?: "v{$nextVersionNumber}",
            description: $description,
            parentVersionId: $parentVersionId,
            isCurrent: true,
            isPublished: false,
            userId: $userId,
        );

        return $version;
    }

    /**
     * 切换到指定版本继续编辑
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int $versionId 目标版本ID
     * @return bool
     */
    public function switchToVersion(int $themeId, string $pageType, int $versionId): bool
    {
        // 1. 加载目标版本
        $version = $this->versionModel->reset()->load($versionId);
        if (!$version->getVersionId()) {
            return false;
        }

        // 验证版本属于指定的主题和页面类型
        if ($version->getThemeId() !== $themeId || $version->getPageType() !== $pageType) {
            return false;
        }

        // 2. 获取版本快照数据
        $snapshotData = $version->getSnapshotData();

        // 3. 清空当前 draft
        $this->clearDraft($themeId, $pageType);

        // 4. 将快照恢复到 draft
        $this->restoreSnapshotToDraft($themeId, $pageType, $snapshotData);

        // 5. 更新 is_current 标记
        $this->unsetCurrentVersion($themeId, $pageType);
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
    public function restoreOriginal(int $themeId, string $pageType, ?int $userId = null): array
    {
        // 1. 获取当前版本
        $currentVersion = $this->getCurrentVersion($themeId, $pageType);
        
        // 2. 获取当前 draft 数据
        $currentDraftData = $this->layoutService->getLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT);

        // 3. 确定备份数据来源：优先使用 draft，如果 draft 为空则使用当前版本的快照
        $backupData = null;
        $backupSource = null;
        
        // 检查 draft 是否有 widgets
        $draftHasWidgets = false;
        foreach ($currentDraftData as $area => $areaData) {
            if (!empty($areaData['widgets'])) {
                $draftHasWidgets = true;
                break;
            }
        }
        
        if ($draftHasWidgets) {
            // Draft 有数据，使用 draft 作为备份
            $backupData = $currentDraftData;
            $backupSource = 'draft';
        } elseif ($currentVersion) {
            // Draft 为空，但有当前版本，使用当前版本的快照作为备份
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
            $backupVersionNumber = $this->getNextVersionNumber($themeId, $pageType);

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
            );
        }

        // 5. 清空当前 draft
        $this->clearDraft($themeId, $pageType);

        // 6. 取消所有 is_current 标记（备份版本之后再取消，确保备份版本能正确引用父版本）
        $this->unsetCurrentVersion($themeId, $pageType);

        // 7. 创建新的"原始布局"版本（空快照）
        $emptySnapshot = $this->getEmptyLayoutSnapshot();
        $newVersionNumber = $this->getNextVersionNumber($themeId, $pageType);

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
    public function publishVersion(int $themeId, string $pageType, ?int $versionId = null): bool
    {
        // 获取要发布的版本
        if ($versionId) {
            $version = $this->versionModel->reset()->load($versionId);
        } else {
            $version = $this->getCurrentVersion($themeId, $pageType);
        }

        if (!$version || !$version->getVersionId()) {
            // 如果没有版本，先保存当前工作区为新版本
            $version = $this->saveVersion($themeId, $pageType, null, __('发布时自动创建'));
        }

        // 1. 取消旧的 is_published 标记
        $this->unsetPublishedVersion($themeId, $pageType);

        // 2. 标记当前版本为已发布
        $version->setIsPublished(true)->save();

        // 3. 使用现有的发布逻辑（draft -> published）
        $result = $this->layoutService->publishLayout($themeId, $pageType);
        
        // 4. 更新静态资源版本号
        if ($result) {
            $this->updateStaticVersion($themeId);
        }

        return $result;
    }
    
    /**
     * 更新静态资源版本号
     * 
     * 版本号格式：{themeVersion}_{timestamp}
     * 例如：1.0.0_1738500000
     * 
     * @param int $themeId 主题ID
     */
    private function updateStaticVersion(int $themeId): void
    {
        try {
            // 获取主题版本号
            $theme = $this->welineTheme->reset()->load($themeId);
            $themeVersion = $theme->getData('version') ?: '1.0.0';
            
            // 生成静态版本号：主题版本_时间戳
            $staticVersion = $themeVersion . '_' . time();
            
            // 保存到系统配置
            Env::getInstance()->setConfig('theme.static_version', $staticVersion);
        } catch (\Exception $e) {
            // 静默失败，不影响发布流程
        }
    }

    /**
     * 获取版本列表
     *
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int $limit 限制数量，0表示不限制
     * @return array 版本数组
     */
    public function getVersions(int $themeId, string $pageType, int $limit = 0): array
    {
        $query = $this->versionModel->reset()
            ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
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

    public function getVersion(int $themeId, string $pageType, int $versionId): ?ThemeLayoutVersion
    {
        if ($themeId <= 0 || $pageType === '' || $versionId <= 0) {
            return null;
        }

        $version = $this->versionModel->reset()->load($versionId);
        if (!$version->getVersionId()) {
            return null;
        }

        if ((int)$version->getThemeId() !== $themeId || (string)$version->getPageType() !== $pageType) {
            return null;
        }

        return $version;
    }

    public function getVersionSnapshot(int $themeId, string $pageType, int $versionId): ?array
    {
        $version = $this->getVersion($themeId, $pageType, $versionId);
        if (!$version) {
            return null;
        }

        $snapshot = $version->getSnapshotData();
        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * 获取当前版本
     */
    public function getCurrentVersion(int $themeId, string $pageType): ?ThemeLayoutVersion
    {
        $result = $this->versionModel->reset()
            ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayoutVersion::schema_fields_IS_CURRENT, 1)
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
    public function getPublishedVersion(int $themeId, string $pageType): ?ThemeLayoutVersion
    {
        $result = $this->versionModel->reset()
            ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
            ->where(ThemeLayoutVersion::schema_fields_IS_PUBLISHED, 1)
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
    public function deleteVersion(int $versionId): bool
    {
        $version = $this->versionModel->reset()->load($versionId);
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
    public function renameVersion(int $versionId, string $newName): bool
    {
        $version = $this->versionModel->reset()->load($versionId);
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
    public function initializeVersionIfNeeded(int $themeId, string $pageType, ?int $userId = null): ?ThemeLayoutVersion
    {
        // 检查是否已有版本
        $versions = $this->getVersions($themeId, $pageType, 1);
        if (!empty($versions)) {
            return null; // 已有版本，无需初始化
        }

        // 获取当前布局数据（优先 draft，其次 published）
        $layoutData = $this->layoutService->getLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT);

        $hasWidgets = false;
        foreach ($layoutData as $area => $areaData) {
            if (!empty($areaData['widgets'])) {
                $hasWidgets = true;
                break;
            }
        }

        if (!$hasWidgets) {
            $layoutData = $this->layoutService->getLayout($themeId, $pageType, ThemeLayout::STATUS_PUBLISHED);
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
    ): ThemeLayoutVersion {
        $version = clone $this->versionModel;
        $version->reset()
            ->setThemeId($themeId)
            ->setPageType($pageType)
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

    /**
     * 获取下一个版本号
     */
    private function getNextVersionNumber(int $themeId, string $pageType): int
    {
        $result = $this->versionModel->reset()
            ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
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
    private function unsetCurrentVersion(int $themeId, string $pageType): void
    {
        try {
            $this->versionModel->reset()
                ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayoutVersion::schema_fields_IS_CURRENT, 1)
                ->update([ThemeLayoutVersion::schema_fields_IS_CURRENT => 0])
                ->fetch();
        } catch (\Exception $e) {
            // 静默失败
        }
    }

    /**
     * 取消已发布版本标记
     */
    private function unsetPublishedVersion(int $themeId, string $pageType): void
    {
        try {
            $this->versionModel->reset()
                ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayoutVersion::schema_fields_IS_PUBLISHED, 1)
                ->update([ThemeLayoutVersion::schema_fields_IS_PUBLISHED => 0])
                ->fetch();
        } catch (\Exception $e) {
            // 静默失败
        }
    }

    /**
     * 清空 draft 工作区
     */
    private function clearDraft(int $themeId, string $pageType): void
    {
        try {
            $this->themeLayout->reset()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::schema_fields_STATUS, ThemeLayout::STATUS_DRAFT)
                ->delete()
                ->fetch();
        } catch (\Exception $e) {
            // 静默失败
        }
    }

    /**
     * 将快照数据恢复到 draft 工作区
     */
    private function restoreSnapshotToDraft(int $themeId, string $pageType, array $snapshotData): void
    {
        foreach ($snapshotData as $area => $areaData) {
            $widgets = $areaData['widgets'] ?? [];
            foreach ($widgets as $widget) {
                $this->layoutService->saveWidget([
                    'theme_id' => $themeId,
                    'page_type' => $pageType,
                    'area' => $area,
                    'widget_code' => $widget['widget_code'] ?? '',
                    'widget_module' => $widget['widget_module'] ?? '',
                    'widget_type' => $widget['widget_type'] ?? '',
                    'slot_id' => $widget['slot_id'] ?? null,
                    'config' => $widget['config'] ?? [],
                    'sort_order' => $widget['sort_order'] ?? 0,
                    'is_active' => true,
                    'status' => ThemeLayout::STATUS_DRAFT,
                ]);
            }
        }
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
