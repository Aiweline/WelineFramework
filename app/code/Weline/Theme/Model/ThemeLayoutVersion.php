<?php
declare(strict_types=1);
namespace Weline\Theme\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 主题布局版本模型 - 存储主题布局的历史版本快照 */
#[Table(comment: '主题布局版本表')]
#[Index(name: 'idx_theme_page', columns: ['theme_id', 'page_type'])]
#[Index(name: 'idx_version_number', columns: ['theme_id', 'page_type', 'version_number'])]
#[Index(name: 'idx_current', columns: ['theme_id', 'page_type', 'is_current'])]
#[Index(name: 'idx_published', columns: ['theme_id', 'page_type', 'is_published'])]
#[Index(name: 'idx_type', columns: ['version_type'])]
class ThemeLayoutVersion extends Model
{
    public const schema_table = 'theme_layout_version';
    public const schema_primary_key = 'version_id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '版本ID')]
    public const schema_fields_ID = 'version_id';
    #[Col('int', 11, nullable: false, comment: '主题ID')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col('varchar', 50, nullable: false, default: 'homepage', comment: '页面/布局类型')]
    public const schema_fields_PAGE_TYPE = 'page_type';
    #[Col('int', 11, nullable: false, default: 1, comment: '版本号')]
    public const schema_fields_VERSION_NUMBER = 'version_number';
    #[Col('varchar', 100, comment: '版本名称')]
    public const schema_fields_VERSION_NAME = 'version_name';
    #[Col('varchar', 20, nullable: false, default: 'manual', comment: '版本类型')]
    public const schema_fields_VERSION_TYPE = 'version_type';
    #[Col('longtext', comment: 'JSON快照数据')]
    public const schema_fields_SNAPSHOT_DATA = 'snapshot_data';
    #[Col('int', 11, comment: '父版本ID')]
    public const schema_fields_PARENT_VERSION_ID = 'parent_version_id';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否为当前编辑版本')]
    public const schema_fields_IS_CURRENT = 'is_current';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否为已发布版本')]
    public const schema_fields_IS_PUBLISHED = 'is_published';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col('int', 11, comment: '创建者用户ID')]
    public const schema_fields_CREATED_BY = 'created_by';
    #[Col('text', comment: '版本描述')]
    public const schema_fields_DESCRIPTION = 'description';
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
// ==================== Getters & Setters ====================
    public function getVersionId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }
    public function setVersionId(int $id): self
    {
        return $this->setData(self::schema_fields_ID, $id);
    }
    public function getThemeId(): int
    {
        return (int)$this->getData(self::schema_fields_THEME_ID);
    }
    public function setThemeId(int $themeId): self
    {
        return $this->setData(self::schema_fields_THEME_ID, $themeId);
    }
    public function getPageType(): string
    {
        return (string)$this->getData(self::schema_fields_PAGE_TYPE);
    }
    public function setPageType(string $pageType): self
    {
        return $this->setData(self::schema_fields_PAGE_TYPE, $pageType);
    }
    public function getVersionNumber(): int
    {
        return (int)$this->getData(self::schema_fields_VERSION_NUMBER);
    }
    public function setVersionNumber(int $number): self
    {
        return $this->setData(self::schema_fields_VERSION_NUMBER, $number);
    }
    public function getVersionName(): ?string
    {
        $name = $this->getData(self::schema_fields_VERSION_NAME);
        return $name ? (string)$name : null;
    }
    public function setVersionName(?string $name): self
    {
        return $this->setData(self::schema_fields_VERSION_NAME, $name);
    }
    public function getVersionType(): string
    {
        return (string)($this->getData(self::schema_fields_VERSION_TYPE) ?: self::TYPE_MANUAL);
    }
    public function setVersionType(string $type): self
    {
        return $this->setData(self::schema_fields_VERSION_TYPE, $type);
    }
    /**
     * 获取快照数据（解析 JSON）
     */
    public function getSnapshotData(): array
    {
        $data = $this->getData(self::schema_fields_SNAPSHOT_DATA);
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
            self::schema_fields_SNAPSHOT_DATA,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
    public function getParentVersionId(): ?int
    {
        $id = $this->getData(self::schema_fields_PARENT_VERSION_ID);
        return $id ? (int)$id : null;
    }
    public function setParentVersionId(?int $id): self
    {
        return $this->setData(self::schema_fields_PARENT_VERSION_ID, $id);
    }
    public function isCurrent(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_CURRENT);
    }
    public function setIsCurrent(bool $current): self
    {
        return $this->setData(self::schema_fields_IS_CURRENT, $current ? 1 : 0);
    }
    public function isPublished(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_PUBLISHED);
    }
    public function setIsPublished(bool $published): self
    {
        return $this->setData(self::schema_fields_IS_PUBLISHED, $published ? 1 : 0);
    }
    public function getCreatedBy(): ?int
    {
        $id = $this->getData(self::schema_fields_CREATED_BY);
        return $id ? (int)$id : null;
    }
    public function setCreatedBy(?int $userId): self
    {
        return $this->setData(self::schema_fields_CREATED_BY, $userId);
    }
    public function getDescription(): ?string
    {
        $desc = $this->getData(self::schema_fields_DESCRIPTION);
        return $desc ? (string)$desc : null;
    }
    public function setDescription(?string $description): self
    {
        return $this->setData(self::schema_fields_DESCRIPTION, $description);
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
            'created_at' => $this->getData(self::schema_fields_CREATE_TIME),
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
