<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/2/21 22:06:19
 */

namespace WeShop\Product\Model;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Category extends \Weline\Framework\Database\Model
{
    public const indexer = 'product_category';
    public const fields_ID = 'category_id';
    public const fields_CATEGORY_ID = 'category_id';
    public const fields_NAME = 'name';
    public const fields_PID = 'pid';
    public const fields_PATH = 'path';
    public const fields_POSITION = 'position';
    public const fields_LEVEL = 'level';
    public const fields_CHILD_COUNT = 'child_count';

    public function addLocalDescription(): static
    {
        $lang = Cookie::getLang();
        $idField = $this::fields_ID;
        $this->joinModel(
            \WeShop\Product\Model\Category\LocalDescription::class,
            'local',
            "main_table.{$idField}=local.{$idField} and local.local_code='$lang'",
            'left'
        );
        return $this;
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
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        //        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable('分类表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '分类ID'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null unique',
                    '分类名'
                )
                ->addColumn(
                    self::fields_PID,
                    TableInterface::column_type_INTEGER,
                    255,
                    'not null default 0',
                    '分类父级ID'
                )
                ->addColumn(
                    self::fields_PATH,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '分类路径'
                )
                ->addColumn(
                    self::fields_POSITION,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null default 0',
                    '分类位置'
                )
                ->addColumn(
                    self::fields_LEVEL,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null default 1',
                    '分类层级'
                )
                ->addColumn(
                    self::fields_CHILD_COUNT,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null default 0',
                    '分类子级总数'
                )
                ->addIndex(
                    TableInterface::index_type_DEFAULT,
                    'CATEGORY_ID',
                    self::fields_CATEGORY_ID,
                    '分类ID索引'
                )
                ->create();
            // 创建根分类
            $this->setData(self::fields_CATEGORY_ID, 1)
                ->setData(self::fields_NAME, '根分类')
                ->setData(self::fields_PID, 0)
                ->setData(self::fields_PATH, 1)
                ->setData(self::fields_POSITION, 0)
                ->setData(self::fields_LEVEL, 1)
                ->setData(self::fields_CHILD_COUNT, 0)
                ->save(true);
        }
    }

    public function save_before()
    {
        # 处理path
        $this->setData(self::fields_PATH, 1);
        parent::save_before();
    }

    public function save_after()
    {
        $model = clone $this;
        # 处理path 和 层级level
        if ($pid = $this->getData(self::fields_PID)) {
            $parent = $model->reset()->where(self::fields_ID, $pid)->find()->fetch();
            $this->setData(self::fields_PATH, $parent->getData('path') . '/' . $this->getId());
            $this->setData(self::fields_LEVEL, $parent->getData(self::fields_LEVEL) + 1);
        } else {
            $this->setData(self::fields_PATH, $this->getId());
            $this->setData(self::fields_LEVEL, 1);
        }

        $this->update()->fetch();
        parent::save_after();
    }

    /**
     * @throws \Weline\Framework\App\Exception
     */
    public function delete_before()
    {
        if (intval($this->getId()) === 1) {
            throw new Exception(__('不能删除根分类！'));
        }
    }
}
