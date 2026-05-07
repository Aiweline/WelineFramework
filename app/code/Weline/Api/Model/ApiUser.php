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
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: 'API用户表')]
#[Index(name: 'idx_w_api_user_username', columns: ['username'], type: 'UNIQUE', comment: '用户名唯一')]
#[Index(name: 'idx_w_api_user_email', columns: ['email'], type: 'UNIQUE', comment: '邮箱唯一')]
#[Index(name: 'idx_w_api_user_api_key', columns: ['api_key'], type: 'UNIQUE', comment: 'API密钥唯一')]
#[Index(name: 'idx_w_api_user_is_enabled', columns: ['is_enabled'], comment: '启用状态')]
#[Index(name: 'idx_w_api_user_is_deleted', columns: ['is_deleted'], comment: '删除状态')]
#[Index(name: 'idx_w_api_user_is_sandbox', columns: ['is_sandbox'], comment: '沙盒状态')]
#[Index(name: 'idx_w_api_user_ip_whitelist_enabled', columns: ['ip_whitelist_enabled'], comment: 'IP白名单')]
#[Index(name: 'idx_w_api_user_user_agent_restriction_enabled', columns: ['user_agent_restriction_enabled'], comment: 'UA限制')]
class ApiUser extends Model
{
    public const DEFAULT_TOKEN_EXPIRE_TIME = 604800;
    public const DEFAULT_REFRESH_TOKEN_EXPIRE_TIME = 2592000;
    public const DEFAULT_IS_ENABLED = 1;
    public const DEFAULT_IS_DELETED = 0;
    public const DEFAULT_IP_WHITELIST_ENABLED = 0;
    public const DEFAULT_ALLOWED_IPS = '';
    public const DEFAULT_USER_AGENT_RESTRICTION_ENABLED = 0;
    public const DEFAULT_ALLOWED_USER_AGENTS = '';
    public const DEFAULT_IS_SANDBOX = 0;

    public const fields_ID = 'user_id';
    public const schema_table = 'm_api_user';
    public string $table = 'm_api_user';
    /** 主键列名，避免 Schema 解析时把父类 id 一并建表导致 PostgreSQL 报 multiple primary keys */
    public const schema_primary_key = 'user_id';

    #[Col(type: 'integer', length: 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '用户ID')]
    public const schema_fields_ID = 'user_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '用户名')]
    public const schema_fields_username = 'username';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '邮箱')]
    public const schema_fields_email = 'email';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '密码')]
    public const schema_fields_password = 'password';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'API密钥')]
    public const schema_fields_api_key = 'api_key';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'API Secret')]
    public const schema_fields_api_secret = 'api_secret';
    #[Col(type: 'integer', length: 11, nullable: false, default: self::DEFAULT_TOKEN_EXPIRE_TIME, comment: '访问令牌有效期')]
    public const schema_fields_token_expire_time = 'token_expire_time';
    #[Col(type: 'integer', length: 11, nullable: false, default: self::DEFAULT_REFRESH_TOKEN_EXPIRE_TIME, comment: '刷新令牌有效期')]
    public const schema_fields_refresh_token_expire_time = 'refresh_token_expire_time';
    #[Col(type: 'integer', length: 1, nullable: false, default: self::DEFAULT_IS_ENABLED, comment: '是否启用')]
    public const schema_fields_is_enabled = 'is_enabled';
    #[Col(type: 'integer', length: 1, nullable: false, default: self::DEFAULT_IS_DELETED, comment: '是否删除')]
    public const schema_fields_is_deleted = 'is_deleted';
    #[Col(type: 'integer', length: 1, nullable: false, default: self::DEFAULT_IP_WHITELIST_ENABLED, comment: '是否启用IP白名单')]
    public const schema_fields_ip_whitelist_enabled = 'ip_whitelist_enabled';
    #[Col(type: 'varchar', length: 255, nullable: false, default: self::DEFAULT_ALLOWED_IPS, comment: '允许的IP地址列表')]
    public const schema_fields_allowed_ips = 'allowed_ips';
    #[Col(type: 'integer', length: 1, nullable: false, default: self::DEFAULT_USER_AGENT_RESTRICTION_ENABLED, comment: '是否启用用户代理限制')]
    public const schema_fields_user_agent_restriction_enabled = 'user_agent_restriction_enabled';
    #[Col(type: 'varchar', length: 255, nullable: false, default: self::DEFAULT_ALLOWED_USER_AGENTS, comment: '允许的用户代理列表')]
    public const schema_fields_allowed_user_agents = 'allowed_user_agents';
    #[Col(type: 'integer', length: 1, nullable: false, default: self::DEFAULT_IS_SANDBOX, comment: '是否沙盒账户')]
    public const schema_fields_is_sandbox = 'is_sandbox';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public array $_unit_primary_keys = ['user_id'];
    public array $_index_sort_keys = ['user_id', 'username', 'email', 'api_key'];
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
        return (string)($this->getData(self::schema_fields_username) ?? '');
    }
    /**
     * 设置用户名
     */
    public function setUsername(string $username): self
    {
        return $this->setData(self::schema_fields_username, $username);
    }
    /**
     * 获取邮箱
     */
    public function getEmail(): string
    {
        return (string)($this->getData(self::schema_fields_email) ?? '');
    }
    /**
     * 设置邮箱
     */
    public function setEmail(string $email): self
    {
        return $this->setData(self::schema_fields_email, $email);
    }
    /**
     * 获取密码（加密后的）
     */
    public function getPassword(): string
    {
        return (string)($this->getData(self::schema_fields_password) ?? '');
    }
    /**
     * 设置密码（自动加密）
     */
    public function setPassword(string $password): self
    {
        return $this->setData(self::schema_fields_password, password_hash($password, PASSWORD_DEFAULT));
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
        return (string)($this->getData(self::schema_fields_api_key) ?? '');
    }
    /**
     * 设置API Key
     */
    public function setApiKey(string $apiKey): self
    {
        return $this->setData(self::schema_fields_api_key, $apiKey);
    }
    /**
     * 获取API Secret（加密后的）
     */
    public function getApiSecret(): string
    {
        return (string)($this->getData(self::schema_fields_api_secret) ?? '');
    }
    /**
     * 设置API Secret（自动加密）
     */
    public function setApiSecret(string $apiSecret): self
    {
        return $this->setData(self::schema_fields_api_secret, password_hash($apiSecret, PASSWORD_DEFAULT));
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
        return (int)($this->getData(self::schema_fields_token_expire_time) ?? self::DEFAULT_TOKEN_EXPIRE_TIME); // 默认7天
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
        return $this->setData(self::schema_fields_token_expire_time, $seconds);
    }
    /**
     * 获取刷新令牌有效期（秒）
     */
    public function getRefreshTokenExpireTime(): int
    {
        return (int)($this->getData(self::schema_fields_refresh_token_expire_time) ?? self::DEFAULT_REFRESH_TOKEN_EXPIRE_TIME); // 默认30天
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
        return $this->setData(self::schema_fields_refresh_token_expire_time, $seconds);
    }
    /**
     * 是否启用
     */
    public function getIsEnabled(): bool
    {
        return (bool)($this->getData(self::schema_fields_is_enabled) ?? self::DEFAULT_IS_ENABLED);
    }
    /**
     * 设置是否启用
     */
    public function setIsEnabled(bool $enabled): self
    {
        return $this->setData(self::schema_fields_is_enabled, (int)$enabled);
    }
    /**
     * 是否沙盒账户
     */
    public function isSandboxAccount(): bool
    {
        return (bool)($this->getData(self::schema_fields_is_sandbox) ?? self::DEFAULT_IS_SANDBOX);
    }
    /**
     * 设置沙盒账户
     */
    public function setSandboxAccount(bool $sandbox): self
    {
        return $this->setData(self::schema_fields_is_sandbox, (int)$sandbox);
    }
    /**
     * 是否删除
     */
    public function getIsDeleted(): bool
    {
        return (bool)($this->getData(self::schema_fields_is_deleted) ?? self::DEFAULT_IS_DELETED);
    }
    /**
     * 设置是否删除
     */
    public function setIsDeleted(bool $deleted): self
    {
        return $this->setData(self::schema_fields_is_deleted, (int)$deleted);
    }
    /**
     * 是否启用IP白名单
     */
    public function isIpWhitelistEnabled(): bool
    {
        return (bool)($this->getData(self::schema_fields_ip_whitelist_enabled) ?? self::DEFAULT_IP_WHITELIST_ENABLED);
    }
    /**
     * 设置是否启用IP白名单
     */
    public function setIpWhitelistEnabled(bool $enabled): self
    {
        return $this->setData(self::schema_fields_ip_whitelist_enabled, (int)$enabled);
    }
    /**
     * 获取允许的IP地址列表
     * 
     * @return array
     */
    public function getAllowedIps(): array
    {
        $ips = $this->getData(self::schema_fields_allowed_ips);
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
        return $this->setData(self::schema_fields_allowed_ips, $ips);
    }
    /**
     * 是否启用用户代理限制
     */
    public function isUserAgentRestrictionEnabled(): bool
    {
        return (bool)($this->getData(self::schema_fields_user_agent_restriction_enabled) ?? self::DEFAULT_USER_AGENT_RESTRICTION_ENABLED);
    }
    /**
     * 设置是否启用用户代理限制
     */
    public function setUserAgentRestrictionEnabled(bool $enabled): self
    {
        return $this->setData(self::schema_fields_user_agent_restriction_enabled, (int)$enabled);
    }
    /**
     * 获取允许的用户代理列表
     * 
     * @return array
     */
    public function getAllowedUserAgents(): array
    {
        $userAgents = $this->getData(self::schema_fields_allowed_user_agents);
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
        return $this->setData(self::schema_fields_allowed_user_agents, $userAgents);
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

        if ($this->getData(self::schema_fields_token_expire_time) === null || $this->getData(self::schema_fields_token_expire_time) === '') {
            $this->setTokenExpireTime(self::DEFAULT_TOKEN_EXPIRE_TIME);
        }

        if ($this->getData(self::schema_fields_refresh_token_expire_time) === null || $this->getData(self::schema_fields_refresh_token_expire_time) === '') {
            $this->setRefreshTokenExpireTime(self::DEFAULT_REFRESH_TOKEN_EXPIRE_TIME);
        }

        $this->setDefaultDataIfMissing(self::schema_fields_is_enabled, self::DEFAULT_IS_ENABLED);
        $this->setDefaultDataIfMissing(self::schema_fields_is_deleted, self::DEFAULT_IS_DELETED);
        $this->setDefaultDataIfMissing(self::schema_fields_ip_whitelist_enabled, self::DEFAULT_IP_WHITELIST_ENABLED);
        $this->setDefaultDataIfMissing(self::schema_fields_allowed_ips, self::DEFAULT_ALLOWED_IPS);
        $this->setDefaultDataIfMissing(self::schema_fields_user_agent_restriction_enabled, self::DEFAULT_USER_AGENT_RESTRICTION_ENABLED);
        $this->setDefaultDataIfMissing(self::schema_fields_allowed_user_agents, self::DEFAULT_ALLOWED_USER_AGENTS);
        $this->setDefaultDataIfMissing(self::schema_fields_is_sandbox, self::DEFAULT_IS_SANDBOX);
        
        // 设置更新时间
        $this->setData(self::schema_fields_updated_at, date('Y-m-d H:i:s'));
        
        // 如果是新用户，设置创建时间
        if (!$this->getId()) {
            $this->setData(self::schema_fields_created_at, date('Y-m-d H:i:s'));
        }
        
        parent::save_before();
    }

    private function setDefaultDataIfMissing(string $field, mixed $default): void
    {
        $value = $this->getData($field);
        if ($value === null || $value === '') {
            $this->setData($field, $default);
        }
    }
}
