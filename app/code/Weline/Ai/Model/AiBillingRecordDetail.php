<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/11
 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 计费记录详情模型
 * 
 * 功能：
 * - 记录详细计费项目
 * - 支持明细查询
 * - 提供账单拆分
 */
class AiBillingRecordDetail extends \Weline\Framework\Database\Model
{
    public const table = 'ai_billing_record_detail';
    public const fields_ID = 'id';
    public const fields_INVOICE_ID = 'invoice_id';
    public const fields_USAGE_LOG_ID = 'usage_log_id';
    public const fields_MODEL_CODE = 'model_code';
    public const fields_BILLING_TYPE = 'billing_type'; // token, request, subscription
    public const fields_QUANTITY = 'quantity';
    public const fields_UNIT_PRICE = 'unit_price';
    public const fields_SUBTOTAL = 'subtotal';
    public const fields_DISCOUNT = 'discount';
    public const fields_TAX = 'tax';
    public const fields_TOTAL = 'total';
    public const fields_CURRENCY = 'currency';
    public const fields_CREATED_TIME = 'created_time';

    /**
     * 计费类型常量
     */
    public const BILLING_TYPE_TOKEN = 'token';
    public const BILLING_TYPE_REQUEST = 'request';
    public const BILLING_TYPE_SUBSCRIPTION = 'subscription';
    public const BILLING_TYPE_OVERAGE = 'overage';

    /**
     * 设置模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: 实现升级逻辑
    }

    /**
     * 安装数据表
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '主键ID')
                ->addColumn(self::fields_INVOICE_ID, TableInterface::column_type_INTEGER, null, 'not null', '发票ID')
                ->addColumn(self::fields_USAGE_LOG_ID, TableInterface::column_type_INTEGER, null, 'null', '使用日志ID')
                ->addColumn(self::fields_MODEL_CODE, TableInterface::column_type_VARCHAR, 255, 'null', '模型代码')
                ->addColumn(self::fields_BILLING_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '计费类型')
                ->addColumn(self::fields_QUANTITY, TableInterface::column_type_DECIMAL, '15,4', 'not null', '数量')
                ->addColumn(self::fields_UNIT_PRICE, TableInterface::column_type_DECIMAL, '10,6', 'not null', '单价')
                ->addColumn(self::fields_SUBTOTAL, TableInterface::column_type_DECIMAL, '10,2', 'not null', '小计')
                ->addColumn(self::fields_DISCOUNT, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '折扣')
                ->addColumn(self::fields_TAX, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '税费')
                ->addColumn(self::fields_TOTAL, TableInterface::column_type_DECIMAL, '10,2', 'not null', '总计')
                ->addColumn(self::fields_CURRENCY, TableInterface::column_type_VARCHAR, 10, 'default "CNY"', '货币')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_invoice_id', self::fields_INVOICE_ID, '发票索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_usage_log_id', self::fields_USAGE_LOG_ID, '使用日志索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_model_code', self::fields_MODEL_CODE, '模型代码索引')
                ->create();
        }
    }

    /**
     * 计算总额（小计 - 折扣 + 税费）
     * 
     * @return float
     */
    public function calculateTotal(): float
    {
        $subtotal = (float)$this->getData(self::fields_SUBTOTAL);
        $discount = (float)$this->getData(self::fields_DISCOUNT);
        $tax = (float)$this->getData(self::fields_TAX);

        return round($subtotal - $discount + $tax, 2);
    }

    /**
     * 获取格式化总额
     * 
     * @return string
     */
    public function getFormattedTotal(): string
    {
        $total = $this->getData(self::fields_TOTAL);
        $currency = $this->getData(self::fields_CURRENCY);

        $currencySymbol = match($currency) {
            'CNY' => '¥',
            'USD' => '$',
            'EUR' => '€',
            default => $currency . ' ',
        };

        return $currencySymbol . number_format((float)$total, 2);
    }

    /**
     * 保存前处理
     * 
     * @return $this
     */
    public function beforeSave(): self
    {
        parent::beforeSave();
        
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, time());
        }
        
        // 自动计算总额
        if (!$this->getData(self::fields_TOTAL)) {
            $this->setData(self::fields_TOTAL, $this->calculateTotal());
        }
        
        return $this;
    }
}

