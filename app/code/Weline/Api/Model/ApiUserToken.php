<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'API用户令牌表')]
#[Index(name: 'idx_w_api_user_token_token', columns: ['token'], type: 'UNIQUE', comment: '令牌唯一')]
#[Index(name: 'idx_w_api_user_token_user_id', columns: ['user_id'], comment: '用户ID')]
#[Index(name: 'idx_w_api_user_token_type', columns: ['type'], comment: '令牌类型')]
#[Index(name: 'idx_w_api_user_token_expire_time', columns: ['token_expire_time'], comment: '过期时间')]
class ApiUserToken extends Model
{

    public const schema_table = 'm_api_user_token';
    public const schema_primary_key = 'id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'int', nullable: false, comment: '用户ID')]
    public const schema_fields_user_id = 'user_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '令牌值')]
    public const schema_fields_token = 'token';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '令牌类型（access_token/refresh_token/pass_token）')]
    public const schema_fields_type = 'type';
    #[Col(type: 'int', nullable: true, comment: '过期时间（Unix时间戳）')]
    public const schema_fields_token_expire_time = 'token_expire_time';

    public const TYPE_ACCESS_TOKEN = 'access_token';
    public const TYPE_REFRESH_TOKEN = 'refresh_token';
    public const TYPE_PASS_TOKEN = 'pass_token';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['user_id', 'token', 'type'];

    /**
     * 获取ID
     */
    public function getId(mixed $default = 0): int
    {
        return (int)parent::getId($default);
    }

    /**
     * 获取用户ID
     */
    public function getUserId(): int
    {
        return (int)($this->getData(self::schema_fields_user_id) ?? 0);
    }

    /**
     * 设置用户ID
     */
    public function setUserId(int $userId): self
    {
        return $this->setData(self::schema_fields_user_id, $userId);
    }

    /**
     * 获取令牌
     */
    public function getToken(): string
    {
        return (string)($this->getData(self::schema_fields_token) ?? '');
    }

    /**
     * 设置令牌
     */
    public function setToken(string $token): self
    {
        return $this->setData(self::schema_fields_token, $token);
    }

    /**
     * 获取令牌类型
     */
    public function getType(): string
    {
        return (string)($this->getData(self::schema_fields_type) ?? '');
    }

    /**
     * 设置令牌类型
     */
    public function setType(string $type): self
    {
        return $this->setData(self::schema_fields_type, $type);
    }

    /**
     * 获取过期时间（Unix时间戳）
     */
    public function getTokenExpireTime(): int
    {
        return (int)($this->getData(self::schema_fields_token_expire_time) ?? 0);
    }

    /**
     * 设置过期时间（Unix时间戳）
     */
    public function setTokenExpireTime(int $timestamp): self
    {
        return $this->setData(self::schema_fields_token_expire_time, $timestamp);
    }

    /**
     * 检查令牌是否过期
     */
    public function isExpired(): bool
    {
        $expireTime = $this->getTokenExpireTime();
        if ($expireTime <= 0) {
            return false; // 永不过期
        }
        return time() > $expireTime;
    }

    /**
     * 保存前设置创建时间
     */
    public function save_before()
    {
        if (!$this->getId()) {
            $this->setData('created_at', date('Y-m-d H:i:s'));
        }
        parent::save_before();
    }
}


