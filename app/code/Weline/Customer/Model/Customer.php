<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Customer\Model;

use Weline\Backend\Model\Config;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\View\Template;

#[Table(comment: '用户表')]
class Customer extends Model implements AuthenticableInterface
{
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '用户ID')]
    public const schema_fields_ID            = 'user_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '用户名')]
    public const schema_fields_username      = 'username';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '密码')]
    public const schema_fields_password      = 'password';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '头像')]
    public const schema_fields_avatar        = 'avatar';
    #[Col(type: 'varchar', length: 45, nullable: true, comment: '登录IP')]
    public const schema_fields_login_ip      = 'login_ip';
    #[Col(type: 'varchar', length: 45, nullable: true, comment: '尝试IP')]
    public const schema_fields_attempt_ip    = 'attempt_ip';
    #[Col(type: 'int', nullable: false, default: 0, comment: '尝试登录次数')]
    public const schema_fields_attempt_times = 'attempt_times';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: '会话ID')]
    public const schema_fields_sess_id       = 'sess_id';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否沙箱')]
    public const schema_fields_is_sandbox    = 'is_sandbox';

    public const schema_primary_key = 'user_id';
    public const schema_primary_keys = ['user_id'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
    }

    public function getAttemptTimes()
    {
        return intval($this->getData(self::schema_fields_attempt_times));
    }

    public function addAttemptTimes(): static
    {
        $this->setData(self::schema_fields_attempt_times, intval($this->getData(self::schema_fields_attempt_times)) + 1);
        return $this;
    }

    public function getAttemptIp()
    {
        return $this->getData(self::schema_fields_attempt_ip);
    }

    public function setAttemptIp($ip)
    {
        return $this->setData(self::schema_fields_attempt_ip, $ip);
    }

    public function resetAttemptTimes(): static
    {
        $this->setData(self::schema_fields_attempt_times, 0);
        $this->save();
        return $this;
    }

    public function getUsername()
    {
        return $this->getData('username');
    }

    public function setUsername(string $username)
    {
        return $this->setData('username', $username);
    }

    public function getAvatar()
    {
        return $this->getData('avatar');
    }

    public function setAvatar(string $avatar)
    {
        return $this->setData('avatar', $avatar);
    }

    public function getPassword()
    {
        return $this->getData('password');
    }

    public function setPassword(string $password)
    {
        return $this->setData('password', password_hash($password, PASSWORD_DEFAULT));
    }


    public function getSessionId()
    {
        return $this->getData(self::schema_fields_sess_id);
    }

    public function setSessionId(string $sess_id): static
    {
        return $this->setData(self::schema_fields_sess_id, $sess_id);
    }

    public function getLoginIp()
    {
        return $this->getData(self::schema_fields_login_ip);
    }

    public function setLoginIp(string $ip): static
    {
        return $this->setData(self::schema_fields_login_ip, $ip);
    }

    public function isSandboxAccount(): bool
    {
        return (bool)$this->getData(self::schema_fields_is_sandbox);
    }

    public function setSandboxAccount(bool $flag): static
    {
        return $this->setData(self::schema_fields_is_sandbox, $flag ? 1 : 0);
    }

    // ==================== AuthenticableInterface 实现 ====================

    /**
     * @inheritDoc
     */
    public function getAuthIdentifier(): int|string
    {
        return (int)$this->getId();
    }

    /**
     * @inheritDoc
     */
    public function getAuthUsername(): string
    {
        return (string)$this->getUsername();
    }

    /**
     * @inheritDoc
     */
    public function getAuthSessionId(): string
    {
        return (string)$this->getSessionId();
    }

    /**
     * @inheritDoc
     */
    public static function getAuthModelClass(): string
    {
        return self::class;
    }
}

