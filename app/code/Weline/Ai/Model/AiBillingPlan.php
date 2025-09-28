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
 * AI计费计划数据模型
 * 
 * 功能：
 * - 管理订阅计划
 * - 计划功能配置
 * - 价格和计费周期管理
 * - 计划限制配置
 */
class AiBillingPlan extends Model
{
    public const table = 'ai_billing_plan';
    
    // 字段常量
    public const fields_ID = 'id';
    public const fields_PLAN_NAME = 'plan_name';
    public const fields_PLAN_TYPE = 'plan_type';
    public const fields_PRICE = 'price';
    public const fields_CURRENCY = 'currency';
    public const fields_BILLING_CYCLE = 'billing_cycle';
    public const fields_FEATURES = 'features';
    public const fields_LIMITS = 'limits';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

    // 计划类型常量
    public const TYPE_FREE = 'free';
    public const TYPE_PAID = 'paid';
    public const TYPE_ENTERPRISE = 'enterprise';

    // 计费周期常量
    public const CYCLE_MONTHLY = 'monthly';
    public const CYCLE_QUARTERLY = 'quarterly';
    public const CYCLE_YEARLY = 'yearly';
    public const CYCLE_LIFETIME = 'lifetime';

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
                ->addColumn(self::fields_PLAN_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '计划名称')
                ->addColumn(self::fields_PLAN_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '计划类型')
                ->addColumn(self::fields_PRICE, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '价格')
                ->addColumn(self::fields_CURRENCY, TableInterface::column_type_VARCHAR, 3, 'not null default "USD"', '货币')
                ->addColumn(self::fields_BILLING_CYCLE, TableInterface::column_type_VARCHAR, 20, 'not null default "monthly"', '计费周期')
                ->addColumn(self::fields_FEATURES, TableInterface::column_type_TEXT, null, 'null', '功能列表JSON')
                ->addColumn(self::fields_LIMITS, TableInterface::column_type_TEXT, null, 'null', '限制配置JSON')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_INTEGER, 1, 'not null default 1', '是否激活')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_plan_type', self::fields_PLAN_TYPE, '计划类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '激活状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_billing_cycle', self::fields_BILLING_CYCLE, '计费周期索引')
                ->create();
        }
    }

    /**
     * 获取计划名称
     * 
     * @return string
     */
    public function getPlanName(): string
    {
        return $this->getData(self::fields_PLAN_NAME) ?? '';
    }

    /**
     * 获取计划类型
     * 
     * @return string
     */
    public function getPlanType(): string
    {
        return $this->getData(self::fields_PLAN_TYPE) ?? '';
    }

    /**
     * 获取价格
     * 
     * @return float
     */
    public function getPrice(): float
    {
        return (float)$this->getData(self::fields_PRICE);
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
     * 获取计费周期
     * 
     * @return string
     */
    public function getBillingCycle(): string
    {
        return $this->getData(self::fields_BILLING_CYCLE) ?? self::CYCLE_MONTHLY;
    }

    /**
     * 获取功能列表
     * 
     * @return array
     */
    public function getFeatures(): array
    {
        $features = $this->getData(self::fields_FEATURES);
        return $features ? json_decode($features, true) : [];
    }

    /**
     * 设置功能列表
     * 
     * @param array $features
     * @return $this
     */
    public function setFeatures(array $features): self
    {
        $this->setData(self::fields_FEATURES, json_encode($features));
        return $this;
    }

    /**
     * 获取限制配置
     * 
     * @return array
     */
    public function getLimits(): array
    {
        $limits = $this->getData(self::fields_LIMITS);
        return $limits ? json_decode($limits, true) : [];
    }

    /**
     * 设置限制配置
     * 
     * @param array $limits
     * @return $this
     */
    public function setLimits(array $limits): self
    {
        $this->setData(self::fields_LIMITS, json_encode($limits));
        return $this;
    }

    /**
     * 检查是否激活
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    /**
     * 检查是否为免费计划
     * 
     * @return bool
     */
    public function isFree(): bool
    {
        return $this->getPlanType() === self::TYPE_FREE;
    }

    /**
     * 检查是否为付费计划
     * 
     * @return bool
     */
    public function isPaid(): bool
    {
        return $this->getPlanType() === self::TYPE_PAID;
    }

    /**
     * 检查是否为企业计划
     * 
     * @return bool
     */
    public function isEnterprise(): bool
    {
        return $this->getPlanType() === self::TYPE_ENTERPRISE;
    }

    /**
     * 获取计划类型显示名称
     * 
     * @return string
     */
    public function getPlanTypeDisplayName(): string
    {
        $typeNames = [
            self::TYPE_FREE => '免费版',
            self::TYPE_PAID => '付费版',
            self::TYPE_ENTERPRISE => '企业版'
        ];

        return $typeNames[$this->getPlanType()] ?? $this->getPlanType();
    }

    /**
     * 获取计费周期显示名称
     * 
     * @return string
     */
    public function getBillingCycleDisplayName(): string
    {
        $cycleNames = [
            self::CYCLE_MONTHLY => '月付',
            self::CYCLE_QUARTERLY => '季付',
            self::CYCLE_YEARLY => '年付',
            self::CYCLE_LIFETIME => '终身'
        ];

        return $cycleNames[$this->getBillingCycle()] ?? $this->getBillingCycle();
    }

    /**
     * 获取格式化价格
     * 
     * @return string
     */
    public function getFormattedPrice(): string
    {
        $price = $this->getPrice();
        $currency = $this->getCurrency();
        
        if ($this->isFree()) {
            return '免费';
        }

        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CNY' => '¥',
            'JPY' => '¥'
        ];

        $symbol = $symbols[$currency] ?? $currency;
        return $symbol . number_format($price, 2);
    }

    /**
     * 检查功能是否可用
     * 
     * @param string $feature 功能名称
     * @return bool
     */
    public function hasFeature(string $feature): bool
    {
        $features = $this->getFeatures();
        return in_array($feature, $features);
    }

    /**
     * 获取功能限制
     * 
     * @param string $resource 资源类型
     * @return int
     */
    public function getResourceLimit(string $resource): int
    {
        $limits = $this->getLimits();
        return $limits[$resource]['limit'] ?? 0;
    }

    /**
     * 检查资源是否在限制内
     * 
     * @param string $resource 资源类型
     * @param int $used 已使用量
     * @return bool
     */
    public function isWithinLimit(string $resource, int $used): bool
    {
        $limit = $this->getResourceLimit($resource);
        
        // 如果限制为0，表示无限制
        if ($limit === 0) {
            return true;
        }

        return $used <= $limit;
    }

    /**
     * 获取计划比较数据
     * 
     * @return array
     */
    public function getComparisonData(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getPlanName(),
            'type' => $this->getPlanType(),
            'price' => $this->getFormattedPrice(),
            'cycle' => $this->getBillingCycleDisplayName(),
            'features' => $this->getFeatures(),
            'limits' => $this->getLimits(),
            'is_popular' => $this->isPaid() && $this->getBillingCycle() === self::CYCLE_MONTHLY,
            'is_enterprise' => $this->isEnterprise()
        ];
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
