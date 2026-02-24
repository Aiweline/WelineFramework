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
    // 字段常量
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_DOMAIN = 'domain';
    public const fields_CONFIG = 'config';
    public const fields_QUOTA_MONTHLY = 'quota_monthly';
    public const fields_USAGE_MONTHLY = 'usage_monthly';
    public const fields_BILLING_PLAN = 'billing_plan';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 计费计划常量
    public const PLAN_FREE = 'free';
    public const PLAN_BASIC = 'basic';
    public const PLAN_PREMIUM = 'premium';
    public const PLAN_ENTERPRISE = 'enterprise';

    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    public function _init(): void
    {
        $this->useMainDbMaster();
        // 表名由框架自动推导：AiTenant -> ai_tenant
    }

    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('AI租户表')
                ->addColumn('id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn('name', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '租户名称')
                ->addColumn('domain', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, '', '域名')
                ->addColumn('config', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '配置JSON')
                ->addColumn('quota_monthly', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, '', '月度配额')
                ->addColumn('usage_monthly', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '月度使用量')
                ->addColumn('billing_plan', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'free\'', '计费计划')
                ->addColumn('status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'active\'', '状态')
                ->addColumn('created_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn('updated_at', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_DEFAULT, 'idx_domain', 'domain')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_DEFAULT, 'idx_status', 'status')
                ->create();
        }
        // 初始数据使用模型 save() 插入，避免 SQL 方言差异（PostgreSQL/MySQL/SQLite）
        if ($this->reset()->total() === 0) {
            $this->setData([
                'name' => 'Default Tenant',
                'domain' => 'default.localhost',
                'config' => ['timezone' => 'Asia/Shanghai', 'locale' => 'zh_Hans_CN'],
                'billing_plan' => self::PLAN_ENTERPRISE,
                'status' => self::STATUS_ACTIVE,
            ])->save();
        }
    }

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
