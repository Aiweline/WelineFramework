<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Tenant Entity
 * 
 * @package Weline_Ai
 */
class AiTenant extends Model
{
    public const PLAN_FREE = 'free';
    public const PLAN_BASIC = 'basic';
    public const PLAN_PREMIUM = 'premium';
    public const PLAN_ENTERPRISE = 'enterprise';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    public function _init(): void
    {
        $this->_table = 'ai_tenant';
        $this->_id_field_name = 'id';
    }

    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    public function install(ModelSetup $setup, Context $context): void {}

    public function isActive(): bool
    {
        return $this->getData('status') === self::STATUS_ACTIVE;
    }

    public function getConfig(): array
    {
        $config = $this->getData('config');
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    public function hasQuota(): bool
    {
        $monthlyQuota = $this->getData('quota_monthly');
        if (!$monthlyQuota) {
            return true;
        }

        $monthlyUsage = $this->getData('usage_monthly');
        return $monthlyUsage < $monthlyQuota;
    }

    public function validate(): bool
    {
        if (empty($this->getData('name'))) {
            throw new \InvalidArgumentException('Tenant name is required');
        }

        return true;
    }

    public function beforeSave(): self
    {
        $this->validate();
        
        if (is_array($this->getData('config'))) {
            $this->setData('config', json_encode($this->getData('config')));
        }

        return parent::beforeSave();
    }
}
