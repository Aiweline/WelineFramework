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
 * 支付交易模型
 */
class PaymentTransaction extends Model
{
    public const table = 'weline_checkout_payment_transaction';
    public const primary_key = 'transaction_id';
    
    // 字段常量
    public const fields_ID = 'transaction_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_PAYMENT_METHOD = 'payment_method';
    public const fields_TRANSACTION_NUMBER = 'transaction_number';
    public const fields_AMOUNT = 'amount';
    public const fields_CURRENCY = 'currency';
    public const fields_STATUS = 'status';
    public const fields_GATEWAY_RESPONSE = 'gateway_response';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';
    
    // 交易状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    
    public array $_unit_primary_keys = ['transaction_id'];
    public array $_index_sort_keys = ['transaction_id', 'order_id', 'transaction_number', 'created_time'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = 'transaction_id';
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
            $setup->createTable('支付交易表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '交易ID')
                ->addColumn(self::fields_ORDER_ID, TableInterface::column_type_INTEGER, null, 'not null', '订单ID')
                ->addColumn(self::fields_PAYMENT_METHOD, TableInterface::column_type_VARCHAR, 50, 'not null', '支付方式')
                ->addColumn(self::fields_TRANSACTION_NUMBER, TableInterface::column_type_VARCHAR, 128, '', '交易号')
                ->addColumn(self::fields_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '交易金额')
                ->addColumn(self::fields_CURRENCY, TableInterface::column_type_VARCHAR, 10, 'default \'CNY\'', '货币代码')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '交易状态')
                ->addColumn(self::fields_GATEWAY_RESPONSE, TableInterface::column_type_TEXT, null, '', '支付网关响应（JSON）')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_DATETIME, null, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_order_id', self::fields_ORDER_ID)
                ->addIndex(TableInterface::index_type_KEY, 'idx_transaction_number', self::fields_TRANSACTION_NUMBER)
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
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
     * 根据订单ID获取支付交易
     * 
     * @param int $orderId
     * @return array
     */
    public function getTransactionsByOrderId(int $orderId): array
    {
        return $this->where(self::fields_ORDER_ID, $orderId)
            ->order(self::fields_CREATED_TIME, 'DESC')
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

