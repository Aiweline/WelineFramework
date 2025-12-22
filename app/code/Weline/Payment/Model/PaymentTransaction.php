<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class PaymentTransaction extends AbstractModel
{
    public const table = 'weline_payment_transaction';
    
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    
    public const fields_ID = 'transaction_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_METHOD_CODE = 'method_code';
    public const fields_TRANSACTION_NO = 'transaction_no';
    public const fields_AMOUNT = 'amount';
    public const fields_CURRENCY = 'currency';
    public const fields_STATUS = 'status';
    public const fields_REQUEST_DATA = 'request_data';
    public const fields_RESPONSE_DATA = 'response_data';
    public const fields_CALLBACK_DATA = 'callback_data';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    public const fields_PAID_AT = 'paid_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['transaction_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['transaction_id', 'order_id', 'transaction_no', 'status'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('支付交易表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '交易ID'
                )
                ->addColumn(
                    self::fields_ORDER_ID,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '订单ID'
                )
                ->addColumn(
                    self::fields_METHOD_CODE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '支付方式代码'
                )
                ->addColumn(
                    self::fields_TRANSACTION_NO,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null unique',
                    '交易号（唯一）'
                )
                ->addColumn(
                    self::fields_AMOUNT,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'not null',
                    '支付金额'
                )
                ->addColumn(
                    self::fields_CURRENCY,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'default \'CNY\'',
                    '货币代码'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default \'pending\'',
                    '支付状态：pending-待支付，processing-处理中，success-成功，failed-失败，refunded-已退款'
                )
                ->addColumn(
                    self::fields_REQUEST_DATA,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '请求数据（JSON）'
                )
                ->addColumn(
                    self::fields_RESPONSE_DATA,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '响应数据（JSON）'
                )
                ->addColumn(
                    self::fields_CALLBACK_DATA,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '回调数据（JSON）'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    '更新时间'
                )
                ->addColumn(
                    self::fields_PAID_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default null',
                    '支付完成时间'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_transaction_no',
                    self::fields_TRANSACTION_NO,
                    '交易号唯一索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_order_id',
                    self::fields_ORDER_ID,
                    '订单ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_method_code',
                    self::fields_METHOD_CODE,
                    '支付方式代码索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status',
                    self::fields_STATUS,
                    '支付状态索引'
                )
                ->create();
        }
    }

    /**
     * 设置表结构（开发模式）
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * 是否待支付
     * 
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_PENDING;
    }

    /**
     * 是否处理中
     * 
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_PROCESSING;
    }

    /**
     * 是否成功
     * 
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_SUCCESS;
    }

    /**
     * 是否失败
     * 
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_FAILED;
    }

    /**
     * 是否已退款
     * 
     * @return bool
     */
    public function isRefunded(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_REFUNDED;
    }

    /**
     * 获取请求数据
     * 
     * @return array
     */
    public function getRequestData(): array
    {
        $data = $this->getData(self::fields_REQUEST_DATA);
        if (empty($data)) {
            return [];
        }
        if (is_string($data)) {
            return json_decode($data, true) ?? [];
        }
        return $data;
    }

    /**
     * 设置请求数据
     * 
     * @param array $data
     * @return $this
     */
    public function setRequestData(array $data): static
    {
        return $this->setData(self::fields_REQUEST_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取响应数据
     * 
     * @return array
     */
    public function getResponseData(): array
    {
        $data = $this->getData(self::fields_RESPONSE_DATA);
        if (empty($data)) {
            return [];
        }
        if (is_string($data)) {
            return json_decode($data, true) ?? [];
        }
        return $data;
    }

    /**
     * 设置响应数据
     * 
     * @param array $data
     * @return $this
     */
    public function setResponseData(array $data): static
    {
        return $this->setData(self::fields_RESPONSE_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取回调数据
     * 
     * @return array
     */
    public function getCallbackData(): array
    {
        $data = $this->getData(self::fields_CALLBACK_DATA);
        if (empty($data)) {
            return [];
        }
        if (is_string($data)) {
            return json_decode($data, true) ?? [];
        }
        return $data;
    }

    /**
     * 设置回调数据
     * 
     * @param array $data
     * @return $this
     */
    public function setCallbackData(array $data): static
    {
        return $this->setData(self::fields_CALLBACK_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}

