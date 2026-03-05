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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 订单项模型
 */
#[Table(comment: '订单项表')]
#[Index(name: 'idx_order_id', columns: ['order_id'])]
#[Index(name: 'idx_product_id', columns: ['product_id'])]
#[Index(name: 'idx_product_sku', columns: ['product_sku'])]
class OrderItem extends Model
{
    public const schema_table = 'weline_checkout_order_item';
    public const schema_primary_key = 'item_id';
    public const schema_primary_keys = ['item_id'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '订单项ID')]
    public const schema_fields_ID = 'item_id';
    #[Col('int', nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col('int', nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col('varchar', 255, nullable: false, comment: '产品名称')]
    public const schema_fields_PRODUCT_NAME = 'product_name';
    #[Col('varchar', 100, comment: '产品SKU')]
    public const schema_fields_PRODUCT_SKU = 'product_sku';
    #[Col('int', default: 1, comment: '数量')]
    public const schema_fields_QUANTITY = 'quantity';
    #[Col('decimal', '10,2', default: '0.00', comment: '单价')]
    public const schema_fields_PRICE = 'price';
    #[Col('decimal', '10,2', default: '0.00', comment: '总价')]
    public const schema_fields_TOTAL_PRICE = 'total_price';
    #[Col('text', comment: '产品属性')]
    public const schema_fields_ATTRIBUTES = 'attributes';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';
    
    public array $_index_sort_keys = ['item_id', 'order_id'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
    }

    /**
     * 根据订单ID获取所有订单项
     * 
     * @param int $orderId
     * @return array
     */
    public function getItemsByOrderId(int $orderId): array
    {
        return $this->where(self::schema_fields_ORDER_ID, $orderId)
            ->order(self::schema_fields_ID, 'ASC')
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
            $this->where(self::schema_fields_ORDER_ID, $orderId)->delete()->fetch();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

