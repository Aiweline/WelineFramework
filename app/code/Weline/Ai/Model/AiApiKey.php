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
    // 框架自动推导表名：AiApiKey → ai_api_key （遵循Constitution XI.A原则）
    // 禁止声明 protected $_table，让ORM自动推导

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';
    
    /**
     * Field name constants（参考AiModel.php和install()方法）
     */
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_TOKEN = 'token';
    public const fields_USER_ID = 'user_id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_STATUS = 'status';
    public const fields_QUOTA_DAILY = 'quota_daily'; // 每日成本控制限额（单位：元）
    public const fields_QUOTA_MONTHLY = 'quota_monthly'; // 每月成本控制限额（单位：元）
    public const fields_USAGE_DAILY = 'usage_daily'; // 今日已使用金额（单位：元）
    public const fields_USAGE_MONTHLY = 'usage_monthly'; // 本月已使用金额（单位：元）
    public const fields_CALL_COUNT = 'call_count'; // 累计调用次数
    public const fields_LAST_USED_TIME = 'last_used_time'; // 最后使用时间
    public const fields_LAST_USED_AT = 'last_used_at';
    public const fields_EXPIRES_AT = 'expires_at';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Initialize model（参考AiModel.php的成功案例）
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
        // 框架自动推导表名：AiApiKey → ai_api_key
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    public function upgrade(ModelSetup $setup, Context $context): void {}
    public function install(ModelSetup $setup, Context $context): void
    {
        $setup->getPrinting()->setup('安装数据表...' . self::table);

        if ($setup->tableExist() === false) {
            $setup->createTable('AI API密钥表')
                ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn('name', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '名称')
                ->addColumn('token', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', 'Token')
                ->addColumn('user_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '用户ID')
                ->addColumn('tenant_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '租户ID')
                ->addColumn('status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '状态')
                ->addColumn('quota_daily', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '每日配额')
                ->addColumn('quota_monthly', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '每月配额')
                ->addColumn('usage_daily', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '每日使用')
                ->addColumn('usage_monthly', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '每月使用')
                ->addColumn('last_used_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP, null, '', '最后使用时间')
                ->addColumn('expires_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP, null, '', '过期时间')
                ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP, null, 'not null DEFAULT CURRENT_TIMESTAMP', '创建时间')
                ->addColumn('updated_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP, null, 'not null DEFAULT CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_UNIQUE, 'idx_token', self::fields_TOKEN, 'Token唯一索引')
                ->create();
        }
    }

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
