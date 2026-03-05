<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * AI API Key Entity
 *
 * Manages API keys with quota tracking and status management.
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI API密钥表')]
#[Index(name: 'idx_token', columns: ['token'], type: 'UNIQUE', comment: 'Token唯一索引')]
class AiApiKey extends Model
{
    public const schema_table = 'weline_ai_ai_api_key';
    public const schema_primary_key = 'id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Token')]
    public const schema_fields_TOKEN = 'token';
    #[Col(type: 'int', nullable: false, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'int', nullable: false, comment: '租户ID')]
    public const schema_fields_TENANT_ID = 'tenant_id';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', nullable: true, default: 0, comment: '每日配额')]
    public const schema_fields_QUOTA_DAILY = 'quota_daily';
    #[Col(type: 'int', nullable: true, default: 0, comment: '每月配额')]
    public const schema_fields_QUOTA_MONTHLY = 'quota_monthly';
    #[Col(type: 'int', nullable: true, default: 0, comment: '每日使用')]
    public const schema_fields_USAGE_DAILY = 'usage_daily';
    #[Col(type: 'int', nullable: true, default: 0, comment: '每月使用')]
    public const schema_fields_USAGE_MONTHLY = 'usage_monthly';
    #[Col(type: 'int', nullable: true, default: 0, comment: '调用次数')]
    public const schema_fields_CALL_COUNT = 'call_count';
    #[Col(type: 'int', nullable: true, comment: '最后使用时间戳')]
    public const schema_fields_LAST_USED_TIME = 'last_used_time';
    #[Col(type: 'timestamp', nullable: true, comment: '最后使用时间')]
    public const schema_fields_LAST_USED_AT = 'last_used_at';
    #[Col(type: 'timestamp', nullable: true, comment: '过期时间')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col(type: 'timestamp', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'timestamp', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';

    public array $_unit_primary_keys = ['id'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    /** Whether the key is active and not expired */
    public function isActive(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_APPROVED
            && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->getData(self::schema_fields_EXPIRES_AT);
        return $expiresAt && strtotime((string) $expiresAt) < time();
    }

    public function hasQuota(): bool
    {
        $dailyQuota = $this->getData(self::schema_fields_QUOTA_DAILY);
        $monthlyQuota = $this->getData(self::schema_fields_QUOTA_MONTHLY);
        $dailyUsage = $this->getData(self::schema_fields_USAGE_DAILY);
        $monthlyUsage = $this->getData(self::schema_fields_USAGE_MONTHLY);

        if ($dailyQuota && (int) $dailyUsage >= (int) $dailyQuota) {
            return false;
        }

        if ($monthlyQuota && (int) $monthlyUsage >= (int) $monthlyQuota) {
            return false;
        }

        return true;
    }

    public function incrementUsage(): void
    {
        $this->setData(self::schema_fields_USAGE_DAILY, (int) $this->getData(self::schema_fields_USAGE_DAILY) + 1);
        $this->setData(self::schema_fields_USAGE_MONTHLY, (int) $this->getData(self::schema_fields_USAGE_MONTHLY) + 1);
        $this->setData(self::schema_fields_LAST_USED_AT, date('Y-m-d H:i:s'));
    }

    public function validate(): bool
    {
        if (empty($this->getData(self::schema_fields_NAME))) {
            throw new \InvalidArgumentException('API Key name is required');
        }

        if (empty($this->getData(self::schema_fields_TOKEN))) {
            throw new \InvalidArgumentException('API Key token is required');
        }

        if (empty($this->getData(self::schema_fields_USER_ID))) {
            throw new \InvalidArgumentException('User ID is required');
        }

        if (empty($this->getData(self::schema_fields_TENANT_ID))) {
            throw new \InvalidArgumentException('Tenant ID is required');
        }

        return true;
    }

    public function beforeSave(): self
    {
        $this->validate();
        return parent::beforeSave();
    }
}
