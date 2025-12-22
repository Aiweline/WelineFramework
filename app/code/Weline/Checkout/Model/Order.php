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
 * 订单模型
 */
class Order extends Model
{
    public const table = 'weline_checkout_order';
    public const primary_key = 'order_id';
    
    // 字段常量
    public const fields_ID = 'order_id';
    public const fields_ORDER_NUMBER = 'order_number';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_STATUS = 'status';
    public const fields_SUBTOTAL = 'subtotal';
    public const fields_SHIPPING_AMOUNT = 'shipping_amount';
    public const fields_TAX_AMOUNT = 'tax_amount';
    public const fields_DISCOUNT_AMOUNT = 'discount_amount';
    public const fields_TOTAL_AMOUNT = 'total_amount';
    public const fields_CURRENCY = 'currency';
    public const fields_SHIPPING_ADDRESS = 'shipping_address';
    public const fields_BILLING_ADDRESS = 'billing_address';
    public const fields_PAYMENT_METHOD = 'payment_method';
    public const fields_PAYMENT_STATUS = 'payment_status';
    public const fields_SHIPPING_METHOD = 'shipping_method';
    public const fields_SHIPPING_STATUS = 'shipping_status';
    public const fields_REMARK = 'remark';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';
    
    // 订单状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    
    // 支付状态常量
    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_PAID = 'paid';
    public const PAYMENT_STATUS_FAILED = 'failed';
    public const PAYMENT_STATUS_REFUNDED = 'refunded';
    
    // 配送状态常量
    public const SHIPPING_STATUS_PENDING = 'pending';
    public const SHIPPING_STATUS_SHIPPED = 'shipped';
    public const SHIPPING_STATUS_DELIVERED = 'delivered';
    
    public array $_unit_primary_keys = ['order_id'];
    public array $_index_sort_keys = ['order_id', 'order_number', 'customer_id', 'created_time'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = 'order_id';
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
            $setup->createTable('订单表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '订单ID')
                ->addColumn(self::fields_ORDER_NUMBER, TableInterface::column_type_VARCHAR, 64, 'not null', '订单号')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, null, 'not null', '客户ID')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '订单状态')
                ->addColumn(self::fields_SUBTOTAL, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '商品小计')
                ->addColumn(self::fields_SHIPPING_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '运费')
                ->addColumn(self::fields_TAX_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '税费')
                ->addColumn(self::fields_DISCOUNT_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '折扣金额')
                ->addColumn(self::fields_TOTAL_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '订单总额')
                ->addColumn(self::fields_CURRENCY, TableInterface::column_type_VARCHAR, 10, 'default \'CNY\'', '货币代码')
                ->addColumn(self::fields_SHIPPING_ADDRESS, TableInterface::column_type_TEXT, null, '', '收货地址（JSON）')
                ->addColumn(self::fields_BILLING_ADDRESS, TableInterface::column_type_TEXT, null, '', '账单地址（JSON）')
                ->addColumn(self::fields_PAYMENT_METHOD, TableInterface::column_type_VARCHAR, 50, '', '支付方式')
                ->addColumn(self::fields_PAYMENT_STATUS, TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '支付状态')
                ->addColumn(self::fields_SHIPPING_METHOD, TableInterface::column_type_VARCHAR, 50, '', '配送方式')
                ->addColumn(self::fields_SHIPPING_STATUS, TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '配送状态')
                ->addColumn(self::fields_REMARK, TableInterface::column_type_TEXT, null, '', '订单备注')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_DATETIME, null, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_order_number', self::fields_ORDER_NUMBER)
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID)
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
                ->addIndex(TableInterface::index_type_KEY, 'idx_payment_status', self::fields_PAYMENT_STATUS)
                ->addIndex(TableInterface::index_type_KEY, 'idx_created_time', self::fields_CREATED_TIME)
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
     * 生成订单号
     * 
     * @return string
     */
    public function generateOrderNumber(): string
    {
        return 'ORD' . date('YmdHis') . str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * 获取订单项
     * 
     * @return array
     */
    public function getItems(): array
    {
        if (!$this->getId()) {
            return [];
        }
        
        /** @var OrderItem $orderItemModel */
        $orderItemModel = \Weline\Framework\Manager\ObjectManager::getInstance(OrderItem::class);
        return $orderItemModel->getItemsByOrderId($this->getId());
    }

    /**
     * 检查订单是否可以取消
     * 
     * @return bool
     */
    public function canCancel(): bool
    {
        return in_array($this->getStatus(), [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING
        ]) && $this->getPaymentStatus() !== self::PAYMENT_STATUS_PAID;
    }

    /**
     * 检查订单是否已支付
     * 
     * @return bool
     */
    public function isPaid(): bool
    {
        return $this->getPaymentStatus() === self::PAYMENT_STATUS_PAID;
    }

    /**
     * 检查订单是否已完成
     * 
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->getStatus() === self::STATUS_COMPLETED;
    }

    /**
     * 获取订单状态文本
     * 
     * @return string
     */
    public function getStatusText(): string
    {
        $statusMap = [
            self::STATUS_PENDING => __('待处理'),
            self::STATUS_PROCESSING => __('处理中'),
            self::STATUS_COMPLETED => __('已完成'),
            self::STATUS_CANCELLED => __('已取消'),
            self::STATUS_REFUNDED => __('已退款'),
        ];
        
        return $statusMap[$this->getStatus()] ?? $this->getStatus();
    }

    /**
     * 获取支付状态文本
     * 
     * @return string
     */
    public function getPaymentStatusText(): string
    {
        $statusMap = [
            self::PAYMENT_STATUS_PENDING => __('待支付'),
            self::PAYMENT_STATUS_PAID => __('已支付'),
            self::PAYMENT_STATUS_FAILED => __('支付失败'),
            self::PAYMENT_STATUS_REFUNDED => __('已退款'),
        ];
        
        return $statusMap[$this->getPaymentStatus()] ?? $this->getPaymentStatus();
    }

    /**
     * 获取配送状态文本
     * 
     * @return string
     */
    public function getShippingStatusText(): string
    {
        $statusMap = [
            self::SHIPPING_STATUS_PENDING => __('待发货'),
            self::SHIPPING_STATUS_SHIPPED => __('已发货'),
            self::SHIPPING_STATUS_DELIVERED => __('已送达'),
        ];
        
        return $statusMap[$this->getShippingStatus()] ?? $this->getShippingStatus();
    }

    /**
     * 获取收货地址（解析JSON）
     * 
     * @return array
     */
    public function getShippingAddressArray(): array
    {
        $address = $this->getShippingAddress();
        if (empty($address)) {
            return [];
        }
        
        $decoded = json_decode($address, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 获取账单地址（解析JSON）
     * 
     * @return array
     */
    public function getBillingAddressArray(): array
    {
        $address = $this->getBillingAddress();
        if (empty($address)) {
            return [];
        }
        
        $decoded = json_decode($address, true);
        return is_array($decoded) ? $decoded : [];
    }
}

