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
 * AI API密钥数据模型
 * 
 * 功能：
 * - 管理API密钥的基本信息
 * - 支持密钥状态管理（申请、审核、冻结等）
 * - 提供配额管理功能
 * - 记录密钥使用统计
 */
class AiApiKey extends Model
{
    public const table = 'ai_api_key';
    
    // 字段常量
    public const fields_ID = 'id';
    public const fields_USER_ID = 'user_id';
    public const fields_NAME = 'name';
    public const fields_TOKEN = 'token';
    public const fields_STATUS = 'status';
    public const fields_QUOTA_LIMIT = 'quota_limit';
    public const fields_USED_QUOTA = 'used_quota';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_IS_FROZEN = 'is_frozen';
    public const fields_APPROVED_TIME = 'approved_time';
    public const fields_FROZEN_TIME = 'frozen_time';
    public const fields_FROZEN_REASON = 'frozen_reason';
    public const fields_LAST_USED = 'last_used';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_DELETED = 'deleted';

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
                ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, 11, 'not null', '用户ID')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '密钥名称')
                ->addColumn(self::fields_TOKEN, TableInterface::column_type_VARCHAR, 255, 'not null', '密钥令牌')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, 'not null default "pending"', '密钥状态')
                ->addColumn(self::fields_QUOTA_LIMIT, TableInterface::column_type_INTEGER, 11, 'not null default 0', '配额限制')
                ->addColumn(self::fields_USED_QUOTA, TableInterface::column_type_INTEGER, 11, 'not null default 0', '已使用配额')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_INTEGER, 1, 'not null default 1', '是否激活')
                ->addColumn(self::fields_IS_FROZEN, TableInterface::column_type_INTEGER, 1, 'not null default 0', '是否冻结')
                ->addColumn(self::fields_APPROVED_TIME, TableInterface::column_type_INTEGER, 11, 'null', '审核通过时间')
                ->addColumn(self::fields_FROZEN_TIME, TableInterface::column_type_INTEGER, 11, 'null', '冻结时间')
                ->addColumn(self::fields_FROZEN_REASON, TableInterface::column_type_TEXT, null, 'null', '冻结原因')
                ->addColumn(self::fields_LAST_USED, TableInterface::column_type_INTEGER, 11, 'null', '最后使用时间')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_token', self::fields_TOKEN, '令牌唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_USER_ID, '用户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '激活状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_frozen', self::fields_IS_FROZEN, '冻结状态索引')
                ->create();
        }
    }

    /**
     * 生成API令牌
     * 
     * @return string
     */
    public function generateToken(): string
    {
        $prefix = 'ai_';
        $randomString = bin2hex(random_bytes(16));
        $timestamp = time();
        return $prefix . $timestamp . '_' . $randomString;
    }

    /**
     * 检查是否为激活状态
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    /**
     * 检查是否被冻结
     * 
     * @return bool
     */
    public function isFrozen(): bool
    {
        return (bool)$this->getData(self::fields_IS_FROZEN);
    }

    /**
     * 检查是否已审核通过
     * 
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_APPROVED;
    }

    /**
     * 检查配额是否充足
     * 
     * @param int $requiredQuota
     * @return bool
     */
    public function hasEnoughQuota(int $requiredQuota = 1): bool
    {
        $quotaLimit = (int)$this->getData(self::fields_QUOTA_LIMIT);
        $usedQuota = (int)$this->getData(self::fields_USED_QUOTA);
        
        // 0表示无限制
        if ($quotaLimit === 0) {
            return true;
        }
        
        return ($usedQuota + $requiredQuota) <= $quotaLimit;
    }

    /**
     * 消费配额
     * 
     * @param int $quota
     * @return bool
     */
    public function consumeQuota(int $quota = 1): bool
    {
        if (!$this->hasEnoughQuota($quota)) {
            return false;
        }
        
        $usedQuota = (int)$this->getData(self::fields_USED_QUOTA);
        $this->setData(self::fields_USED_QUOTA, $usedQuota + $quota);
        $this->setData(self::fields_LAST_USED, time());
        
        return true;
    }

    /**
     * 冻结密钥
     * 
     * @param string $reason
     * @return $this
     */
    public function freeze(string $reason = ''): self
    {
        $this->setData(self::fields_IS_FROZEN, 1);
        $this->setData(self::fields_STATUS, self::STATUS_FROZEN);
        $this->setData(self::fields_FROZEN_TIME, time());
        $this->setData(self::fields_FROZEN_REASON, $reason);
        
        return $this;
    }

    /**
     * 解冻密钥
     * 
     * @return $this
     */
    public function unfreeze(): self
    {
        $this->setData(self::fields_IS_FROZEN, 0);
        $this->setData(self::fields_STATUS, self::STATUS_APPROVED);
        $this->setData(self::fields_FROZEN_TIME, null);
        $this->setData(self::fields_FROZEN_REASON, null);
        
        return $this;
    }

    /**
     * 审核通过
     * 
     * @return $this
     */
    public function approve(): self
    {
        $this->setData(self::fields_STATUS, self::STATUS_APPROVED);
        $this->setData(self::fields_APPROVED_TIME, time());
        
        return $this;
    }

    /**
     * 审核拒绝
     * 
     * @return $this
     */
    public function reject(): self
    {
        $this->setData(self::fields_STATUS, self::STATUS_REJECTED);
        $this->setData(self::fields_IS_ACTIVE, 0);
        
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
            // 如果没有设置令牌，自动生成
            if (!$this->getData(self::fields_TOKEN)) {
                $this->setData(self::fields_TOKEN, $this->generateToken());
            }
        }
        $this->setData(self::fields_UPDATED_TIME, $currentTime);
        
        return $this;
    }
}
