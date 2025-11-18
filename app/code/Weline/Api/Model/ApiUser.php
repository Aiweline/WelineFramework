<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Model;

use Weline\Acl\Model\Role;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class ApiUser extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'user_id';
    public const fields_username = 'username';
    public const fields_email = 'email';
    public const fields_password = 'password';
    public const fields_api_key = 'api_key';
    public const fields_api_secret = 'api_secret';
    public const fields_token_expire_time = 'token_expire_time';
    public const fields_refresh_token_expire_time = 'refresh_token_expire_time';
    public const fields_is_enabled = 'is_enabled';
    public const fields_is_deleted = 'is_deleted';
    public const fields_ip_whitelist_enabled = 'ip_whitelist_enabled';
    public const fields_allowed_ips = 'allowed_ips';
    public const fields_user_agent_restriction_enabled = 'user_agent_restriction_enabled';
    public const fields_allowed_user_agents = 'allowed_user_agents';
    public const fields_is_sandbox = 'is_sandbox';

    public string $table = 'm_api_user';

    public array $_unit_primary_keys = ['user_id'];
    public array $_index_sort_keys = ['user_id', 'username', 'email', 'api_key'];

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
        if (!$setup->hasField(self::fields_is_sandbox)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_is_sandbox,
                    self::fields_is_deleted,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '是否沙盒账户'
                )
                ->alter();
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // 表结构已在 Setup/Install.php 中创建
        // 这里可以添加初始数据
    }

    /**
     * 获取用户ID
     */
    public function getId(mixed $default = 0): int
    {
        return (int)parent::getId($default);
    }

    /**
     * 获取用户名
     */
    public function getUsername(): string
    {
        return (string)($this->getData(self::fields_username) ?? '');
    }

    /**
     * 设置用户名
     */
    public function setUsername(string $username): self
    {
        return $this->setData(self::fields_username, $username);
    }

    /**
     * 获取邮箱
     */
    public function getEmail(): string
    {
        return (string)($this->getData(self::fields_email) ?? '');
    }

    /**
     * 设置邮箱
     */
    public function setEmail(string $email): self
    {
        return $this->setData(self::fields_email, $email);
    }

    /**
     * 获取密码（加密后的）
     */
    public function getPassword(): string
    {
        return (string)($this->getData(self::fields_password) ?? '');
    }

    /**
     * 设置密码（自动加密）
     */
    public function setPassword(string $password): self
    {
        return $this->setData(self::fields_password, password_hash($password, PASSWORD_DEFAULT));
    }

    /**
     * 验证密码
     */
    public function verifyPassword(string $password): bool
    {
        $hashedPassword = $this->getPassword();
        if (empty($hashedPassword)) {
            return false;
        }
        return password_verify($password, $hashedPassword);
    }

    /**
     * 获取API Key
     */
    public function getApiKey(): string
    {
        return (string)($this->getData(self::fields_api_key) ?? '');
    }

    /**
     * 设置API Key
     */
    public function setApiKey(string $apiKey): self
    {
        return $this->setData(self::fields_api_key, $apiKey);
    }

    /**
     * 获取API Secret（加密后的）
     */
    public function getApiSecret(): string
    {
        return (string)($this->getData(self::fields_api_secret) ?? '');
    }

    /**
     * 设置API Secret（自动加密）
     */
    public function setApiSecret(string $apiSecret): self
    {
        return $this->setData(self::fields_api_secret, password_hash($apiSecret, PASSWORD_DEFAULT));
    }

    /**
     * 验证API Secret
     */
    public function verifySecret(string $apiSecret): bool
    {
        $hashedSecret = $this->getApiSecret();
        if (empty($hashedSecret)) {
            return false;
        }
        return password_verify($apiSecret, $hashedSecret);
    }

    /**
     * 生成API Key和Secret
     * 
     * @return array ['api_key' => string, 'api_secret' => string]
     */
    public function generateApiCredentials(): array
    {
        // 生成API Key（32字节，64字符）
        $apiKey = 'ak_' . bin2hex(random_bytes(32));
        
        // 生成API Secret（32字节，64字符）
        $apiSecret = 'as_' . bin2hex(random_bytes(32));
        
        return [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret
        ];
    }

    /**
     * 自动生成并设置API Key和Secret
     */
    public function autoGenerateApiCredentials(): self
    {
        $credentials = $this->generateApiCredentials();
        $this->setApiKey($credentials['api_key']);
        $this->setApiSecret($credentials['api_secret']);
        // 临时保存原始Secret，用于返回给用户（仅创建时显示一次）
        $this->setData('raw_api_secret', $credentials['api_secret']);
        return $this;
    }

    /**
     * 获取访问令牌有效期（秒）
     */
    public function getTokenExpireTime(): int
    {
        return (int)($this->getData(self::fields_token_expire_time) ?? 604800); // 默认7天
    }

    /**
     * 设置访问令牌有效期（秒）
     */
    public function setTokenExpireTime(int $seconds): self
    {
        // 限制在1-30天之间
        $minSeconds = 86400; // 1天
        $maxSeconds = 2592000; // 30天
        $seconds = max($minSeconds, min($maxSeconds, $seconds));
        return $this->setData(self::fields_token_expire_time, $seconds);
    }

    /**
     * 获取刷新令牌有效期（秒）
     */
    public function getRefreshTokenExpireTime(): int
    {
        return (int)($this->getData(self::fields_refresh_token_expire_time) ?? 2592000); // 默认30天
    }

    /**
     * 设置刷新令牌有效期（秒）
     */
    public function setRefreshTokenExpireTime(int $seconds): self
    {
        // 限制在7-90天之间
        $minSeconds = 604800; // 7天
        $maxSeconds = 7776000; // 90天
        $seconds = max($minSeconds, min($maxSeconds, $seconds));
        return $this->setData(self::fields_refresh_token_expire_time, $seconds);
    }

    /**
     * 是否启用
     */
    public function getIsEnabled(): bool
    {
        return (bool)($this->getData(self::fields_is_enabled) ?? true);
    }

    /**
     * 设置是否启用
     */
    public function setIsEnabled(bool $enabled): self
    {
        return $this->setData(self::fields_is_enabled, (int)$enabled);
    }

    /**
     * 是否沙盒账户
     */
    public function isSandboxAccount(): bool
    {
        return (bool)($this->getData(self::fields_is_sandbox) ?? false);
    }

    /**
     * 设置沙盒账户
     */
    public function setSandboxAccount(bool $sandbox): self
    {
        return $this->setData(self::fields_is_sandbox, (int)$sandbox);
    }

    /**
     * 是否删除
     */
    public function getIsDeleted(): bool
    {
        return (bool)($this->getData(self::fields_is_deleted) ?? false);
    }

    /**
     * 设置是否删除
     */
    public function setIsDeleted(bool $deleted): self
    {
        return $this->setData(self::fields_is_deleted, (int)$deleted);
    }

    /**
     * 是否启用IP白名单
     */
    public function isIpWhitelistEnabled(): bool
    {
        return (bool)($this->getData(self::fields_ip_whitelist_enabled) ?? false);
    }

    /**
     * 设置是否启用IP白名单
     */
    public function setIpWhitelistEnabled(bool $enabled): self
    {
        return $this->setData(self::fields_ip_whitelist_enabled, (int)$enabled);
    }

    /**
     * 获取允许的IP地址列表
     * 
     * @return array
     */
    public function getAllowedIps(): array
    {
        $ips = $this->getData(self::fields_allowed_ips);
        if (empty($ips)) {
            return [];
        }
        
        // 尝试解析JSON
        $decoded = json_decode($ips ?? '', true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        
        // 如果不是JSON，按换行符分割
        return array_filter(array_map('trim', explode("\n", $ips)));
    }

    /**
     * 设置允许的IP地址列表
     * 
     * @param array|string $ips IP地址列表（数组或换行分隔的字符串）
     */
    public function setAllowedIps(array|string $ips): self
    {
        if (is_array($ips)) {
            $ips = json_encode($ips, JSON_UNESCAPED_UNICODE);
        }
        return $this->setData(self::fields_allowed_ips, $ips);
    }

    /**
     * 是否启用用户代理限制
     */
    public function isUserAgentRestrictionEnabled(): bool
    {
        return (bool)($this->getData(self::fields_user_agent_restriction_enabled) ?? false);
    }

    /**
     * 设置是否启用用户代理限制
     */
    public function setUserAgentRestrictionEnabled(bool $enabled): self
    {
        return $this->setData(self::fields_user_agent_restriction_enabled, (int)$enabled);
    }

    /**
     * 获取允许的用户代理列表
     * 
     * @return array
     */
    public function getAllowedUserAgents(): array
    {
        $userAgents = $this->getData(self::fields_allowed_user_agents);
        if (empty($userAgents)) {
            return [];
        }
        
        // 尝试解析JSON
        $decoded = json_decode($userAgents ?? '', true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        
        // 如果不是JSON，按换行符分割
        return array_filter(array_map('trim', explode("\n", $userAgents)));
    }

    /**
     * 设置允许的用户代理列表
     * 
     * @param array|string $userAgents 用户代理列表（数组或换行分隔的字符串）
     */
    public function setAllowedUserAgents(array|string $userAgents): self
    {
        if (is_array($userAgents)) {
            $userAgents = json_encode($userAgents, JSON_UNESCAPED_UNICODE);
        }
        return $this->setData(self::fields_allowed_user_agents, $userAgents);
    }

    /**
     * 获取角色模型
     * 
     * @return Role|null
     */
    public function getRoleModel(): ?Role
    {
        if ($role = $this->getData('role')) {
            return $role;
        }
        
        /** @var ApiUserRole $userRole */
        $userRole = ObjectManager::getInstance(ApiUserRole::class);
        $userRole->where('user_id', $this->getId())->find()->fetch();
        
        if (!$userRole->getId()) {
            return null;
        }
        
        /** @var Role $role */
        $role = ObjectManager::getInstance(Role::class);
        $role = $role->load($userRole->getRoleId());
        
        if ($role->getId()) {
            $this->setData('role', $role);
        }
        
        return $role->getId() ? $role : null;
    }

    /**
     * 分配角色
     * 
     * @param int $roleId 角色ID
     */
    public function assignRole(int $roleId): self
    {
        /** @var ApiUserRole $userRole */
        $userRole = ObjectManager::getInstance(ApiUserRole::class);
        $userRole->where('user_id', $this->getId())
            ->where('role_id', $roleId)
            ->find()
            ->fetch();
        
        if (!$userRole->getId()) {
            $userRole->clear()
                ->setUserId($this->getId())
                ->setRoleId($roleId)
                ->save();
        }
        
        return $this;
    }

    /**
     * 移除角色
     * 
     * @param int $roleId 角色ID
     */
    public function removeRole(int $roleId): self
    {
        /** @var ApiUserRole $userRole */
        $userRole = ObjectManager::getInstance(ApiUserRole::class);
        $userRole->where('user_id', $this->getId())
            ->where('role_id', $roleId)
            ->delete();
        
        return $this;
    }

    /**
     * 保存前自动生成API Key和Secret（如果不存在）
     */
    public function save_before()
    {
        // 如果是新用户且没有API Key，自动生成
        if (!$this->getId() && empty($this->getApiKey())) {
            $this->autoGenerateApiCredentials();
        }
        
        // 设置更新时间
        $this->setData('updated_at', date('Y-m-d H:i:s'));
        
        // 如果是新用户，设置创建时间
        if (!$this->getId()) {
            $this->setData('created_at', date('Y-m-d H:i:s'));
        }
        
        parent::save_before();
    }
}

