<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * AI Tenant Entity
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI租户表')]
#[Index(name: 'idx_domain', columns: ['domain'])]
#[Index(name: 'idx_status', columns: ['status'])]
class AiTenant extends Model
{
    public const schema_table = 'ai_tenant';
    public const schema_primary_key = 'id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '租户名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '域名')]
    public const schema_fields_DOMAIN = 'domain';
    #[Col(type: 'text', nullable: true, comment: '配置JSON')]
    public const schema_fields_CONFIG = 'config';
    #[Col(type: 'int', nullable: true, comment: '月度配额')]
    public const schema_fields_QUOTA_MONTHLY = 'quota_monthly';
    #[Col(type: 'int', nullable: true, default: 0, comment: '月度使用量')]
    public const schema_fields_USAGE_MONTHLY = 'usage_monthly';
    #[Col(type: 'varchar', length: 20, default: 'free', comment: '计费计划')]
    public const schema_fields_BILLING_PLAN = 'billing_plan';
    #[Col(type: 'varchar', length: 20, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', nullable: true, default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'int', nullable: true, default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const PLAN_FREE = 'free';
    public const PLAN_BASIC = 'basic';
    public const PLAN_PREMIUM = 'premium';
    public const PLAN_ENTERPRISE = 'enterprise';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    /** Whether the tenant is active */
    public function isActive(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_ACTIVE;
    }

    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    public function hasQuota(): bool
    {
        $monthlyQuota = $this->getData(self::schema_fields_QUOTA_MONTHLY);
        if (!$monthlyQuota) {
            return true;
        }

        $monthlyUsage = $this->getData(self::schema_fields_USAGE_MONTHLY);
        return (int) $monthlyUsage < (int) $monthlyQuota;
    }

    public function validate(): bool
    {
        if (empty($this->getData(self::schema_fields_NAME))) {
            throw new \InvalidArgumentException('Tenant name is required');
        }

        return true;
    }

    public function beforeSave(): self
    {
        $this->validate();

        $config = $this->getData(self::schema_fields_CONFIG);
        if (is_array($config)) {
            $this->setData(self::schema_fields_CONFIG, json_encode($config));
        }

        return parent::beforeSave();
    }
}
