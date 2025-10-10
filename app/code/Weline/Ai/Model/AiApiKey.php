<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI API Key Entity
 * 
 * Manages API keys with quota tracking and status management.
 * 
 * @package Weline_Ai
 */
class AiApiKey extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';

    public function _init(): void
    {
        $this->_table = 'ai_api_key';
        $this->_id_field_name = 'id';
    }

    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    public function install(ModelSetup $setup, Context $context): void {}

    public function isActive(): bool
    {
        return $this->getData('status') === self::STATUS_APPROVED 
            && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->getData('expires_at');
        return $expiresAt && strtotime($expiresAt) < time();
    }

    public function hasQuota(): bool
    {
        $dailyQuota = $this->getData('quota_daily');
        $monthlyQuota = $this->getData('quota_monthly');
        $dailyUsage = $this->getData('usage_daily');
        $monthlyUsage = $this->getData('usage_monthly');

        if ($dailyQuota && $dailyUsage >= $dailyQuota) {
            return false;
        }

        if ($monthlyQuota && $monthlyUsage >= $monthlyQuota) {
            return false;
        }

        return true;
    }

    public function incrementUsage(): void
    {
        $this->setData('usage_daily', $this->getData('usage_daily') + 1);
        $this->setData('usage_monthly', $this->getData('usage_monthly') + 1);
        $this->setData('last_used_at', date('Y-m-d H:i:s'));
    }

    public function validate(): bool
    {
        if (empty($this->getData('name'))) {
            throw new \InvalidArgumentException('API Key name is required');
        }

        if (empty($this->getData('token'))) {
            throw new \InvalidArgumentException('API Key token is required');
        }

        if (empty($this->getData('user_id'))) {
            throw new \InvalidArgumentException('User ID is required');
        }

        if (empty($this->getData('tenant_id'))) {
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
