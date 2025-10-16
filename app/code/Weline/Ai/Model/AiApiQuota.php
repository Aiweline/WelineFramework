<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/11
 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * API配额管理模型
 * 
 * 功能：
 * - API调用配额管理
 * - 配额使用统计
 * - 配额预警和限制
 * - 支持按时间周期配额
 */
class AiApiQuota extends \Weline\Framework\Database\Model
{
    public const table = 'ai_api_quota';
    public const fields_ID = 'id';
    public const fields_API_KEY_ID = 'api_key_id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_USER_ID = 'user_id';
    public const fields_QUOTA_TYPE = 'quota_type'; // daily, monthly, yearly, custom
    public const fields_QUOTA_LIMIT = 'quota_limit';
    public const fields_QUOTA_USED = 'quota_used';
    public const fields_QUOTA_REMAINING = 'quota_remaining';
    public const fields_TOKEN_LIMIT = 'token_limit';
    public const fields_TOKEN_USED = 'token_used';
    public const fields_TOKEN_REMAINING = 'token_remaining';
    public const fields_COST_LIMIT = 'cost_limit';
    public const fields_COST_USED = 'cost_used';
    public const fields_COST_REMAINING = 'cost_remaining';
    public const fields_RESET_AT = 'reset_at';
    public const fields_WARNING_THRESHOLD = 'warning_threshold'; // 警告阈值（百分比）
    public const fields_IS_EXCEEDED = 'is_exceeded';
    public const fields_EXCEEDED_AT = 'exceeded_at';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

    /**
     * 配额类型常量
     */
    public const QUOTA_TYPE_DAILY = 'daily';
    public const QUOTA_TYPE_MONTHLY = 'monthly';
    public const QUOTA_TYPE_YEARLY = 'yearly';
    public const QUOTA_TYPE_CUSTOM = 'custom';

    /**
     * 设置模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: 实现升级逻辑
    }

    /**
     * 安装数据表
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '主键ID')
                ->addColumn(self::fields_API_KEY_ID, TableInterface::column_type_INTEGER, null, 'null', 'API Key ID')
                ->addColumn(self::fields_TENANT_ID, TableInterface::column_type_INTEGER, null, 'null', '租户ID')
                ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, null, 'null', '用户ID')
                ->addColumn(self::fields_QUOTA_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '配额类型')
                ->addColumn(self::fields_QUOTA_LIMIT, TableInterface::column_type_INTEGER, null, 'default 0', '配额限制')
                ->addColumn(self::fields_QUOTA_USED, TableInterface::column_type_INTEGER, null, 'default 0', '已使用配额')
                ->addColumn(self::fields_QUOTA_REMAINING, TableInterface::column_type_INTEGER, null, 'default 0', '剩余配额')
                ->addColumn(self::fields_TOKEN_LIMIT, TableInterface::column_type_BIGINT, null, 'default 0', 'Token限制')
                ->addColumn(self::fields_TOKEN_USED, TableInterface::column_type_BIGINT, null, 'default 0', '已使用Token')
                ->addColumn(self::fields_TOKEN_REMAINING, TableInterface::column_type_BIGINT, null, 'default 0', '剩余Token')
                ->addColumn(self::fields_COST_LIMIT, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '成本限制')
                ->addColumn(self::fields_COST_USED, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '已使用成本')
                ->addColumn(self::fields_COST_REMAINING, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '剩余成本')
                ->addColumn(self::fields_RESET_AT, TableInterface::column_type_INTEGER, null, 'not null', '重置时间')
                ->addColumn(self::fields_WARNING_THRESHOLD, TableInterface::column_type_SMALLINT, null, 'default 80', '警告阈值')
                ->addColumn(self::fields_IS_EXCEEDED, TableInterface::column_type_SMALLINT, 1, 'default 0', '是否超额')
                ->addColumn(self::fields_EXCEEDED_AT, TableInterface::column_type_INTEGER, null, 'null', '超额时间')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_api_key_id', self::fields_API_KEY_ID, 'API Key索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_tenant_id', self::fields_TENANT_ID, '租户索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_USER_ID, '用户索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_quota_type', self::fields_QUOTA_TYPE, '配额类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_reset_at', self::fields_RESET_AT, '重置时间索引')
                ->create();
        }
    }

    /**
     * 使用配额
     * 
     * @param int $requests
     * @param int $tokens
     * @param float $cost
     * @return bool
     */
    public function use(int $requests = 1, int $tokens = 0, float $cost = 0.0): bool
    {
        // 检查是否需要重置
        if (time() >= (int)$this->getData(self::fields_RESET_AT)) {
            $this->reset();
        }

        // 检查是否超额
        if ($this->isExceeded()) {
            return false;
        }

        // 更新使用量
        $this->setData(self::fields_QUOTA_USED, $this->getData(self::fields_QUOTA_USED) + $requests);
        $this->setData(self::fields_TOKEN_USED, $this->getData(self::fields_TOKEN_USED) + $tokens);
        $this->setData(self::fields_COST_USED, $this->getData(self::fields_COST_USED) + $cost);

        // 更新剩余量
        $this->updateRemaining();

        // 检查是否超额
        $this->checkExceeded();

        return true;
    }

    /**
     * 更新剩余配额
     * 
     * @return void
     */
    private function updateRemaining(): void
    {
        $quotaRemaining = max(0, (int)$this->getData(self::fields_QUOTA_LIMIT) - (int)$this->getData(self::fields_QUOTA_USED));
        $tokenRemaining = max(0, (int)$this->getData(self::fields_TOKEN_LIMIT) - (int)$this->getData(self::fields_TOKEN_USED));
        $costRemaining = max(0.0, (float)$this->getData(self::fields_COST_LIMIT) - (float)$this->getData(self::fields_COST_USED));

        $this->setData(self::fields_QUOTA_REMAINING, $quotaRemaining);
        $this->setData(self::fields_TOKEN_REMAINING, $tokenRemaining);
        $this->setData(self::fields_COST_REMAINING, $costRemaining);
    }

    /**
     * 检查是否超额
     * 
     * @return void
     */
    private function checkExceeded(): void
    {
        $quotaLimit = (int)$this->getData(self::fields_QUOTA_LIMIT);
        $tokenLimit = (int)$this->getData(self::fields_TOKEN_LIMIT);
        $costLimit = (float)$this->getData(self::fields_COST_LIMIT);

        $isExceeded = false;

        if ($quotaLimit > 0 && (int)$this->getData(self::fields_QUOTA_USED) >= $quotaLimit) {
            $isExceeded = true;
        }

        if ($tokenLimit > 0 && (int)$this->getData(self::fields_TOKEN_USED) >= $tokenLimit) {
            $isExceeded = true;
        }

        if ($costLimit > 0 && (float)$this->getData(self::fields_COST_USED) >= $costLimit) {
            $isExceeded = true;
        }

        if ($isExceeded && !$this->getData(self::fields_IS_EXCEEDED)) {
            $this->setData(self::fields_IS_EXCEEDED, 1);
            $this->setData(self::fields_EXCEEDED_AT, time());
        }
    }

    /**
     * 重置配额
     * 
     * @return $this
     */
    public function reset(): self
    {
        $this->setData(self::fields_QUOTA_USED, 0);
        $this->setData(self::fields_TOKEN_USED, 0);
        $this->setData(self::fields_COST_USED, 0);
        $this->setData(self::fields_IS_EXCEEDED, 0);
        $this->setData(self::fields_EXCEEDED_AT, null);

        $this->updateRemaining();
        $this->setNextResetTime();

        return $this;
    }

    /**
     * 设置下一次重置时间
     * 
     * @return void
     */
    private function setNextResetTime(): void
    {
        $quotaType = $this->getData(self::fields_QUOTA_TYPE);
        $now = time();

        switch ($quotaType) {
            case self::QUOTA_TYPE_DAILY:
                $resetAt = strtotime('tomorrow 00:00:00');
                break;
            case self::QUOTA_TYPE_MONTHLY:
                $resetAt = strtotime('first day of next month 00:00:00');
                break;
            case self::QUOTA_TYPE_YEARLY:
                $resetAt = strtotime('first day of January next year 00:00:00');
                break;
            default:
                // Custom: 添加默认周期（30天）
                $resetAt = $now + (30 * 24 * 3600);
                break;
        }

        $this->setData(self::fields_RESET_AT, $resetAt);
    }

    /**
     * 检查是否超额
     * 
     * @return bool
     */
    public function isExceeded(): bool
    {
        return (bool)$this->getData(self::fields_IS_EXCEEDED);
    }

    /**
     * 检查是否接近警告阈值
     * 
     * @return bool
     */
    public function isNearWarningThreshold(): bool
    {
        $threshold = (int)$this->getData(self::fields_WARNING_THRESHOLD);
        $quotaLimit = (int)$this->getData(self::fields_QUOTA_LIMIT);

        if ($quotaLimit <= 0) {
            return false;
        }

        $usagePercent = ((int)$this->getData(self::fields_QUOTA_USED) / $quotaLimit) * 100;

        return $usagePercent >= $threshold;
    }

    /**
     * 获取使用率
     * 
     * @return float
     */
    public function getUsagePercent(): float
    {
        $quotaLimit = (int)$this->getData(self::fields_QUOTA_LIMIT);

        if ($quotaLimit <= 0) {
            return 0.0;
        }

        return ((int)$this->getData(self::fields_QUOTA_USED) / $quotaLimit) * 100;
    }

    /**
     * 保存前处理
     * 
     * @return $this
     */
    public function beforeSave(): self
    {
        parent::beforeSave();
        
        $currentTime = time();
        
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, $currentTime);
            $this->setNextResetTime();
        }
        
        $this->setData(self::fields_UPDATED_TIME, $currentTime);
        
        return $this;
    }
}

