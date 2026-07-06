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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\DataObject\DataObject;
/** 订单主表模型 */
#[Table(comment: '订单主表')]
#[Index(name: 'idx_order_number', columns: ['order_number'])]
#[Index(name: 'idx_customer_id', columns: ['customer_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_source_app', columns: ['source_app'])]
#[Index(name: 'idx_source_module', columns: ['source_module'])]
#[Index(name: 'idx_business_code', columns: ['business_code'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class Order extends Model
{
    public const schema_table = 'weline_order';
    public const schema_primary_key = 'order_id';
    // 字段常量
    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '订单ID')]
    public const schema_fields_ID = 'order_id';
    #[Col('varchar', 64, nullable: false, unique: true, comment: '订单号')]
    public const schema_fields_ORDER_NUMBER = 'order_number';
    #[Col('int', 11, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('varchar', 50, nullable: false, default: 'pending', comment: '订单状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 50, nullable: false, default: 'pending', comment: '状态机状态')]
    public const schema_fields_STATE = 'state';
    #[Col('decimal', '10,2', nullable: false, default: 0.00, comment: '订单总金额')]
    public const schema_fields_GRAND_TOTAL = 'grand_total';
    #[Col('decimal', '10,2', nullable: false, default: 0.00, comment: '商品小计')]
    public const schema_fields_SUBTOTAL = 'subtotal';
    #[Col('decimal', '10,2', nullable: false, default: 0.00, comment: '运费')]
    public const schema_fields_SHIPPING_AMOUNT = 'shipping_amount';
    #[Col('decimal', '10,2', nullable: false, default: 0.00, comment: '税费')]
    public const schema_fields_TAX_AMOUNT = 'tax_amount';
    #[Col('decimal', '10,2', nullable: false, default: 0.00, comment: '折扣金额')]
    public const schema_fields_DISCOUNT_AMOUNT = 'discount_amount';
    #[Col('varchar', 10, nullable: false, default: 'CNY', comment: '货币代码')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('varchar', 80, nullable: false, default: '', comment: '来源应用')]
    public const schema_fields_SOURCE_APP = 'source_app';
    #[Col('varchar', 100, nullable: false, default: '', comment: '来源模块')]
    public const schema_fields_SOURCE_MODULE = 'source_module';
    #[Col('varchar', 100, nullable: false, default: '', comment: '业务代码')]
    public const schema_fields_BUSINESS_CODE = 'business_code';
    #[Col('varchar', 160, nullable: false, default: '', comment: '业务名称')]
    public const schema_fields_BUSINESS_NAME = 'business_name';
    #[Col('varchar', 50, nullable: false, default: 'pending', comment: '支付状态')]
    public const schema_fields_PAYMENT_STATUS = 'payment_status';
    #[Col('varchar', 50, nullable: false, default: 'pending', comment: '发货状态')]
    public const schema_fields_FULFILLMENT_STATUS = 'fulfillment_status';
    #[Col('text', comment: '收货地址JSON')]
    public const schema_fields_SHIPPING_ADDRESS = 'shipping_address';
    #[Col('text', comment: '账单地址JSON')]
    public const schema_fields_BILLING_ADDRESS = 'billing_address';
    #[Col('varchar', 255, comment: '客户邮箱')]
    public const schema_fields_CUSTOMER_EMAIL = 'customer_email';
    #[Col('varchar', 255, comment: '客户姓名')]
    public const schema_fields_CUSTOMER_NAME = 'customer_name';
    #[Col('varchar', 50, comment: '客户电话')]
    public const schema_fields_CUSTOMER_PHONE = 'customer_phone';
    #[Col('varchar', 100, comment: '配送方式')]
    public const schema_fields_SHIPPING_METHOD = 'shipping_method';
    #[Col('varchar', 100, comment: '支付方式')]
    public const schema_fields_PAYMENT_METHOD = 'payment_method';
    #[Col('text', comment: '订单备注')]
    public const schema_fields_NOTES = 'notes';
    #[Col('timestamp', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('timestamp', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    
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
        $status = $this->getData(self::schema_fields_STATUS);
        return in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }
    
    /**
     * 检查是否可以退款
     */
    public function canRefund(): bool
    {
        $status = $this->getData(self::schema_fields_STATUS);
        $paymentStatus = $this->getData(self::schema_fields_PAYMENT_STATUS);
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
        $status = $this->getData(self::schema_fields_STATUS);
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
        $status = $this->getData(self::schema_fields_STATUS);
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
