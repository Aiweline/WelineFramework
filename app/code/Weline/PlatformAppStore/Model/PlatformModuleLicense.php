<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '平台模块许可证表')]
#[Index(name: 'idx_license_key', columns: ['license_key'], type: 'UNIQUE', comment: '许可证密钥唯一索引')]
#[Index(name: 'idx_module_id', columns: ['module_id'], type: 'KEY', comment: '模块ID索引')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'KEY', comment: '客户ID索引')]
#[Index(name: 'idx_domain', columns: ['domain'], type: 'KEY', comment: '域名索引')]
#[Index(name: 'idx_status', columns: ['status'], type: 'KEY', comment: '状态索引')]
class PlatformModuleLicense extends Model
{
    public const schema_table = 'weline_platform_module_license';
    public const schema_primary_key = 'license_id';

    // 许可证状态常量
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '许可证ID')]
    public const schema_fields_ID = 'license_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '许可证密钥')]
    public const schema_fields_license_key = 'license_key';

    #[Col(type: 'int', nullable: false, comment: '模块ID')]
    public const schema_fields_module_id = 'module_id';

    #[Col(type: 'int', nullable: false, comment: '订单ID')]
    public const schema_fields_order_id = 'order_id';

    #[Col(type: 'int', nullable: false, comment: '购买者ID')]
    public const schema_fields_customer_id = 'customer_id';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '绑定域名')]
    public const schema_fields_domain = 'domain';

    #[Col(type: 'varchar', length: 20, nullable: false, default: self::STATUS_INACTIVE, comment: '状态')]
    public const schema_fields_status = 'status';

    #[Col(type: 'datetime', nullable: true, comment: '过期时间 (订阅制)')]
    public const schema_fields_expires_at = 'expires_at';

    #[Col(type: 'datetime', nullable: true, comment: '激活时间')]
    public const schema_fields_activated_at = 'activated_at';

    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public function getLicenseId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setLicenseId(int $licenseId): static
    {
        $this->setData(self::schema_fields_ID, $licenseId);
        return $this;
    }

    public function getLicenseKey(): string
    {
        return $this->getData(self::schema_fields_license_key) ?? '';
    }

    public function setLicenseKey(string $licenseKey): static
    {
        $this->setData(self::schema_fields_license_key, $licenseKey);
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

    public function getOrderId(): int
    {
        return (int)$this->getData(self::schema_fields_order_id);
    }

    public function setOrderId(int $orderId): static
    {
        $this->setData(self::schema_fields_order_id, $orderId);
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

    public function getDomain(): ?string
    {
        return $this->getData(self::schema_fields_domain);
    }

    public function setDomain(?string $domain): static
    {
        $this->setData(self::schema_fields_domain, $domain);
        return $this;
    }

    public function getStatus(): string
    {
        return $this->getData(self::schema_fields_status) ?? self::STATUS_INACTIVE;
    }

    public function setStatus(string $status): static
    {
        $this->setData(self::schema_fields_status, $status);
        return $this;
    }

    public function getExpiresAt(): ?string
    {
        return $this->getData(self::schema_fields_expires_at);
    }

    public function setExpiresAt(?string $expiresAt): static
    {
        $this->setData(self::schema_fields_expires_at, $expiresAt);
        return $this;
    }

    public function getActivatedAt(): ?string
    {
        return $this->getData(self::schema_fields_activated_at);
    }

    public function setActivatedAt(?string $activatedAt): static
    {
        $this->setData(self::schema_fields_activated_at, $activatedAt);
        return $this;
    }

    public function isActive(): bool
    {
        if ($this->getStatus() !== self::STATUS_ACTIVE) {
            return false;
        }

        // 检查是否过期
        $expiresAt = $this->getExpiresAt();
        if ($expiresAt && strtotime($expiresAt) < time()) {
            return false;
        }

        return true;
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();
        return $expiresAt && strtotime($expiresAt) < time();
    }

    public function isActivated(): bool
    {
        return !empty($this->getDomain());
    }

    public function activate(string $domain): static
    {
        $this->setDomain($domain);
        $this->setStatus(self::STATUS_ACTIVE);
        $this->setActivatedAt(date('Y-m-d H:i:s'));
        return $this;
    }

    public function revoke(): static
    {
        $this->setStatus(self::STATUS_REVOKED);
        return $this;
    }
}
