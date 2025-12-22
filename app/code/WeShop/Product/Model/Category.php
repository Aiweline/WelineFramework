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

use Weline\Eav\Model\EavAttribute\Set;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
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
    public const fields_DEFAULT_SET_ID = 'default_set_id';
    public const fields_IMAGE = 'image';
    public const fields_DESCRIPTION = 'description';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_META_TITLE = 'meta_title';
    public const fields_META_DESCRIPTION = 'meta_description';
    public const fields_META_KEYWORDS = 'meta_keywords';
    
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
        // 添加 default_set_id 字段（关联默认属性集）
        if (!$setup->hasField(self::fields_DEFAULT_SET_ID)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_DEFAULT_SET_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '默认属性集ID'
                )
                ->alter();
        }
        // 添加分类图片字段
        if (!$setup->hasField(self::fields_IMAGE)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_IMAGE,
                    TableInterface::column_type_VARCHAR,
                    500,
                    "default ''",
                    '分类图片'
                )
                ->alter();
        }
        // 添加分类描述字段
        if (!$setup->hasField(self::fields_DESCRIPTION)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '分类描述'
                )
                ->alter();
        }
        // 添加分类状态字段
        if (!$setup->hasField(self::fields_IS_ACTIVE)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用'
                )
                ->alter();
        }
        // 添加SEO字段
        if (!$setup->hasField(self::fields_META_TITLE)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_META_TITLE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    "default ''",
                    'Meta标题'
                )
                ->alter();
        }
        if (!$setup->hasField(self::fields_META_DESCRIPTION)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_META_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    'Meta描述'
                )
                ->alter();
        }
        if (!$setup->hasField(self::fields_META_KEYWORDS)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_META_KEYWORDS,
                    TableInterface::column_type_VARCHAR,
                    500,
                    "default ''",
                    'Meta关键词'
                )
                ->alter();
        }
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
                ->addColumn(
                    self::fields_DEFAULT_SET_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'default 0',
                    '默认属性集ID'
                )
                ->addColumn(
                    self::fields_IMAGE,
                    TableInterface::column_type_VARCHAR,
                    500,
                    "default ''",
                    '分类图片'
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '分类描述'
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用'
                )
                ->addColumn(
                    self::fields_META_TITLE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    "default ''",
                    'Meta标题'
                )
                ->addColumn(
                    self::fields_META_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    'Meta描述'
                )
                ->addColumn(
                    self::fields_META_KEYWORDS,
                    TableInterface::column_type_VARCHAR,
                    500,
                    "default ''",
                    'Meta关键词'
                )
                ->addIndex(
                    TableInterface::index_type_DEFAULT,
                    'CATEGORY_ID',
                    self::fields_CATEGORY_ID,
                    '分类ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_default_set_id',
                    self::fields_DEFAULT_SET_ID,
                    '默认属性集索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_is_active',
                    self::fields_IS_ACTIVE,
                    '启用状态索引'
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

    public function save_before(): void
    {
        # 处理path
        $this->setData(self::fields_PATH, 1);
        parent::save_before();
    }

    public function save_after(): void
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
        
        // 触发分类保存后事件
        $this->getEventManager()->dispatch('WeShop_Product::category_save_after', [
            'category' => $this,
            'category_id' => $this->getId()
        ]);
    }
    
    /**
     * 分类删除后钩子 - 触发事件
     */
    public function delete_after(): void
    {
        parent::delete_after();
        // 触发分类删除后事件
        $this->getEventManager()->dispatch('WeShop_Product::category_delete_after', [
            'category_id' => $this->getOriginData(self::fields_ID)
        ]);
    }
    
    /**
     * 获取事件管理器
     * @return \Weline\Framework\Event\EventsManager
     */
    protected function getEventManager(): \Weline\Framework\Event\EventsManager
    {
        return ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
    }

    /**
     * @throws \Weline\Framework\App\Exception
     */
    public function delete_before(): void
    {
        if (intval($this->getId()) === 1) {
            throw new Exception(__('不能删除根分类！'));
        }
    }

    /**
     * 获取默认属性集ID
     * @return int
     */
    public function getDefaultSetId(): int
    {
        return (int)$this->getData(self::fields_DEFAULT_SET_ID);
    }

    /**
     * 设置默认属性集ID
     * @param int $setId
     * @return static
     */
    public function setDefaultSetId(int $setId): static
    {
        return $this->setData(self::fields_DEFAULT_SET_ID, $setId);
    }

    /**
     * 获取默认属性集模型
     * @return Set|null
     */
    public function getDefaultSet(): ?Set
    {
        $setId = $this->getDefaultSetId();
        if ($setId <= 0) {
            return null;
        }
        /** @var Set $set */
        $set = ObjectManager::getInstance(Set::class);
        $set->load($setId);
        return $set->getId() ? $set : null;
    }

    /**
     * 获取父级ID
     * @return int
     */
    public function getPid(): int
    {
        return (int)$this->getData(self::fields_PID);
    }

    /**
     * 获取分类图片
     * @return string
     */
    public function getImage(): string
    {
        return (string)$this->getData(self::fields_IMAGE);
    }

    /**
     * 设置分类图片
     * @param string $image
     * @return static
     */
    public function setImage(string $image): static
    {
        return $this->setData(self::fields_IMAGE, $image);
    }

    /**
     * 获取分类描述
     * @return string
     */
    public function getDescription(): string
    {
        return (string)$this->getData(self::fields_DESCRIPTION);
    }

    /**
     * 设置分类描述
     * @param string $description
     * @return static
     */
    public function setDescription(string $description): static
    {
        return $this->setData(self::fields_DESCRIPTION, $description);
    }

    /**
     * 是否启用
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    /**
     * 设置启用状态
     * @param bool $isActive
     * @return static
     */
    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::fields_IS_ACTIVE, $isActive ? 1 : 0);
    }

    /**
     * 获取分类名称
     * @return string
     */
    public function getName(): string
    {
        return (string)$this->getData(self::fields_NAME);
    }

    /**
     * 设置分类名称
     * @param string $name
     * @return static
     */
    public function setName(string $name): static
    {
        return $this->setData(self::fields_NAME, $name);
    }

    /**
     * 获取分类路径
     * @return string
     */
    public function getPath(): string
    {
        return (string)$this->getData(self::fields_PATH);
    }

    /**
     * 获取分类层级
     * @return int
     */
    public function getLevel(): int
    {
        return (int)$this->getData(self::fields_LEVEL);
    }

    /**
     * 获取Meta标题
     * @return string
     */
    public function getMetaTitle(): string
    {
        return (string)$this->getData(self::fields_META_TITLE);
    }

    /**
     * 获取Meta描述
     * @return string
     */
    public function getMetaDescription(): string
    {
        return (string)$this->getData(self::fields_META_DESCRIPTION);
    }

    /**
     * 获取Meta关键词
     * @return string
     */
    public function getMetaKeywords(): string
    {
        return (string)$this->getData(self::fields_META_KEYWORDS);
    }
}
