<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Gvanda所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/2/21 23:13:32
 */

namespace Gvanda\Product\Model;

use Weline\Eav\EavModel;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Product extends EavModel
{
    public const fields_ID = 'product_id';
    public const fields_name = 'name';
    public const fields_short_description = 'short_description';
    public const fields_description = 'description';
    public const fields_meta_name = 'meta_name';
    public const fields_meta_description = 'meta_description';
    public const fields_spu = 'spu';
    public const fields_sku = 'sku';
    public const fields_stock = 'stock';
    public const fields_cost = 'cost';
    public const fields_price = 'price';
    public const fields_image = 'image';
    public const fields_images = 'images';
    public const fields_parent_id = 'parent_id';
    public const fields_status = 'status';
    public const fields_weight = 'weight';
    public const fields_set_id = 'set_id';

    public array $_validate_fields = [self::fields_set_id, self::fields_name, self::fields_sku, self::fields_stock, self::fields_cost, self::fields_price, self::fields_set_id];

    public const entity_code = 'product';
    public const entity_name = '产品实体';
    public const eav_entity_id_field_type = TableInterface::column_type_INTEGER;
    public const eav_entity_id_field_length = 11;

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
        // 设置产品默认属性集
        /**@var \Weline\Eav\Model\EavAttribute\Set $setModel */
        $setModel = ObjectManager::getInstance(Set::class);
        # 属性集ID
        $set_id = $setModel->reset()->where(Set::fields_code, 'default')
            ->where(Set::fields_eav_entity_id, $this->getEavEntityId())
            ->find()->fetch()['set_id'] ?? 0;
        if ($set_id == 0) {
            # 属性集
            $setModel->reset()->setData(Set::fields_code, 'default')
                ->setData(Set::fields_eav_entity_id, $this->getEavEntityId())
                ->setData(Set::fields_name, '默认属性集')
                ->forceCheck(true, [Set::fields_code, Set::fields_eav_entity_id])
                ->save();
            $set_id = $setModel->getId();
        }
        if ($set_id) {
            # 属性组
            /**@var \Weline\Eav\Model\EavAttribute\Group $groupModel */
            $groupModel = ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Group::class);
            $groupModel->reset()->clearData()->setData(\Weline\Eav\Model\EavAttribute\Group::fields_code, 'default')
                ->setData(\Weline\Eav\Model\EavAttribute\Group::fields_eav_entity_id, $this->getEavEntityId())
                ->setData(\Weline\Eav\Model\EavAttribute\Group::fields_set_id, $set_id)
                ->setData(\Weline\Eav\Model\EavAttribute\Group::fields_name, '默认属性组')
                ->forceCheck(true, [
                    \Weline\Eav\Model\EavAttribute\Group::fields_code,
                    \Weline\Eav\Model\EavAttribute\Group::fields_eav_entity_id,
                    \Weline\Eav\Model\EavAttribute\Group::fields_set_id,
                ])
                ->save();
            $group_id = $groupModel->where(\Weline\Eav\Model\EavAttribute\Group::fields_code, 'default')
                ->where(\Weline\Eav\Model\EavAttribute\Group::fields_eav_entity_id, $this->getEavEntityId())
                ->where(\Weline\Eav\Model\EavAttribute\Set::fields_SET_ID, $set_id)
                ->find()->fetch()['group_id'] ?? 0;
            if ($group_id) {
                # 创建默认属性 主图：image 子图：images
                # 查找字符串类型
                /**@var \Weline\Eav\Model\EavAttribute\Type $type */
                $type = ObjectManager::getInstance(EavAttribute\Type::class);
                $type_id = $type->where(EavAttribute\Type::fields_code, 'input_string')
                    ->find()->fetch()['type_id'] ?? 0;
                if ($type_id) {
                    /**@var \Weline\Eav\Model\EavAttribute $attributeModel */
                    $attributeModel = ObjectManager::getInstance(EavAttribute::class);
                    $attributeModel->reset()->clearData()->setData(EavAttribute::fields_code, 'image')
                        ->setData(EavAttribute::fields_eav_entity_id, $this->getEavEntityId())
                        ->setData(EavAttribute::fields_group_id, $group_id)
                        ->setData(EavAttribute::fields_set_id, $set_id)
                        ->setData(EavAttribute::fields_type_id, $type_id)
                        ->setData(EavAttribute::fields_name, '图片')
                        ->forceCheck(true, [EavAttribute::fields_code, EavAttribute::fields_eav_entity_id, EavAttribute::fields_set_id])
                        ->save();
                    $attributeModel->reset()->clearData()->setData(EavAttribute::fields_code, 'images')
                        ->setData(EavAttribute::fields_eav_entity_id, $this->getEavEntityId())
                        ->setData(EavAttribute::fields_group_id, $group_id)
                        ->setData(EavAttribute::fields_set_id, $set_id)
                        ->setData(EavAttribute::fields_name, '子图')
                        ->setData(EavAttribute::fields_type_id, $type_id)
                        ->forceCheck(true, [EavAttribute::fields_code, EavAttribute::fields_eav_entity_id, EavAttribute::fields_set_id])
                        ->save();
                }
            }
        }
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
            $setup->createTable('产品表')
                ->addColumn(
                    $this::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '产品ID'
                )
                ->addColumn(
                    $this::fields_name,
                    TableInterface::column_type_VARCHAR,
                    150,
                    'not null',
                    '名称'
                )
                ->addColumn(
                    $this::fields_parent_id,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '父级ID'
                )
                ->addColumn(
                    $this::fields_spu,
                    TableInterface::column_type_VARCHAR,
                    60,
                    'not null',
                    'SPU'
                )
                ->addColumn(
                    $this::fields_sku,
                    TableInterface::column_type_VARCHAR,
                    60,
                    'not null unique',
                    '最小存货单位（SKU）'
                )
                ->addColumn(
                    $this::fields_stock,
                    TableInterface::column_type_INTEGER,
                    60,
                    'default 99',
                    '库存'
                )
                ->addColumn(
                    $this::fields_cost,
                    TableInterface::column_type_FLOAT,
                    0,
                    'not null',
                    '成本'
                )
                ->addColumn(
                    $this::fields_price,
                    TableInterface::column_type_FLOAT,
                    0,
                    'not null',
                    '价格'
                )
                ->addColumn(
                    $this::fields_short_description,
                    TableInterface::column_type_TEXT,
                    0,
                    'not null',
                    '简短描述'
                )
                ->addColumn(
                    $this::fields_description,
                    TableInterface::column_type_TEXT,
                    0,
                    'not null',
                    '描述'
                )
                ->addColumn(
                    $this::fields_image,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '图片'
                )
                ->addColumn(
                    $this::fields_images,
                    TableInterface::column_type_TEXT,
                    0,
                    'not null',
                    '子图'
                )
                ->addColumn(
                    $this::fields_status,
                    TableInterface::column_type_INTEGER,
                    1,
                    'not null',
                    '状态'
                )
                ->addColumn(
                    $this::fields_weight,
                    TableInterface::column_type_NUMERIC,
                    4,
                    'not null',
                    '重量'
                )
                ->addColumn(
                    self::fields_meta_name,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    'meta名称'
                )
                ->addColumn(
                    self::fields_meta_description,
                    TableInterface::column_type_TEXT,
                    0,
                    'not null',
                    'meta描述'
                )
                ->addColumn(
                    self::fields_set_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '属性集ID'
                )
                ->addIndex(
                    TableInterface::index_type_FULLTEXT,
                    'idx_short_description',
                    $this::fields_short_description,
                    '简短描述索引'
                )
                ->addIndex(
                    TableInterface::index_type_FULLTEXT,
                    'idx_description',
                    $this::fields_description,
                    '描述索引'
                )
                ->addIndex(
                    TableInterface::index_type_FULLTEXT,
                    'idx_sku',
                    $this::fields_sku,
                    'SKU索引'
                )
                ->addIndex(
                    TableInterface::index_type_FULLTEXT,
                    'idx_spu',
                    $this::fields_spu,
                    'SPU索引'
                )
                ->addIndex(
                    TableInterface::index_type_DEFAULT,
                    'idx_price',
                    $this::fields_price,
                    '价格索引'
                )
                ->addIndex(
                    TableInterface::index_type_FULLTEXT,
                    'idx_name',
                    $this::fields_name,
                    '产品名索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_parent_id',
                    $this::fields_parent_id,
                    '父级ID索引'
                )
                ->create();
        }
    }

    public function getName(): string
    {
        return $this->getData(self::fields_name);
    }

    public function setName(string $name): static
    {
        $this->setData(self::fields_name, $name);
        return $this;
    }

    public function getParentId(): int
    {
        return $this->getData(self::fields_parent_id);
    }

    public function setParentId(int $parent_id): static
    {
        $this->setData(self::fields_parent_id, $parent_id);
        return $this;
    }

    public function getSku(): string
    {
        return $this->getData(self::fields_sku);
    }

    public function setSku(string $sku): static
    {
        $this->setData(self::fields_sku, $sku);
        return $this;
    }

    public function getStock(): int
    {
        return $this->getData(self::fields_stock);
    }

    public function setStock(int $stock): static
    {
        $this->setData(self::fields_stock, $stock);
        return $this;
    }

    public function getCost(): float
    {
        return (float)$this->getData(self::fields_cost);
    }

    public function setCost(float $cost): static
    {
        $this->setData(self::fields_cost, $cost);
        return $this;
    }

    public function getPrice(): float
    {
        return (float)$this->getData(self::fields_price);
    }

    public function setPrice(float $price): static
    {
        $this->setData(self::fields_price, $price);
        return $this;
    }

    public function getShortDescription(): string
    {
        return $this->getData(self::fields_short_description);
    }

    public function setShortDescription(string $short_description): static
    {
        $this->setData(self::fields_short_description, $short_description);
        return $this;
    }

    public function getDescription(): string
    {
        return $this->getData(self::fields_description);
    }

    public function setDescription(string $description): static
    {
        $this->setData(self::fields_description, $description);
        return $this;
    }

    public function getImage(): string
    {
        return $this->getData(self::fields_image);
    }

    public function setImage(string $image): static
    {
        $this->setData(self::fields_image, $image);
        return $this;
    }

    public function getImages(): string
    {
        return $this->getData(self::fields_images);
    }

    public function setImages(string $images): static
    {
        $this->setData(self::fields_images, $images);
        return $this;
    }

    public function getMetaName(): string
    {
        return $this->getData(self::fields_meta_name);
    }

    public function setMetaName(string $meta_title): static
    {
        $this->setData(self::fields_meta_name, $meta_title);
        return $this;
    }

    public function getMetaDescription(): string
    {
        return $this->getData(self::fields_meta_description);
    }

    public function setMetaDescription(string $meta_description): static
    {
        $this->setData(self::fields_meta_description, $meta_description);
        return $this;
    }

    public function getStatus(): int
    {
        return (int)$this->getData(self::fields_status);
    }

    public function setStatus(int $status): static
    {
        $this->setData(self::fields_status, $status);
        return $this;
    }

    public function getWeight(): float
    {
        return (float)$this->getData(self::fields_weight);
    }

    public function setWeight(float $weight): static
    {
        $this->setData(self::fields_weight, $weight);
        return $this;
    }

}
