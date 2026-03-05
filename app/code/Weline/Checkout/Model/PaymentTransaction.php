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
 * 支付交易模型
 */
#[Table(comment: '支付交易表')]
#[Index(name: 'idx_order_id', columns: ['order_id'], comment: '订单ID')]
#[Index(name: 'idx_transaction_number', columns: ['transaction_number'], comment: '交易号')]
#[Index(name: 'idx_status', columns: ['status'], comment: '交易状态')]
#[Index(name: 'idx_created_time', columns: ['created_time'], comment: '创建时间')]
class PaymentTransaction extends Model
{

    public const schema_table = 'weline_checkout_payment_transaction';
    public const schema_primary_key = 'transaction_id';
    public const schema_primary_keys = ['transaction_id'];

    // 字段常量
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '交易ID')]
    public const schema_fields_ID = 'transaction_id';
    #[Col(type: 'int', nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '支付方式')]
    public const schema_fields_PAYMENT_METHOD = 'payment_method';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '交易号')]
    public const schema_fields_TRANSACTION_NUMBER = 'transaction_number';
    #[Col(type: 'decimal', length: '10,2', nullable: true, default: '0.00', comment: '交易金额')]
    public const schema_fields_AMOUNT = 'amount';
    #[Col(type: 'varchar', length: 10, nullable: true, default: 'CNY', comment: '货币代码')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'pending', comment: '交易状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'text', nullable: true, comment: '支付网关响应（JSON）')]
    public const schema_fields_GATEWAY_RESPONSE = 'gateway_response';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_TIME = 'updated_time';
    
    // 交易状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    
    public array $_index_sort_keys = ['transaction_id', 'order_id', 'transaction_number', 'created_time'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
    }

    /**
     * 根据订单ID获取支付交易
     * 
     * @param int $orderId
     * @return array
     */
    public function getTransactionsByOrderId(int $orderId): array
    {
        return $this->where(self::schema_fields_ORDER_ID, $orderId)
            ->order(self::schema_fields_CREATED_TIME, 'DESC')
            ->select()
            ->fetchArray();
    }

    /**
     * 检查交易是否成功
     * 
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->getStatus() === self::STATUS_SUCCESS;
    }

    /**
     * 获取交易状态文本
     * 
     * @return string
     */
    public function getStatusText(): string
    {
        $statusMap = [
            self::STATUS_PENDING => __('待处理'),
            self::STATUS_SUCCESS => __('成功'),
            self::STATUS_FAILED => __('失败'),
            self::STATUS_REFUNDED => __('已退款'),
        ];
        
        return $statusMap[$this->getStatus()] ?? $this->getStatus();
    }

    /**
     * 获取支付网关响应（解析JSON）
     * 
     * @return array
     */
    public function getGatewayResponseArray(): array
    {
        $response = $this->getGatewayResponse();
        if (empty($response)) {
            return [];
        }
        
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }
}


