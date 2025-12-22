<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 订单项模型
 */
class OrderItem extends Model
{
    public const table = 'weline_checkout_order_item';
    public const primary_key = 'item_id';
    
    // 字段常量
    public const fields_ID = 'item_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_PRODUCT_NAME = 'product_name';
    public const fields_PRODUCT_SKU = 'product_sku';
    public const fields_QUANTITY = 'quantity';
    public const fields_PRICE = 'price';
    public const fields_TOTAL_PRICE = 'total_price';
    public const fields_ATTRIBUTES = 'attributes';
    public const fields_CREATED_TIME = 'created_time';
    
    public array $_unit_primary_keys = ['item_id'];
    public array $_index_sort_keys = ['item_id', 'order_id'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = 'item_id';
    }

    /**
     * 设置模型
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 安装模型
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('订单项表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '订单项ID')
                ->addColumn(self::fields_ORDER_ID, TableInterface::column_type_INTEGER, null, 'not null', '订单ID')
                ->addColumn(self::fields_PRODUCT_ID, TableInterface::column_type_INTEGER, null, 'not null', '产品ID')
                ->addColumn(self::fields_PRODUCT_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '产品名称')
                ->addColumn(self::fields_PRODUCT_SKU, TableInterface::column_type_VARCHAR, 100, '', '产品SKU')
                ->addColumn(self::fields_QUANTITY, TableInterface::column_type_INTEGER, null, 'default 1', '数量')
                ->addColumn(self::fields_PRICE, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '单价')
                ->addColumn(self::fields_TOTAL_PRICE, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '总价')
                ->addColumn(self::fields_ATTRIBUTES, TableInterface::column_type_TEXT, null, '', '产品属性（JSON）')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_order_id', self::fields_ORDER_ID)
                ->addIndex(TableInterface::index_type_KEY, 'idx_product_id', self::fields_PRODUCT_ID)
                ->addIndex(TableInterface::index_type_KEY, 'idx_product_sku', self::fields_PRODUCT_SKU)
                ->create();
        }
    }

    /**
     * 升级模型
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * 根据订单ID获取所有订单项
     * 
     * @param int $orderId
     * @return array
     */
    public function getItemsByOrderId(int $orderId): array
    {
        return $this->where(self::fields_ORDER_ID, $orderId)
            ->order(self::fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 计算订单项总价
     * 
     * @return float
     */
    public function calculateTotalPrice(): float
    {
        $quantity = (int)($this->getQuantity() ?: 1);
        $price = (float)($this->getPrice() ?: 0);
        return $quantity * $price;
    }

    /**
     * 获取产品属性（解析JSON）
     * 
     * @return array
     */
    public function getAttributesArray(): array
    {
        $attributes = $this->getAttributes();
        if (empty($attributes)) {
            return [];
        }
        
        $decoded = json_decode($attributes, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 批量插入订单项
     * 
     * @param array $items
     * @return bool
     */
    public function batchInsertItems(array $items): bool
    {
        if (empty($items)) {
            return true;
        }

        try {
            $this->beginTransaction();
            
            foreach ($items as $item) {
                $this->clear()
                    ->setData($item)
                    ->save();
            }
            
            $this->commit();
            return true;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * 删除订单的所有订单项
     * 
     * @param int $orderId
     * @return bool
     */
    public function deleteByOrderId(int $orderId): bool
    {
        try {
            $this->where(self::fields_ORDER_ID, $orderId)->delete()->fetch();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

