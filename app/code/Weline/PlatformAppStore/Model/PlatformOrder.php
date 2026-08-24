<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '平台订单表')]
#[Index(name: 'idx_order_number', columns: ['order_number'], type: 'UNIQUE', comment: '订单号唯一索引')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'KEY', comment: '客户ID索引')]
#[Index(name: 'idx_module_id', columns: ['module_id'], type: 'KEY', comment: '模块ID索引')]
#[Index(name: 'idx_status', columns: ['status'], type: 'KEY', comment: '状态索引')]
#[Index(name: 'idx_created_at', columns: ['created_at'], type: 'KEY', comment: '创建时间索引')]
class PlatformOrder extends Model
{
    public const schema_table = 'weline_platform_order';
    public const schema_primary_key = 'order_id';

    // 订单类型常量
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_SUBSCRIPTION = 'subscription';
    public const TYPE_RENEWAL = 'renewal';

    // 订单状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '订单ID')]
    public const schema_fields_ID = 'order_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '订单号')]
    public const schema_fields_order_number = 'order_number';

    #[Col(type: 'int', nullable: false, comment: '客户ID')]
    public const schema_fields_customer_id = 'customer_id';

    #[Col(type: 'int', nullable: false, comment: '模块ID')]
    public const schema_fields_module_id = 'module_id';

    #[Col(type: 'int', nullable: true, comment: '版本ID')]
    public const schema_fields_version_id = 'version_id';

    #[Col(type: 'varchar', length: 20, nullable: false, comment: '订单类型')]
    public const schema_fields_type = 'type';

    #[Col(type: 'decimal', length: '10,2', nullable: false, comment: '订单金额')]
    public const schema_fields_amount = 'amount';

    #[Col(type: 'varchar', length: 10, nullable: false, comment: '货币代码')]
    public const schema_fields_currency = 'currency';

    #[Col(type: 'varchar', length: 50, nullable: false, default: self::STATUS_PENDING, comment: '订单状态')]
    public const schema_fields_status = 'status';

    #[Col(type: 'int', nullable: true, comment: '生成的许可证ID')]
    public const schema_fields_license_id = 'license_id';

    #[Col(type: 'varchar', length: 100, nullable: true, comment: '支付方式')]
    public const schema_fields_payment_method = 'payment_method';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '支付交易ID')]
    public const schema_fields_transaction_id = 'transaction_id';

    #[Col(type: 'datetime', nullable: true, comment: '支付时间')]
    public const schema_fields_paid_at = 'paid_at';

    #[Col(type: 'text', nullable: true, comment: '订单备注')]
    public const schema_fields_notes = 'notes';

    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public function getOrderId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setOrderId(int $orderId): static
    {
        $this->setData(self::schema_fields_ID, $orderId);
        return $this;
    }

    public function getOrderNumber(): string
    {
        return $this->getData(self::schema_fields_order_number) ?? '';
    }

    public function setOrderNumber(string $orderNumber): static
    {
        $this->setData(self::schema_fields_order_number, $orderNumber);
        return $this;
    }

    public function getCustomerId(): int
    {
        return (int)$this->getData(self::schema_fields_customer_id);
    }

    public function setCustomerId(int $customerId): static
    {
        $this->setData(self::schema_fields_customer_id, $customerId);
        return $this;
    }

    public function getModuleId(): int
    {
        return (int)$this->getData(self::schema_fields_module_id);
    }

    public function setModuleId(int $moduleId): static
    {
        $this->setData(self::schema_fields_module_id, $moduleId);
        return $this;
    }

    public function getVersionId(): ?int
    {
        return $this->getData(self::schema_fields_version_id) ? (int)$this->getData(self::schema_fields_version_id) : null;
    }

    public function setVersionId(?int $versionId): static
    {
        $this->setData(self::schema_fields_version_id, $versionId);
        return $this;
    }

    public function getType(): string
    {
        return $this->getData(self::schema_fields_type) ?? self::TYPE_PURCHASE;
    }

    public function setType(string $type): static
    {
        $this->setData(self::schema_fields_type, $type);
        return $this;
    }

    public function getAmount(): float
    {
        return (float)$this->getData(self::schema_fields_amount);
    }

    public function setAmount(float $amount): static
    {
        $this->setData(self::schema_fields_amount, $amount);
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->getData(self::schema_fields_currency) ?? 'CNY';
    }

    public function setCurrency(string $currency): static
    {
        $this->setData(self::schema_fields_currency, $currency);
        return $this;
    }

    public function getStatus(): string
    {
        return $this->getData(self::schema_fields_status) ?? self::STATUS_PENDING;
    }

    public function setStatus(string $status): static
    {
        $this->setData(self::schema_fields_status, $status);
        return $this;
    }

    public function getLicenseId(): ?int
    {
        return $this->getData(self::schema_fields_license_id) ? (int)$this->getData(self::schema_fields_license_id) : null;
    }

    public function setLicenseId(?int $licenseId): static
    {
        $this->setData(self::schema_fields_license_id, $licenseId);
        return $this;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->getData(self::schema_fields_payment_method);
    }

    public function setPaymentMethod(?string $paymentMethod): static
    {
        $this->setData(self::schema_fields_payment_method, $paymentMethod);
        return $this;
    }

    public function getTransactionId(): ?string
    {
        return $this->getData(self::schema_fields_transaction_id);
    }

    public function setTransactionId(?string $transactionId): static
    {
        $this->setData(self::schema_fields_transaction_id, $transactionId);
        return $this;
    }

    public function getPaidAt(): ?string
    {
        return $this->getData(self::schema_fields_paid_at);
    }

    public function setPaidAt(?string $paidAt): static
    {
        $this->setData(self::schema_fields_paid_at, $paidAt);
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->getData(self::schema_fields_notes);
    }

    public function setNotes(?string $notes): static
    {
        $this->setData(self::schema_fields_notes, $notes);
        return $this;
    }

    public function isPaid(): bool
    {
        return in_array($this->getStatus(), [self::STATUS_PAID, self::STATUS_COMPLETED]);
    }

    public function isCompleted(): bool
    {
        return $this->getStatus() === self::STATUS_COMPLETED;
    }

    public function generateOrderNumber(): string
    {
        return 'ORD' . date('YmdHis') . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function markAsPaid(string $paymentMethod, string $transactionId): static
    {
        $this->setStatus(self::STATUS_PAID);
        $this->setPaymentMethod($paymentMethod);
        $this->setTransactionId($transactionId);
        $this->setPaidAt(date('Y-m-d H:i:s'));
        return $this;
    }

    public function complete(): static
    {
        $this->setStatus(self::STATUS_COMPLETED);
        return $this;
    }
}
