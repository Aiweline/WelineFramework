<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class ApiUserToken extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'id';
    public const fields_user_id = 'user_id';
    public const fields_token = 'token';
    public const fields_type = 'type';
    public const fields_token_expire_time = 'token_expire_time';

    public const TYPE_ACCESS_TOKEN = 'access_token';
    public const TYPE_REFRESH_TOKEN = 'refresh_token';
    public const TYPE_PASS_TOKEN = 'pass_token';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['user_id', 'token', 'type'];
    
    public string $table = 'm_api_user_token';

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
        // 数据库升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // 表结构已在 Setup/Install.php 中创建
    }

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
        return (int)($this->getData(self::fields_user_id) ?? 0);
    }

    /**
     * 设置用户ID
     */
    public function setUserId(int $userId): self
    {
        return $this->setData(self::fields_user_id, $userId);
    }

    /**
     * 获取令牌
     */
    public function getToken(): string
    {
        return (string)($this->getData(self::fields_token) ?? '');
    }

    /**
     * 设置令牌
     */
    public function setToken(string $token): self
    {
        return $this->setData(self::fields_token, $token);
    }

    /**
     * 获取令牌类型
     */
    public function getType(): string
    {
        return (string)($this->getData(self::fields_type) ?? '');
    }

    /**
     * 设置令牌类型
     */
    public function setType(string $type): self
    {
        return $this->setData(self::fields_type, $type);
    }

    /**
     * 获取过期时间（Unix时间戳）
     */
    public function getTokenExpireTime(): int
    {
        return (int)($this->getData(self::fields_token_expire_time) ?? 0);
    }

    /**
     * 设置过期时间（Unix时间戳）
     */
    public function setTokenExpireTime(int $timestamp): self
    {
        return $this->setData(self::fields_token_expire_time, $timestamp);
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
    public function beforeSave(): self
    {
        if (!$this->getId()) {
            $this->setData('created_at', date('Y-m-d H:i:s'));
        }
        return $this;
    }
}

