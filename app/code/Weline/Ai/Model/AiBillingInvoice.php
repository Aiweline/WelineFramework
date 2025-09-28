<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AI计费发票数据模型
 * 
 * 功能：
 * - 管理计费发票
 * - 支付状态跟踪
 * - 发票生成和管理
 * - 计费历史记录
 */
class AiBillingInvoice extends Model
{
    public const table = 'ai_billing_invoice';
    
    // 字段常量
    public const fields_ID = 'id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_INVOICE_NUMBER = 'invoice_number';
    public const fields_AMOUNT = 'amount';
    public const fields_CURRENCY = 'currency';
    public const fields_STATUS = 'status';
    public const fields_DUE_DATE = 'due_date';
    public const fields_PAID_DATE = 'paid_date';
    public const fields_PAYMENT_METHOD = 'payment_method';
    public const fields_TRANSACTION_ID = 'transaction_id';
    public const fields_ITEMS = 'items';
    public const fields_TAX_AMOUNT = 'tax_amount';
    public const fields_DISCOUNT_AMOUNT = 'discount_amount';
    public const fields_TOTAL_AMOUNT = 'total_amount';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

    // 发票状态常量
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_TENANT_ID, TableInterface::column_type_INTEGER, 11, 'not null', '租户ID')
                ->addColumn(self::fields_INVOICE_NUMBER, TableInterface::column_type_VARCHAR, 100, 'not null unique', '发票号')
                ->addColumn(self::fields_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'not null', '金额')
                ->addColumn(self::fields_CURRENCY, TableInterface::column_type_VARCHAR, 3, 'not null default "USD"', '货币')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 50, 'not null default "draft"', '发票状态')
                ->addColumn(self::fields_DUE_DATE, TableInterface::column_type_INTEGER, 11, 'null', '到期日期')
                ->addColumn(self::fields_PAID_DATE, TableInterface::column_type_INTEGER, 11, 'null', '支付日期')
                ->addColumn(self::fields_PAYMENT_METHOD, TableInterface::column_type_VARCHAR, 50, 'null', '支付方式')
                ->addColumn(self::fields_TRANSACTION_ID, TableInterface::column_type_VARCHAR, 255, 'null', '交易ID')
                ->addColumn(self::fields_ITEMS, TableInterface::column_type_TEXT, null, 'null', '发票项目JSON')
                ->addColumn(self::fields_TAX_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '税费金额')
                ->addColumn(self::fields_DISCOUNT_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '折扣金额')
                ->addColumn(self::fields_TOTAL_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'not null', '总金额')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_tenant_id', self::fields_TENANT_ID, '租户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_invoice_number', self::fields_INVOICE_NUMBER, '发票号索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_due_date', self::fields_DUE_DATE, '到期日期索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_paid_date', self::fields_PAID_DATE, '支付日期索引')
                ->create();
        }
    }

    /**
     * 获取租户ID
     * 
     * @return int
     */
    public function getTenantId(): int
    {
        return (int)$this->getData(self::fields_TENANT_ID);
    }

    /**
     * 获取发票号
     * 
     * @return string
     */
    public function getInvoiceNumber(): string
    {
        return $this->getData(self::fields_INVOICE_NUMBER) ?? '';
    }

    /**
     * 获取金额
     * 
     * @return float
     */
    public function getAmount(): float
    {
        return (float)$this->getData(self::fields_AMOUNT);
    }

    /**
     * 获取货币
     * 
     * @return string
     */
    public function getCurrency(): string
    {
        return $this->getData(self::fields_CURRENCY) ?? 'USD';
    }

    /**
     * 获取发票状态
     * 
     * @return string
     */
    public function getStatus(): string
    {
        return $this->getData(self::fields_STATUS) ?? self::STATUS_DRAFT;
    }

    /**
     * 设置发票状态
     * 
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self
    {
        $this->setData(self::fields_STATUS, $status);
        return $this;
    }

    /**
     * 获取到期日期
     * 
     * @return int
     */
    public function getDueDate(): int
    {
        return (int)$this->getData(self::fields_DUE_DATE);
    }

    /**
     * 设置到期日期
     * 
     * @param int $date
     * @return $this
     */
    public function setDueDate(int $date): self
    {
        $this->setData(self::fields_DUE_DATE, $date);
        return $this;
    }

    /**
     * 获取支付日期
     * 
     * @return int
     */
    public function getPaidDate(): int
    {
        return (int)$this->getData(self::fields_PAID_DATE);
    }

    /**
     * 设置支付日期
     * 
     * @param int $date
     * @return $this
     */
    public function setPaidDate(int $date): self
    {
        $this->setData(self::fields_PAID_DATE, $date);
        return $this;
    }

    /**
     * 获取支付方式
     * 
     * @return string
     */
    public function getPaymentMethod(): string
    {
        return $this->getData(self::fields_PAYMENT_METHOD) ?? '';
    }

    /**
     * 设置支付方式
     * 
     * @param string $method
     * @return $this
     */
    public function setPaymentMethod(string $method): self
    {
        $this->setData(self::fields_PAYMENT_METHOD, $method);
        return $this;
    }

    /**
     * 获取交易ID
     * 
     * @return string
     */
    public function getTransactionId(): string
    {
        return $this->getData(self::fields_TRANSACTION_ID) ?? '';
    }

    /**
     * 设置交易ID
     * 
     * @param string $transactionId
     * @return $this
     */
    public function setTransactionId(string $transactionId): self
    {
        $this->setData(self::fields_TRANSACTION_ID, $transactionId);
        return $this;
    }

    /**
     * 获取发票项目
     * 
     * @return array
     */
    public function getItems(): array
    {
        $items = $this->getData(self::fields_ITEMS);
        return $items ? json_decode($items, true) : [];
    }

    /**
     * 设置发票项目
     * 
     * @param array $items
     * @return $this
     */
    public function setItems(array $items): self
    {
        $this->setData(self::fields_ITEMS, json_encode($items));
        return $this;
    }

    /**
     * 获取税费金额
     * 
     * @return float
     */
    public function getTaxAmount(): float
    {
        return (float)$this->getData(self::fields_TAX_AMOUNT);
    }

    /**
     * 设置税费金额
     * 
     * @param float $amount
     * @return $this
     */
    public function setTaxAmount(float $amount): self
    {
        $this->setData(self::fields_TAX_AMOUNT, $amount);
        return $this;
    }

    /**
     * 获取折扣金额
     * 
     * @return float
     */
    public function getDiscountAmount(): float
    {
        return (float)$this->getData(self::fields_DISCOUNT_AMOUNT);
    }

    /**
     * 设置折扣金额
     * 
     * @param float $amount
     * @return $this
     */
    public function setDiscountAmount(float $amount): self
    {
        $this->setData(self::fields_DISCOUNT_AMOUNT, $amount);
        return $this;
    }

    /**
     * 获取总金额
     * 
     * @return float
     */
    public function getTotalAmount(): float
    {
        return (float)$this->getData(self::fields_TOTAL_AMOUNT);
    }

    /**
     * 设置总金额
     * 
     * @param float $amount
     * @return $this
     */
    public function setTotalAmount(float $amount): self
    {
        $this->setData(self::fields_TOTAL_AMOUNT, $amount);
        return $this;
    }

    /**
     * 获取状态显示名称
     * 
     * @return string
     */
    public function getStatusDisplayName(): string
    {
        $statusNames = [
            self::STATUS_DRAFT => '草稿',
            self::STATUS_PENDING => '待支付',
            self::STATUS_PAID => '已支付',
            self::STATUS_OVERDUE => '逾期',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_REFUNDED => '已退款'
        ];

        return $statusNames[$this->getStatus()] ?? $this->getStatus();
    }

    /**
     * 检查是否为草稿状态
     * 
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->getStatus() === self::STATUS_DRAFT;
    }

    /**
     * 检查是否为待支付状态
     * 
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->getStatus() === self::STATUS_PENDING;
    }

    /**
     * 检查是否已支付
     * 
     * @return bool
     */
    public function isPaid(): bool
    {
        return $this->getStatus() === self::STATUS_PAID;
    }

    /**
     * 检查是否逾期
     * 
     * @return bool
     */
    public function isOverdue(): bool
    {
        return $this->getStatus() === self::STATUS_OVERDUE;
    }

    /**
     * 检查是否已取消
     * 
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->getStatus() === self::STATUS_CANCELLED;
    }

    /**
     * 检查是否已退款
     * 
     * @return bool
     */
    public function isRefunded(): bool
    {
        return $this->getStatus() === self::STATUS_REFUNDED;
    }

    /**
     * 标记为已支付
     * 
     * @param string $paymentMethod 支付方式
     * @param string $transactionId 交易ID
     * @return $this
     */
    public function markAsPaid(string $paymentMethod, string $transactionId = ''): self
    {
        $this->setStatus(self::STATUS_PAID)
             ->setPaidDate(time())
             ->setPaymentMethod($paymentMethod);
             
        if ($transactionId) {
            $this->setTransactionId($transactionId);
        }
        
        return $this;
    }

    /**
     * 标记为逾期
     * 
     * @return $this
     */
    public function markAsOverdue(): self
    {
        $this->setStatus(self::STATUS_OVERDUE);
        return $this;
    }

    /**
     * 取消发票
     * 
     * @return $this
     */
    public function cancel(): self
    {
        $this->setStatus(self::STATUS_CANCELLED);
        return $this;
    }

    /**
     * 退款
     * 
     * @return $this
     */
    public function refund(): self
    {
        $this->setStatus(self::STATUS_REFUNDED);
        return $this;
    }

    /**
     * 获取格式化金额
     * 
     * @return string
     */
    public function getFormattedAmount(): string
    {
        $amount = $this->getTotalAmount();
        $currency = $this->getCurrency();
        
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CNY' => '¥',
            'JPY' => '¥'
        ];

        $symbol = $symbols[$currency] ?? $currency;
        return $symbol . number_format($amount, 2);
    }

    /**
     * 计算总金额
     * 
     * @return float
     */
    public function calculateTotalAmount(): float
    {
        $amount = $this->getAmount();
        $taxAmount = $this->getTaxAmount();
        $discountAmount = $this->getDiscountAmount();
        
        return $amount + $taxAmount - $discountAmount;
    }

    /**
     * 检查是否逾期
     * 
     * @return bool
     */
    public function checkOverdue(): bool
    {
        if ($this->isPaid() || $this->isCancelled() || $this->isRefunded()) {
            return false;
        }

        $dueDate = $this->getDueDate();
        return $dueDate > 0 && $dueDate < time();
    }

    /**
     * 生成发票号
     * 
     * @return string
     */
    public function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $date = date('Ymd');
        $random = str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        return $prefix . $date . $random;
    }

    /**
     * 保存前的数据处理
     * 
     * @return $this
     */
    public function beforeSave(): self
    {
        parent::beforeSave();
        
        $currentTime = time();
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, $currentTime);
            
            // 生成发票号
            if (!$this->getInvoiceNumber()) {
                $this->setData(self::fields_INVOICE_NUMBER, $this->generateInvoiceNumber());
            }
        }
        $this->setData(self::fields_UPDATED_TIME, $currentTime);
        
        // 计算总金额
        $this->setTotalAmount($this->calculateTotalAmount());
        
        return $this;
    }
}
