<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：布局模型
 */
namespace Weline\Layout\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '布局表')]
#[Index(name: 'idx_unique_layout', columns: ['code', 'module_code', 'layout_type'], type: 'UNIQUE', comment: '布局唯一索引')]
#[Index(name: 'idx_module_code', columns: ['module_code'], comment: '模块代码索引')]
#[Index(name: 'idx_layout_type', columns: ['layout_type'], comment: '布局类型索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '启用状态索引')]
class Layout extends Model
{
    public const schema_table = 'weline_layout';
    public const schema_primary_keys = ['layout_id', 'code', 'module_code', 'layout_type'];
    public const indexer = 'weline_layout';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '布局ID')]
    public const schema_fields_ID = 'layout_id';
    #[Col('varchar', 64, nullable: false, comment: '布局代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 255, nullable: false, comment: '布局名称')]
    public const schema_fields_NAME = 'name';
    #[Col('text', comment: '布局描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('varchar', 128, nullable: false, comment: '模块代码')]
    public const schema_fields_MODULE_CODE = 'module_code';
    #[Col('varchar', 64, nullable: false, comment: '布局类型')]
    public const schema_fields_LAYOUT_TYPE = 'layout_type';
    #[Col('varchar', 500, default: '', comment: '模板路径')]
    public const schema_fields_TEMPLATE_PATH = 'template_path';
    #[Col('text', comment: '布局配置（JSON）')]
    public const schema_fields_CONFIG = 'config';
    #[Col('int', 1, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('varchar', 500, default: '', comment: '预览图片路径')]
    public const schema_fields_PREVIEW_IMAGE = 'preview_image';
    public array $_unit_primary_keys = ['layout_id', 'code', 'module_code', 'layout_type'];
    public array $_index_sort_keys = ['layout_id', 'code', 'module_code', 'layout_type'];
// ===== Getters and Setters =====
    public function getCode(): string
    {
        return (string)$this->getData(self::schema_fields_CODE);
    }
    public function setCode(string $code): static
    {
        return $this->setData(self::schema_fields_CODE, $code);
    }
    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }
    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }
    public function getDescription(): string
    {
        return (string)$this->getData(self::schema_fields_DESCRIPTION);
    }
    public function setDescription(string $description): static
    {
        return $this->setData(self::schema_fields_DESCRIPTION, $description);
    }
    public function getModuleCode(): string
    {
        return (string)$this->getData(self::schema_fields_MODULE_CODE);
    }
    public function setModuleCode(string $moduleCode): static
    {
        return $this->setData(self::schema_fields_MODULE_CODE, $moduleCode);
    }
    public function getLayoutType(): string
    {
        return (string)$this->getData(self::schema_fields_LAYOUT_TYPE);
    }
    public function setLayoutType(string $layoutType): static
    {
        return $this->setData(self::schema_fields_LAYOUT_TYPE, $layoutType);
    }
    public function getTemplatePath(): string
    {
        return (string)$this->getData(self::schema_fields_TEMPLATE_PATH);
    }
    public function setTemplatePath(string $templatePath): static
    {
        return $this->setData(self::schema_fields_TEMPLATE_PATH, $templatePath);
    }
    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        if (empty($config)) {
            return [];
        }
        return is_string($config) ? json_decode($config, true) : $config;
    }
    public function setConfig(array $config): static
    {
        return $this->setData(self::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    public function isActive(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ACTIVE);
    }
    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
    }
    public function getSortOrder(): int
    {
        return (int)$this->getData(self::schema_fields_SORT_ORDER);
    }
    public function setSortOrder(int $sortOrder): static
    {
        return $this->setData(self::schema_fields_SORT_ORDER, $sortOrder);
    }
    public function getPreviewImage(): string
    {
        return (string)$this->getData(self::schema_fields_PREVIEW_IMAGE);
    }
    public function setPreviewImage(string $previewImage): static
    {
        return $this->setData(self::schema_fields_PREVIEW_IMAGE, $previewImage);
    }
    /**
     * 获取指定模块和布局类型的所有布局
     */
    public function getLayoutsByModuleAndType(string $moduleCode, string $layoutType): array
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE_CODE, $moduleCode)
            ->where(self::schema_fields_LAYOUT_TYPE, $layoutType)
            ->where(self::schema_fields_IS_ACTIVE, 1)
            ->order(self::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();
    }
    /**
     * 根据代码获取布局
     */
    public function getByCode(string $code, string $moduleCode, string $layoutType): ?static
    {
        $layout = $this->reset()
            ->where(self::schema_fields_CODE, $code)
            ->where(self::schema_fields_MODULE_CODE, $moduleCode)
            ->where(self::schema_fields_LAYOUT_TYPE, $layoutType)
            ->find()
            ->fetch();
        return $layout->getId() ? $layout : null;
    }
}
