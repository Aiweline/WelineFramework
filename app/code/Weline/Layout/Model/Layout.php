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

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Layout extends Model
{
    public const indexer = 'weline_layout';
    public const fields_ID = 'layout_id';
    public const fields_CODE = 'code';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_MODULE_CODE = 'module_code';
    public const fields_LAYOUT_TYPE = 'layout_type';
    public const fields_TEMPLATE_PATH = 'template_path';
    public const fields_CONFIG = 'config';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_PREVIEW_IMAGE = 'preview_image';

    public array $_unit_primary_keys = ['layout_id', 'code', 'module_code', 'layout_type'];
    public array $_index_sort_keys = ['layout_id', 'code', 'module_code', 'layout_type'];

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
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('布局表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                '布局ID'
            )
            ->addColumn(
                self::fields_CODE,
                TableInterface::column_type_VARCHAR,
                64,
                'not null',
                '布局代码'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '布局名称'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                TableInterface::column_type_TEXT,
                0,
                '',
                '布局描述'
            )
            ->addColumn(
                self::fields_MODULE_CODE,
                TableInterface::column_type_VARCHAR,
                128,
                'not null',
                '模块代码'
            )
            ->addColumn(
                self::fields_LAYOUT_TYPE,
                TableInterface::column_type_VARCHAR,
                64,
                'not null',
                '布局类型'
            )
            ->addColumn(
                self::fields_TEMPLATE_PATH,
                TableInterface::column_type_VARCHAR,
                500,
                "default ''",
                '模板路径'
            )
            ->addColumn(
                self::fields_CONFIG,
                TableInterface::column_type_TEXT,
                0,
                '',
                '布局配置（JSON）'
            )
            ->addColumn(
                self::fields_IS_ACTIVE,
                TableInterface::column_type_INTEGER,
                1,
                'default 1',
                '是否启用'
            )
            ->addColumn(
                self::fields_SORT_ORDER,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                '排序'
            )
            ->addColumn(
                self::fields_PREVIEW_IMAGE,
                TableInterface::column_type_VARCHAR,
                500,
                "default ''",
                '预览图片路径'
            )
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'idx_unique_layout',
                [self::fields_CODE, self::fields_MODULE_CODE, self::fields_LAYOUT_TYPE],
                '布局唯一索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_module_code',
                self::fields_MODULE_CODE,
                '模块代码索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_layout_type',
                self::fields_LAYOUT_TYPE,
                '布局类型索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_is_active',
                self::fields_IS_ACTIVE,
                '启用状态索引'
            )
            ->create();
    }

    // ===== Getters and Setters =====

    public function getCode(): string
    {
        return (string)$this->getData(self::fields_CODE);
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::fields_CODE, $code);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::fields_NAME);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::fields_NAME, $name);
    }

    public function getDescription(): string
    {
        return (string)$this->getData(self::fields_DESCRIPTION);
    }

    public function setDescription(string $description): static
    {
        return $this->setData(self::fields_DESCRIPTION, $description);
    }

    public function getModuleCode(): string
    {
        return (string)$this->getData(self::fields_MODULE_CODE);
    }

    public function setModuleCode(string $moduleCode): static
    {
        return $this->setData(self::fields_MODULE_CODE, $moduleCode);
    }

    public function getLayoutType(): string
    {
        return (string)$this->getData(self::fields_LAYOUT_TYPE);
    }

    public function setLayoutType(string $layoutType): static
    {
        return $this->setData(self::fields_LAYOUT_TYPE, $layoutType);
    }

    public function getTemplatePath(): string
    {
        return (string)$this->getData(self::fields_TEMPLATE_PATH);
    }

    public function setTemplatePath(string $templatePath): static
    {
        return $this->setData(self::fields_TEMPLATE_PATH, $templatePath);
    }

    public function getConfig(): array
    {
        $config = $this->getData(self::fields_CONFIG);
        if (empty($config)) {
            return [];
        }
        return is_string($config) ? json_decode($config, true) : $config;
    }

    public function setConfig(array $config): static
    {
        return $this->setData(self::fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::fields_IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function getSortOrder(): int
    {
        return (int)$this->getData(self::fields_SORT_ORDER);
    }

    public function setSortOrder(int $sortOrder): static
    {
        return $this->setData(self::fields_SORT_ORDER, $sortOrder);
    }

    public function getPreviewImage(): string
    {
        return (string)$this->getData(self::fields_PREVIEW_IMAGE);
    }

    public function setPreviewImage(string $previewImage): static
    {
        return $this->setData(self::fields_PREVIEW_IMAGE, $previewImage);
    }

    /**
     * 获取指定模块和布局类型的所有布局
     */
    public function getLayoutsByModuleAndType(string $moduleCode, string $layoutType): array
    {
        return $this->reset()
            ->where(self::fields_MODULE_CODE, $moduleCode)
            ->where(self::fields_LAYOUT_TYPE, $layoutType)
            ->where(self::fields_IS_ACTIVE, 1)
            ->order(self::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 根据代码获取布局
     */
    public function getByCode(string $code, string $moduleCode, string $layoutType): ?static
    {
        $layout = $this->reset()
            ->where(self::fields_CODE, $code)
            ->where(self::fields_MODULE_CODE, $moduleCode)
            ->where(self::fields_LAYOUT_TYPE, $layoutType)
            ->find()
            ->fetch();
        return $layout->getId() ? $layout : null;
    }
}

