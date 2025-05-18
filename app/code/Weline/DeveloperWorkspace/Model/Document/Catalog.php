<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Model\Document;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Catalog extends \Weline\Framework\Database\Model
{
    public string $table = 'developer_workspace_document_catalog';
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_PID = 'pid';
    public const fields_level = 'level';
    public const fields_icon = 'icon';
    public const fields_selectedIcon = 'selectedIcon';
    public const fields_color = 'color';
    public const fields_backColor = 'backColor';
    public const fields_position = 'position';
    public const fields_is_active = 'is_active';

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
        # 更新提示
        $setup->getPrinting()->setup($context->getVersion());
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->getPrinting()->setup('安装数据表...', $setup->getTable());
            $setup->createTable('目录')
                ->addColumn('id', TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', 'ID')
                ->addColumn('name', TableInterface::column_type_VARCHAR, 60, 'not null unique ', '目录名')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, 0, 'not null', '简介')
                ->addColumn('level', TableInterface::column_type_INTEGER, null, 'not null default 0', '目录层级')
                ->addColumn('icon', TableInterface::column_type_VARCHAR, 60, '', 'icon 图标')
                ->addColumn('selectedIcon', TableInterface::column_type_VARCHAR, 60, '', 'icon 选中图标')
                ->addColumn('color', TableInterface::column_type_VARCHAR, 60, '', '颜色')
                ->addColumn('backColor', TableInterface::column_type_VARCHAR, 60, '', '背景色')
                ->addColumn('position', TableInterface::column_type_INTEGER, null, 'default 0', '排序')
                ->addColumn('is_active', TableInterface::column_type_INTEGER, 1, 'default 0', '是否激活')
                ->addColumn('pid', TableInterface::column_type_INTEGER, 0, '', '父目录')
                ->create();
        }
    }

    public function getName()
    {
        return $this->getData(self::fields_NAME);
    }

    public function setName(string $name): Catalog
    {
        return $this->setData(self::fields_NAME, $name);
    }

    public function getPid()
    {
        return $this->getData(self::fields_PID);
    }

    public function setPid(string|int $pid): Catalog
    {
        return $this->setData(self::fields_PID, $pid);
    }

    public function setDescription(string $description): Catalog
    {
        return $this->setData(self::fields_DESCRIPTION, $description);
    }

    public function getDescription(): string
    {
        return $this->getData(self::fields_DESCRIPTION) ?? '';
    }

    /**
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    private function getUrl(array $param = [])
    {
        return ObjectManager::getInstance(Url::class)->getUrl('dev/tool/catalog', $param);
    }

    public function isActive(): bool
    {
        return $this->getData(self::fields_is_active) === 1;
    }

    public function setIsActive(bool $state): static
    {
        return $this->setData(self::fields_is_active, $state);
    }
}
