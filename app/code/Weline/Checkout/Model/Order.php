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
 * 订单模型
 */
#[Table(comment: '订单表')]
#[Index(name: 'idx_order_number', columns: ['order_number'], type: 'UNIQUE')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_payment_status', columns: ['payment_status'])]
#[Index(name: 'idx_created_time', columns: ['created_time'])]
class Order extends Model
{
    public const schema_table = 'weline_checkout_order';
    public const schema_primary_key = 'order_id';
    // 字段常量
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '订单ID')]
    public const schema_fields_ID = 'order_id';
    #[Col('varchar', 64, nullable: false, comment: '订单号')]
    public const schema_fields_ORDER_NUMBER = 'order_number';
    #[Col('int', nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('varchar', 20, default: 'pending', comment: '订单状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('decimal', '10,2', default: '0.00', comment: '商品小计')]
    public const schema_fields_SUBTOTAL = 'subtotal';
    #[Col('decimal', '10,2', default: '0.00', comment: '运费')]
    public const schema_fields_SHIPPING_AMOUNT = 'shipping_amount';
    #[Col('decimal', '10,2', default: '0.00', comment: '税费')]
    public const schema_fields_TAX_AMOUNT = 'tax_amount';
    #[Col('decimal', '10,2', default: '0.00', comment: '折扣金额')]
    public const schema_fields_DISCOUNT_AMOUNT = 'discount_amount';
    #[Col('decimal', '10,2', default: '0.00', comment: '订单总额')]
    public const schema_fields_TOTAL_AMOUNT = 'total_amount';
    #[Col('varchar', 10, default: 'CNY', comment: '货币代码')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('text', comment: '收货地址')]
    public const schema_fields_SHIPPING_ADDRESS = 'shipping_address';
    #[Col('text', comment: '账单地址')]
    public const schema_fields_BILLING_ADDRESS = 'billing_address';
    #[Col('varchar', 50, comment: '支付方式')]
    public const schema_fields_PAYMENT_METHOD = 'payment_method';
    #[Col('varchar', 20, default: 'pending', comment: '支付状态')]
    public const schema_fields_PAYMENT_STATUS = 'payment_status';
    #[Col('varchar', 50, comment: '配送方式')]
    public const schema_fields_SHIPPING_METHOD = 'shipping_method';
    #[Col('varchar', 20, default: 'pending', comment: '配送状态')]
    public const schema_fields_SHIPPING_STATUS = 'shipping_status';
    #[Col('text', comment: '订单备注')]
    public const schema_fields_REMARK = 'remark';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';
    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_TIME = 'updated_time';
    
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
    
    public const schema_primary_keys = ['order_id'];
    public array $_index_sort_keys = ['order_id', 'order_number', 'customer_id', 'created_time'];
    /**
     * 初始化模型
     */
    public function _init(): void
    {
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
