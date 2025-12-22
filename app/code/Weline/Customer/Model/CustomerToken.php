<?php

declare(strict_types=1);

namespace Weline\Customer\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 前端用户Token模型
 * 用于"记住我"功能
 */
class CustomerToken extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'token_id';
    public const fields_user_id = 'user_id';
    public const fields_token = 'token';
    public const fields_type = 'type';
    public const fields_token_expire_time = 'token_expire_time';
    public const fields_created_at = 'created_at';
    public const fields_last_used_at = 'last_used_at';

    /**
     * 初始化
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装数据表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('前端用户Token表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', 'Token ID')
                ->addColumn(self::fields_user_id, TableInterface::column_type_INTEGER, 0, 'not null', '用户ID')
                ->addColumn(self::fields_token, TableInterface::column_type_VARCHAR, 64, 'not null unique', 'Token字符串')
                ->addColumn(self::fields_type, TableInterface::column_type_VARCHAR, 32, 'not null', 'Token类型')
                ->addColumn(self::fields_token_expire_time, TableInterface::column_type_INTEGER, 0, 'not null', '过期时间戳')
                ->addColumn(self::fields_created_at, TableInterface::column_type_TIMESTAMP, 0, 'default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_last_used_at, TableInterface::column_type_TIMESTAMP, 0, 'null', '最后使用时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_user_id, '用户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_token', self::fields_token, 'Token索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_expire', self::fields_token_expire_time, '过期时间索引')
                ->create();
        }
    }

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
    }

    /**
     * 获取用户ID
     */
    public function getUserId(): int
    {
        return (int)$this->getData(self::fields_user_id);
    }

    /**
     * 设置用户ID
     */
    public function setUserId(int $userId): static
    {
        return $this->setData(self::fields_user_id, $userId);
    }

    /**
     * 获取Token
     */
    public function getToken(): string
    {
        return (string)$this->getData(self::fields_token);
    }

    /**
     * 设置Token
     */
    public function setToken(string $token): static
    {
        return $this->setData(self::fields_token, $token);
    }

    /**
     * 获取Token类型
     */
    public function getType(): string
    {
        return (string)$this->getData(self::fields_type);
    }

    /**
     * 设置Token类型
     */
    public function setType(string $type): static
    {
        return $this->setData(self::fields_type, $type);
    }

    /**
     * 获取过期时间
     */
    public function getTokenExpireTime(): int
    {
        return (int)$this->getData(self::fields_token_expire_time);
    }

    /**
     * 设置过期时间
     */
    public function setTokenExpireTime(int $time): static
    {
        return $this->setData(self::fields_token_expire_time, $time);
    }

    /**
     * 更新最后使用时间
     */
    public function updateLastUsedAt(): static
    {
        return $this->setData(self::fields_last_used_at, date('Y-m-d H:i:s'));
    }

    /**
     * 检查Token是否过期
     */
    public function isExpired(): bool
    {
        return time() >= $this->getTokenExpireTime();
    }

    /**
     * 生成随机Token
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 清理过期的Token
     */
    public function cleanExpiredTokens(): int
    {
        return $this->builder()
            ->where(self::fields_token_expire_time, time(), '<')
            ->delete();
    }
}
