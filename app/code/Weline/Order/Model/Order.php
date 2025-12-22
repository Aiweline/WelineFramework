<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\DataObject\DataObject;

/**
 * 订单主表模型
 */
class Order extends Model
{
    public const table = 'weline_order';
    
    // 字段常量
    public const fields_ID = 'order_id';
    public const fields_ORDER_NUMBER = 'order_number';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_STATUS = 'status';
    public const fields_STATE = 'state';
    public const fields_GRAND_TOTAL = 'grand_total';
    public const fields_SUBTOTAL = 'subtotal';
    public const fields_SHIPPING_AMOUNT = 'shipping_amount';
    public const fields_TAX_AMOUNT = 'tax_amount';
    public const fields_DISCOUNT_AMOUNT = 'discount_amount';
    public const fields_CURRENCY = 'currency';
    public const fields_PAYMENT_STATUS = 'payment_status';
    public const fields_FULFILLMENT_STATUS = 'fulfillment_status';
    public const fields_SHIPPING_ADDRESS = 'shipping_address';
    public const fields_BILLING_ADDRESS = 'billing_address';
    public const fields_CUSTOMER_EMAIL = 'customer_email';
    public const fields_CUSTOMER_NAME = 'customer_name';
    public const fields_CUSTOMER_PHONE = 'customer_phone';
    public const fields_SHIPPING_METHOD = 'shipping_method';
    public const fields_PAYMENT_METHOD = 'payment_method';
    public const fields_NOTES = 'notes';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 订单状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    
    // 支付状态常量
    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_PAID = 'paid';
    public const PAYMENT_STATUS_PARTIAL = 'partial';
    public const PAYMENT_STATUS_REFUNDED = 'refunded';
    
    // 发货状态常量
    public const FULFILLMENT_STATUS_PENDING = 'pending';
    public const FULFILLMENT_STATUS_PARTIAL = 'partial';
    public const FULFILLMENT_STATUS_SHIPPED = 'shipped';
    public const FULFILLMENT_STATUS_DELIVERED = 'delivered';
    
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['order_id'];
    
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['order_id', 'order_number', 'customer_id', 'status', 'created_at'];
    
    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }
    
    /**
     * 模型设置
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * 模型升级
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑可以在这里添加
    }
    
    /**
     * 安装数据表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('订单主表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '订单ID'
                )
                ->addColumn(
                    self::fields_ORDER_NUMBER,
                    TableInterface::column_type_VARCHAR,
                    64,
                    'not null unique',
                    '订单号（唯一）'
                )
                ->addColumn(
                    self::fields_CUSTOMER_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'null',
                    '客户ID（关联Customer模块）'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default "pending"',
                    '订单状态'
                )
                ->addColumn(
                    self::fields_STATE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default "pending"',
                    '订单状态机状态'
                )
                ->addColumn(
                    self::fields_GRAND_TOTAL,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '订单总金额'
                )
                ->addColumn(
                    self::fields_SUBTOTAL,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '商品小计'
                )
                ->addColumn(
                    self::fields_SHIPPING_AMOUNT,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '运费'
                )
                ->addColumn(
                    self::fields_TAX_AMOUNT,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '税费'
                )
                ->addColumn(
                    self::fields_DISCOUNT_AMOUNT,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '折扣金额'
                )
                ->addColumn(
                    self::fields_CURRENCY,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'default "CNY"',
                    '货币代码'
                )
                ->addColumn(
                    self::fields_PAYMENT_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default "pending"',
                    '支付状态'
                )
                ->addColumn(
                    self::fields_FULFILLMENT_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default "pending"',
                    '发货状态'
                )
                ->addColumn(
                    self::fields_SHIPPING_ADDRESS,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '收货地址（JSON）'
                )
                ->addColumn(
                    self::fields_BILLING_ADDRESS,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '账单地址（JSON）'
                )
                ->addColumn(
                    self::fields_CUSTOMER_EMAIL,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    '客户邮箱'
                )
                ->addColumn(
                    self::fields_CUSTOMER_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    '客户姓名'
                )
                ->addColumn(
                    self::fields_CUSTOMER_PHONE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    '客户电话'
                )
                ->addColumn(
                    self::fields_SHIPPING_METHOD,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    '配送方式'
                )
                ->addColumn(
                    self::fields_PAYMENT_METHOD,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    '支付方式'
                )
                ->addColumn(
                    self::fields_NOTES,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '订单备注'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    0,
                    'default current_timestamp',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    0,
                    'default current_timestamp on update current_timestamp',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_order_number',
                    self::fields_ORDER_NUMBER,
                    '订单号索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_customer_id',
                    self::fields_CUSTOMER_ID,
                    '客户ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status',
                    self::fields_STATUS,
                    '订单状态索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_created_at',
                    self::fields_CREATED_AT,
                    '创建时间索引'
                )
                ->create();
        }
    }
    
    /**
     * 生成订单号
     */
    public function generateOrderNumber(): string
    {
        return 'ORD' . date('YmdHis') . str_pad((string)mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * 检查是否可以取消
     */
    public function canCancel(): bool
    {
        $status = $this->getData(self::fields_STATUS);
        return in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }
    
    /**
     * 检查是否可以退款
     */
    public function canRefund(): bool
    {
        $status = $this->getData(self::fields_STATUS);
        $paymentStatus = $this->getData(self::fields_PAYMENT_STATUS);
        return $status === self::STATUS_PAID && $paymentStatus === self::PAYMENT_STATUS_PAID;
    }
    
    /**
     * 获取订单状态的翻译文本
     * 
     * @param string $status 状态值
     * @return string 翻译后的状态文本
     */
    public static function getStatusLabel(string $status): string
    {
        if (empty($status)) {
            return $status;
        }
        
        // 通过事件机制获取状态标签
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            
            // 创建事件数据对象
            $eventData = new DataObject([
                'status' => $status,
                'label' => '',
            ]);
            
            // 触发事件
            $eventsManager->dispatch('Weline_Order::query::get_status_label', $eventData);
            
            // 从事件数据中获取结果
            $label = $eventData->getData('label');
            if (!empty($label)) {
                return $label;
            }
        } catch (\Throwable $e) {
            // 如果事件系统不可用，继续使用默认翻译
        }
        
        // 回退到翻译文件
        $translationKey = 'order_status_' . $status;
        $translated = __($translationKey);
        if ($translated !== $translationKey) {
            return $translated;
        }
        
        // 最后回退到硬编码映射
        return match($status) {
            self::STATUS_PENDING => __('待处理'),
            self::STATUS_PROCESSING => __('处理中'),
            self::STATUS_PAID => __('已支付'),
            self::STATUS_FULFILLED => __('已发货'),
            self::STATUS_COMPLETED => __('已完成'),
            self::STATUS_CANCELLED => __('已取消'),
            self::STATUS_REFUNDED => __('已退款'),
            default => $status,
        };
    }
    
    /**
     * 获取当前订单的状态翻译文本（实例方法）
     * 
     * @return string
     */
    public function getStatusText(): string
    {
        $status = $this->getData(self::fields_STATUS);
        if (empty($status)) {
            return '';
        }
        return self::getStatusLabel($status);
    }
    
    /**
     * 获取当前订单的状态CSS类
     * 
     * @return string
     */
    public function getStatusClass(): string
    {
        $status = $this->getData(self::fields_STATUS);
        if (empty($status)) {
            return 'secondary';
        }
        
        return self::getStatusClassByCode($status);
    }
    
    /**
     * 根据状态代码获取CSS类
     * 
     * @param string $status 状态代码
     * @return string
     */
    public static function getStatusClassByCode(string $status): string
    {
        if (empty($status)) {
            return 'secondary';
        }
        
        // 通过事件机制获取状态CSS类
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            
            // 创建事件数据对象
            $eventData = new DataObject([
                'status' => $status,
                'class' => '',
            ]);
            
            // 触发事件
            $eventsManager->dispatch('Weline_Order::query::get_status_class', $eventData);
            
            // 从事件数据中获取结果
            $class = $eventData->getData('class');
            if (!empty($class)) {
                return $class;
            }
        } catch (\Throwable $e) {
            // 如果事件系统不可用，继续使用默认映射
        }
        
        // 回退到默认映射
        return match($status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_PAID => 'primary',
            self::STATUS_FULFILLED => 'success',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_CANCELLED => 'danger',
            self::STATUS_REFUNDED => 'secondary',
            default => 'secondary',
        };
    }
    
    /**
     * 获取支付状态的翻译文本
     * 
     * @param string $status 支付状态值
     * @return string 翻译后的状态文本
     */
    public static function getPaymentStatusLabel(string $status): string
    {
        if (empty($status)) {
            return $status;
        }
        
        // 通过事件机制获取支付状态标签
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            
            // 创建事件数据对象
            $eventData = new DataObject([
                'status' => $status,
                'label' => '',
            ]);
            
            // 触发事件
            $eventsManager->dispatch('Weline_Order::query::get_payment_status_label', $eventData);
            
            // 从事件数据中获取结果
            $label = $eventData->getData('label');
            if (!empty($label)) {
                return $label;
            }
        } catch (\Throwable $e) {
            // 如果事件系统不可用，继续使用默认翻译
        }
        
        // 回退到硬编码映射
        return match($status) {
            self::PAYMENT_STATUS_PENDING => __('待支付'),
            self::PAYMENT_STATUS_PAID => __('已支付'),
            self::PAYMENT_STATUS_PARTIAL => __('部分支付'),
            self::PAYMENT_STATUS_REFUNDED => __('已退款'),
            default => $status,
        };
    }
    
    /**
     * 获取发货状态的翻译文本
     * 
     * @param string $status 发货状态值
     * @return string 翻译后的状态文本
     */
    public static function getFulfillmentStatusLabel(string $status): string
    {
        if (empty($status)) {
            return $status;
        }
        
        // 通过事件机制获取发货状态标签
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            
            // 创建事件数据对象
            $eventData = new DataObject([
                'status' => $status,
                'label' => '',
            ]);
            
            // 触发事件
            $eventsManager->dispatch('Weline_Order::query::get_fulfillment_status_label', $eventData);
            
            // 从事件数据中获取结果
            $label = $eventData->getData('label');
            if (!empty($label)) {
                return $label;
            }
        } catch (\Throwable $e) {
            // 如果事件系统不可用，继续使用默认翻译
        }
        
        // 回退到硬编码映射
        return match($status) {
            self::FULFILLMENT_STATUS_PENDING => __('待发货'),
            self::FULFILLMENT_STATUS_PARTIAL => __('部分发货'),
            self::FULFILLMENT_STATUS_SHIPPED => __('已发货'),
            self::FULFILLMENT_STATUS_DELIVERED => __('已送达'),
            default => $status,
        };
    }
}

