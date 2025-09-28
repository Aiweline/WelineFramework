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
 * AI租户数据模型
 * 
 * 功能：
 * - 管理多租户信息
 * - 租户数据隔离
 * - 资源配额管理
 * - 计费信息管理
 */
class AiTenant extends Model
{
    public const table = 'ai_tenant';
    
    // 字段常量
    public const fields_ID = 'id';
    public const fields_TENANT_NAME = 'tenant_name';
    public const fields_TENANT_CODE = 'tenant_code';
    public const fields_TENANT_TYPE = 'tenant_type';
    public const fields_STATUS = 'status';
    public const fields_PLAN_TYPE = 'plan_type';
    public const fields_RESOURCE_QUOTA = 'resource_quota';
    public const fields_BILLING_INFO = 'billing_info';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

    // 租户类型常量
    public const TYPE_ENTERPRISE = 'enterprise';
    public const TYPE_INDIVIDUAL = 'individual';
    public const TYPE_DEVELOPER = 'developer';

    // 租户状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_EXPIRED = 'expired';

    // 计划类型常量
    public const PLAN_FREE = 'free';
    public const PLAN_BASIC = 'basic';
    public const PLAN_PROFESSIONAL = 'professional';
    public const PLAN_ENTERPRISE = 'enterprise';

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
                ->addColumn(self::fields_TENANT_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '租户名称')
                ->addColumn(self::fields_TENANT_CODE, TableInterface::column_type_VARCHAR, 100, 'not null unique', '租户代码')
                ->addColumn(self::fields_TENANT_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null default "individual"', '租户类型')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 50, 'not null default "active"', '租户状态')
                ->addColumn(self::fields_PLAN_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null default "free"', '订阅计划类型')
                ->addColumn(self::fields_RESOURCE_QUOTA, TableInterface::column_type_TEXT, null, 'null', '资源配额配置JSON')
                ->addColumn(self::fields_BILLING_INFO, TableInterface::column_type_TEXT, null, 'null', '计费信息JSON')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_tenant_code', self::fields_TENANT_CODE, '租户代码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_tenant_type', self::fields_TENANT_TYPE, '租户类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_plan_type', self::fields_PLAN_TYPE, '计划类型索引')
                ->create();
        }
    }

    /**
     * 获取租户名称
     * 
     * @return string
     */
    public function getTenantName(): string
    {
        return $this->getData(self::fields_TENANT_NAME) ?? '';
    }

    /**
     * 获取租户代码
     * 
     * @return string
     */
    public function getTenantCode(): string
    {
        return $this->getData(self::fields_TENANT_CODE) ?? '';
    }

    /**
     * 获取租户类型
     * 
     * @return string
     */
    public function getTenantType(): string
    {
        return $this->getData(self::fields_TENANT_TYPE) ?? self::TYPE_INDIVIDUAL;
    }

    /**
     * 获取租户状态
     * 
     * @return string
     */
    public function getStatus(): string
    {
        return $this->getData(self::fields_STATUS) ?? self::STATUS_ACTIVE;
    }

    /**
     * 获取计划类型
     * 
     * @return string
     */
    public function getPlanType(): string
    {
        return $this->getData(self::fields_PLAN_TYPE) ?? self::PLAN_FREE;
    }

    /**
     * 获取资源配额配置
     * 
     * @return array
     */
    public function getResourceQuota(): array
    {
        $quota = $this->getData(self::fields_RESOURCE_QUOTA);
        return $quota ? json_decode($quota, true) : [];
    }

    /**
     * 设置资源配额配置
     * 
     * @param array $quota
     * @return $this
     */
    public function setResourceQuota(array $quota): self
    {
        $this->setData(self::fields_RESOURCE_QUOTA, json_encode($quota));
        return $this;
    }

    /**
     * 获取计费信息
     * 
     * @return array
     */
    public function getBillingInfo(): array
    {
        $billing = $this->getData(self::fields_BILLING_INFO);
        return $billing ? json_decode($billing, true) : [];
    }

    /**
     * 设置计费信息
     * 
     * @param array $billing
     * @return $this
     */
    public function setBillingInfo(array $billing): self
    {
        $this->setData(self::fields_BILLING_INFO, json_encode($billing));
        return $this;
    }

    /**
     * 检查租户是否激活
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->getStatus() === self::STATUS_ACTIVE;
    }

    /**
     * 检查租户是否暂停
     * 
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this->getStatus() === self::STATUS_SUSPENDED;
    }

    /**
     * 检查租户是否过期
     * 
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->getStatus() === self::STATUS_EXPIRED;
    }

    /**
     * 检查是否为付费计划
     * 
     * @return bool
     */
    public function isPaidPlan(): bool
    {
        return !in_array($this->getPlanType(), [self::PLAN_FREE]);
    }

    /**
     * 获取租户显示名称
     * 
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->getTenantName() ?: $this->getTenantCode();
    }

    /**
     * 获取租户类型显示名称
     * 
     * @return string
     */
    public function getTenantTypeDisplayName(): string
    {
        $typeNames = [
            self::TYPE_ENTERPRISE => '企业',
            self::TYPE_INDIVIDUAL => '个人',
            self::TYPE_DEVELOPER => '开发者'
        ];

        return $typeNames[$this->getTenantType()] ?? $this->getTenantType();
    }

    /**
     * 获取状态显示名称
     * 
     * @return string
     */
    public function getStatusDisplayName(): string
    {
        $statusNames = [
            self::STATUS_ACTIVE => '激活',
            self::STATUS_SUSPENDED => '暂停',
            self::STATUS_EXPIRED => '过期'
        ];

        return $statusNames[$this->getStatus()] ?? $this->getStatus();
    }

    /**
     * 获取计划类型显示名称
     * 
     * @return string
     */
    public function getPlanTypeDisplayName(): string
    {
        $planNames = [
            self::PLAN_FREE => '免费版',
            self::PLAN_BASIC => '基础版',
            self::PLAN_PROFESSIONAL => '专业版',
            self::PLAN_ENTERPRISE => '企业版'
        ];

        return $planNames[$this->getPlanType()] ?? $this->getPlanType();
    }

    /**
     * 检查资源配额
     * 
     * @param string $resourceType 资源类型
     * @param int $requestedAmount 请求数量
     * @return bool
     */
    public function checkResourceQuota(string $resourceType, int $requestedAmount = 1): bool
    {
        $quota = $this->getResourceQuota();
        
        if (!isset($quota[$resourceType])) {
            return true; // 没有限制
        }

        $limit = $quota[$resourceType]['limit'] ?? 0;
        $used = $quota[$resourceType]['used'] ?? 0;

        return ($used + $requestedAmount) <= $limit;
    }

    /**
     * 使用资源配额
     * 
     * @param string $resourceType 资源类型
     * @param int $amount 使用数量
     * @return bool
     */
    public function useResourceQuota(string $resourceType, int $amount = 1): bool
    {
        if (!$this->checkResourceQuota($resourceType, $amount)) {
            return false;
        }

        $quota = $this->getResourceQuota();
        
        if (!isset($quota[$resourceType])) {
            $quota[$resourceType] = ['limit' => 0, 'used' => 0];
        }

        $quota[$resourceType]['used'] = ($quota[$resourceType]['used'] ?? 0) + $amount;
        $this->setResourceQuota($quota);
        
        return true;
    }

    /**
     * 重置资源配额使用量
     * 
     * @param string $resourceType 资源类型
     * @return $this
     */
    public function resetResourceUsage(string $resourceType): self
    {
        $quota = $this->getResourceQuota();
        
        if (isset($quota[$resourceType])) {
            $quota[$resourceType]['used'] = 0;
            $this->setResourceQuota($quota);
        }
        
        return $this;
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
        }
        $this->setData(self::fields_UPDATED_TIME, $currentTime);
        
        return $this;
    }
}
