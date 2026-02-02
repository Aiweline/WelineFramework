<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 主题布局版本模型
 * 存储主题布局的历史版本快照
 */
class ThemeLayoutVersion extends Model
{
    // 设置主键字段
    public string $_primary_key = 'version_id';

    // 字段常量
    public const fields_ID = 'version_id';
    public const fields_THEME_ID = 'theme_id';
    public const fields_PAGE_TYPE = 'page_type';
    public const fields_VERSION_NUMBER = 'version_number';
    public const fields_VERSION_NAME = 'version_name';
    public const fields_VERSION_TYPE = 'version_type';
    public const fields_SNAPSHOT_DATA = 'snapshot_data';
    public const fields_PARENT_VERSION_ID = 'parent_version_id';
    public const fields_IS_CURRENT = 'is_current';
    public const fields_IS_PUBLISHED = 'is_published';
    public const fields_CREATED_BY = 'created_by';
    public const fields_DESCRIPTION = 'description';

    // 版本类型常量
    public const TYPE_MANUAL = 'manual';           // 手动保存
    public const TYPE_AUTO_BACKUP = 'auto_backup'; // 自动备份（恢复前）
    public const TYPE_RESTORE = 'restore';         // 恢复原始布局
    public const TYPE_PUBLISH = 'publish';         // 发布时自动创建

    /**
     * 获取所有版本类型及其标签
     */
    public static function getVersionTypes(): array
    {
        return [
            self::TYPE_MANUAL => __('手动保存'),
            self::TYPE_AUTO_BACKUP => __('自动备份'),
            self::TYPE_RESTORE => __('恢复原始'),
            self::TYPE_PUBLISH => __('发布快照'),
        ];
    }

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 未来扩展预留
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('主题布局版本表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'UNSIGNED primary key AUTO_INCREMENT',
                '版本ID'
            )
            ->addColumn(
                self::fields_THEME_ID,
                TableInterface::column_type_INTEGER,
                11,
                'UNSIGNED NOT NULL',
                '主题ID'
            )
            ->addColumn(
                self::fields_PAGE_TYPE,
                TableInterface::column_type_VARCHAR,
                50,
                "NOT NULL DEFAULT 'homepage'",
                '页面/布局类型'
            )
            ->addColumn(
                self::fields_VERSION_NUMBER,
                TableInterface::column_type_INTEGER,
                11,
                'UNSIGNED NOT NULL DEFAULT 1',
                '版本号'
            )
            ->addColumn(
                self::fields_VERSION_NAME,
                TableInterface::column_type_VARCHAR,
                100,
                'DEFAULT NULL',
                '版本名称（可自定义）'
            )
            ->addColumn(
                self::fields_VERSION_TYPE,
                TableInterface::column_type_VARCHAR,
                20,
                "NOT NULL DEFAULT 'manual'",
                '版本类型：manual/auto_backup/restore/publish'
            )
            ->addColumn(
                self::fields_SNAPSHOT_DATA,
                TableInterface::column_type_LONG_TEXT,
                null,
                '',
                'JSON快照数据（完整布局配置）'
            )
            ->addColumn(
                self::fields_PARENT_VERSION_ID,
                TableInterface::column_type_INTEGER,
                11,
                'UNSIGNED DEFAULT NULL',
                '父版本ID（用于版本树）'
            )
            ->addColumn(
                self::fields_IS_CURRENT,
                TableInterface::column_type_SMALLINT,
                1,
                'UNSIGNED NOT NULL DEFAULT 0',
                '是否为当前编辑版本'
            )
            ->addColumn(
                self::fields_IS_PUBLISHED,
                TableInterface::column_type_SMALLINT,
                1,
                'UNSIGNED NOT NULL DEFAULT 0',
                '是否为已发布版本'
            )
            ->addColumn(
                self::fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                null,
                'NOT NULL DEFAULT CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_CREATED_BY,
                TableInterface::column_type_INTEGER,
                11,
                'UNSIGNED DEFAULT NULL',
                '创建者用户ID'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                TableInterface::column_type_TEXT,
                null,
                '',
                '版本描述/变更说明'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_theme_page',
                [self::fields_THEME_ID, self::fields_PAGE_TYPE]
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_version_number',
                [self::fields_THEME_ID, self::fields_PAGE_TYPE, self::fields_VERSION_NUMBER]
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_current',
                [self::fields_THEME_ID, self::fields_PAGE_TYPE, self::fields_IS_CURRENT]
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_published',
                [self::fields_THEME_ID, self::fields_PAGE_TYPE, self::fields_IS_PUBLISHED]
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_type',
                [self::fields_VERSION_TYPE]
            )
            ->create();
    }

    // ==================== Getters & Setters ====================

    public function getVersionId(): int
    {
        return (int)$this->getData(self::fields_ID);
    }

    public function setVersionId(int $id): self
    {
        return $this->setData(self::fields_ID, $id);
    }

    public function getThemeId(): int
    {
        return (int)$this->getData(self::fields_THEME_ID);
    }

    public function setThemeId(int $themeId): self
    {
        return $this->setData(self::fields_THEME_ID, $themeId);
    }

    public function getPageType(): string
    {
        return (string)$this->getData(self::fields_PAGE_TYPE);
    }

    public function setPageType(string $pageType): self
    {
        return $this->setData(self::fields_PAGE_TYPE, $pageType);
    }

    public function getVersionNumber(): int
    {
        return (int)$this->getData(self::fields_VERSION_NUMBER);
    }

    public function setVersionNumber(int $number): self
    {
        return $this->setData(self::fields_VERSION_NUMBER, $number);
    }

    public function getVersionName(): ?string
    {
        $name = $this->getData(self::fields_VERSION_NAME);
        return $name ? (string)$name : null;
    }

    public function setVersionName(?string $name): self
    {
        return $this->setData(self::fields_VERSION_NAME, $name);
    }

    public function getVersionType(): string
    {
        return (string)($this->getData(self::fields_VERSION_TYPE) ?: self::TYPE_MANUAL);
    }

    public function setVersionType(string $type): self
    {
        return $this->setData(self::fields_VERSION_TYPE, $type);
    }

    /**
     * 获取快照数据（解析 JSON）
     */
    public function getSnapshotData(): array
    {
        $data = $this->getData(self::fields_SNAPSHOT_DATA);
        if (empty($data)) {
            return [];
        }
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * 设置快照数据（自动 JSON 编码）
     */
    public function setSnapshotData(array $data): self
    {
        return $this->setData(
            self::fields_SNAPSHOT_DATA,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function getParentVersionId(): ?int
    {
        $id = $this->getData(self::fields_PARENT_VERSION_ID);
        return $id ? (int)$id : null;
    }

    public function setParentVersionId(?int $id): self
    {
        return $this->setData(self::fields_PARENT_VERSION_ID, $id);
    }

    public function isCurrent(): bool
    {
        return (bool)$this->getData(self::fields_IS_CURRENT);
    }

    public function setIsCurrent(bool $current): self
    {
        return $this->setData(self::fields_IS_CURRENT, $current ? 1 : 0);
    }

    public function isPublished(): bool
    {
        return (bool)$this->getData(self::fields_IS_PUBLISHED);
    }

    public function setIsPublished(bool $published): self
    {
        return $this->setData(self::fields_IS_PUBLISHED, $published ? 1 : 0);
    }

    public function getCreatedBy(): ?int
    {
        $id = $this->getData(self::fields_CREATED_BY);
        return $id ? (int)$id : null;
    }

    public function setCreatedBy(?int $userId): self
    {
        return $this->setData(self::fields_CREATED_BY, $userId);
    }

    public function getDescription(): ?string
    {
        $desc = $this->getData(self::fields_DESCRIPTION);
        return $desc ? (string)$desc : null;
    }

    public function setDescription(?string $description): self
    {
        return $this->setData(self::fields_DESCRIPTION, $description);
    }

    // ==================== 辅助方法 ====================

    /**
     * 获取版本显示名称
     * 如果没有自定义名称，返回 "v{版本号}"
     */
    public function getDisplayName(): string
    {
        $name = $this->getVersionName();
        if ($name) {
            return $name;
        }
        return 'v' . $this->getVersionNumber();
    }

    /**
     * 获取版本类型标签
     */
    public function getVersionTypeLabel(): string
    {
        $types = self::getVersionTypes();
        return (string)($types[$this->getVersionType()] ?? $this->getVersionType());
    }

    /**
     * 检查是否为自动备份类型
     */
    public function isAutoBackup(): bool
    {
        return $this->getVersionType() === self::TYPE_AUTO_BACKUP;
    }

    /**
     * 检查是否为恢复原始类型
     */
    public function isRestoreType(): bool
    {
        return $this->getVersionType() === self::TYPE_RESTORE;
    }

    /**
     * 将模型数据转换为数组（用于 API 响应）
     * 
     * @param array $keys 可选的键过滤
     */
    public function toArray(array $keys = []): array
    {
        $data = [
            'version_id' => $this->getVersionId(),
            'theme_id' => $this->getThemeId(),
            'page_type' => $this->getPageType(),
            'version_number' => $this->getVersionNumber(),
            'version_name' => $this->getVersionName(),
            'display_name' => $this->getDisplayName(),
            'version_type' => $this->getVersionType(),
            'version_type_label' => $this->getVersionTypeLabel(),
            'is_current' => $this->isCurrent(),
            'is_published' => $this->isPublished(),
            'created_at' => $this->getData(self::fields_CREATE_TIME),
            'created_by' => $this->getCreatedBy(),
            'description' => $this->getDescription(),
            'is_auto_backup' => $this->isAutoBackup(),
        ];
        
        if (!empty($keys)) {
            return array_intersect_key($data, array_flip($keys));
        }
        
        return $data;
    }
}
