<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '平台模块分类表')]
#[Index(name: 'idx_name', columns: ['name'], type: 'KEY', comment: '分类名索引')]
#[Index(name: 'idx_parent_id', columns: ['parent_id'], type: 'KEY', comment: '父级ID索引')]
class PlatformModuleCategory extends Model
{
    public const schema_table = 'weline_platform_module_category';
    public const schema_primary_key = 'category_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '分类ID')]
    public const schema_fields_ID = 'category_id';

    #[Col(type: 'varchar', length: 100, nullable: false, comment: '分类名称')]
    public const schema_fields_name = 'name';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '分类描述')]
    public const schema_fields_description = 'description';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '分类图标')]
    public const schema_fields_icon = 'icon';

    #[Col(type: 'int', nullable: true, default: 0, comment: '父级分类ID')]
    public const schema_fields_parent_id = 'parent_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_sort_order = 'sort_order';

    #[Col(type: 'tinyint', nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_is_enabled = 'is_enabled';

    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public function getCategoryId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setCategoryId(int $categoryId): static
    {
        $this->setData(self::schema_fields_ID, $categoryId);
        return $this;
    }

    public function getName(): string
    {
        return $this->getData(self::schema_fields_name) ?? '';
    }

    public function setName(string $name): static
    {
        $this->setData(self::schema_fields_name, $name);
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->getData(self::schema_fields_description);
    }

    public function setDescription(?string $description): static
    {
        $this->setData(self::schema_fields_description, $description);
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->getData(self::schema_fields_icon);
    }

    public function setIcon(?string $icon): static
    {
        $this->setData(self::schema_fields_icon, $icon);
        return $this;
    }

    public function getParentId(): int
    {
        return (int)$this->getData(self::schema_fields_parent_id);
    }

    public function setParentId(int $parentId): static
    {
        $this->setData(self::schema_fields_parent_id, $parentId);
        return $this;
    }

    public function getSortOrder(): int
    {
        return (int)$this->getData(self::schema_fields_sort_order);
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->setData(self::schema_fields_sort_order, $sortOrder);
        return $this;
    }

    public function getIsEnabled(): bool
    {
        return (bool)$this->getData(self::schema_fields_is_enabled);
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        $this->setData(self::schema_fields_is_enabled, $isEnabled);
        return $this;
    }
}
