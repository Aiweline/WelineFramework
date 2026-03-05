<?php

declare(strict_types=1);

namespace Weline\Customer\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 前端用户Token模型 - 用于"记住我"功能
 */
#[Table(comment: '前端用户Token表')]
#[Index(name: 'idx_user_id', columns: ['user_id'])]
#[Index(name: 'idx_token', columns: ['token'])]
#[Index(name: 'idx_expire', columns: ['token_expire_time'])]
class CustomerToken extends Model
{

    public const schema_primary_key = 'token_id';
    public const schema_primary_keys = ['token_id'];
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Token ID')]
    public const schema_fields_ID = 'token_id';
    #[Col('int', nullable: false, comment: '用户ID')]
    public const schema_fields_user_id = 'user_id';
    #[Col('varchar', 64, nullable: false, comment: 'Token字符串')]
    public const schema_fields_token = 'token';
    #[Col('varchar', 32, nullable: false, comment: 'Token类型')]
    public const schema_fields_type = 'type';
    #[Col('int', nullable: false, comment: '过期时间戳')]
    public const schema_fields_token_expire_time = 'token_expire_time';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col('datetime', comment: '最后使用时间')]
    public const schema_fields_last_used_at = 'last_used_at';

    public function _init(): void
    {
    }

/**
     * 获取用户ID
     */
    public function getUserId(): int
    {
        return (int)$this->getData(self::schema_fields_user_id);
    }

    /**
     * 设置用户ID
     */
    public function setUserId(int $userId): static
    {
        return $this->setData(self::schema_fields_user_id, $userId);
    }

    /**
     * 获取Token
     */
    public function getToken(): string
    {
        return (string)$this->getData(self::schema_fields_token);
    }

    /**
     * 设置Token
     */
    public function setToken(string $token): static
    {
        return $this->setData(self::schema_fields_token, $token);
    }

    /**
     * 获取Token类型
     */
    public function getType(): string
    {
        return (string)$this->getData(self::schema_fields_type);
    }

    /**
     * 设置Token类型
     */
    public function setType(string $type): static
    {
        return $this->setData(self::schema_fields_type, $type);
    }

    /**
     * 获取过期时间
     */
    public function getTokenExpireTime(): int
    {
        return (int)$this->getData(self::schema_fields_token_expire_time);
    }

    /**
     * 设置过期时间
     */
    public function setTokenExpireTime(int $time): static
    {
        return $this->setData(self::schema_fields_token_expire_time, $time);
    }

    /**
     * 更新最后使用时间
     */
    public function updateLastUsedAt(): static
    {
        return $this->setData(self::schema_fields_last_used_at, date('Y-m-d H:i:s'));
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
            ->where(self::schema_fields_token_expire_time, time(), '<')
            ->delete();
    }
}

