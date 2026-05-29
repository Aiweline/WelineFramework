<?php
declare(strict_types=1);

namespace Weline\AppStore\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AppStore 商城账户表')]
#[Index(name: 'idx_platform_user_id', columns: ['platform_user_id'], type: 'UNIQUE', comment: '平台用户ID唯一索引')]
class AppStoreAccount extends Model
{
    public const schema_table = 'weline_appstore_account';
    public const schema_primary_key = 'account_id';

    // 绑定状态常量
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '账户ID')]
    public const schema_fields_ID = 'account_id';

    #[Col(type: 'int', nullable: false, comment: '平台用户ID')]
    public const schema_fields_platform_user_id = 'platform_user_id';

    #[Col(type: 'text', nullable: true, comment: '平台访问令牌 (加密存储)')]
    public const schema_fields_platform_token = 'platform_token';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: '平台邮箱')]
    public const schema_fields_platform_email = 'platform_email';

    #[Col(type: 'varchar', length: 100, nullable: true, comment: '平台用户名')]
    public const schema_fields_platform_username = 'platform_username';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '绑定终端域名')]
    public const schema_fields_bound_domain = 'bound_domain';

    #[Col(type: 'varchar', length: 20, nullable: false, default: self::STATUS_ACTIVE, comment: '绑定状态')]
    public const schema_fields_status = 'status';

    #[Col(type: 'datetime', nullable: true, comment: '绑定时间')]
    public const schema_fields_bound_at = 'bound_at';

    #[Col(type: 'datetime', nullable: true, comment: '令牌过期时间')]
    public const schema_fields_token_expires_at = 'token_expires_at';

    #[Col(type: 'datetime', nullable: true, comment: '最后同步时间')]
    public const schema_fields_last_sync_at = 'last_sync_at';

    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public function getAccountId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setAccountId(int $accountId): static
    {
        $this->setData(self::schema_fields_ID, $accountId);
        return $this;
    }

    public function getPlatformUserId(): int
    {
        return (int)$this->getData(self::schema_fields_platform_user_id);
    }

    public function setPlatformUserId(int $platformUserId): static
    {
        $this->setData(self::schema_fields_platform_user_id, $platformUserId);
        return $this;
    }

    public function getPlatformToken(): ?string
    {
        return $this->getData(self::schema_fields_platform_token);
    }

    public function setPlatformToken(?string $token): static
    {
        $this->setData(self::schema_fields_platform_token, $token);
        return $this;
    }

    public function getPlatformEmail(): string
    {
        return $this->getData(self::schema_fields_platform_email) ?? '';
    }

    public function setPlatformEmail(string $email): static
    {
        $this->setData(self::schema_fields_platform_email, $email);
        return $this;
    }

    public function getPlatformUsername(): ?string
    {
        return $this->getData(self::schema_fields_platform_username);
    }

    public function setPlatformUsername(?string $username): static
    {
        $this->setData(self::schema_fields_platform_username, $username);
        return $this;
    }

    public function getBoundDomain(): ?string
    {
        $domain = $this->getData(self::schema_fields_bound_domain);
        return $domain === null ? null : (string)$domain;
    }

    public function setBoundDomain(?string $domain): static
    {
        return $this;
    }

    public function getStatus(): string
    {
        return $this->getData(self::schema_fields_status) ?? self::STATUS_ACTIVE;
    }

    public function setStatus(string $status): static
    {
        $this->setData(self::schema_fields_status, $status);
        return $this;
    }

    public function getBoundAt(): ?string
    {
        return $this->getData(self::schema_fields_bound_at);
    }

    public function setBoundAt(?string $boundAt): static
    {
        $this->setData(self::schema_fields_bound_at, $boundAt);
        return $this;
    }

    public function getTokenExpiresAt(): ?string
    {
        return $this->getData(self::schema_fields_token_expires_at);
    }

    public function setTokenExpiresAt(?string $expiresAt): static
    {
        $this->setData(self::schema_fields_token_expires_at, $expiresAt);
        return $this;
    }

    public function getLastSyncAt(): ?string
    {
        return $this->getData(self::schema_fields_last_sync_at);
    }

    public function setLastSyncAt(?string $lastSyncAt): static
    {
        $this->setData(self::schema_fields_last_sync_at, $lastSyncAt);
        return $this;
    }

    public function isActive(): bool
    {
        if ($this->getStatus() !== self::STATUS_ACTIVE) {
            return false;
        }

        $expiresAt = $this->getTokenExpiresAt();
        if ($expiresAt && strtotime($expiresAt) < time()) {
            return false;
        }

        return true;
    }

    public function isTokenExpired(): bool
    {
        $expiresAt = $this->getTokenExpiresAt();
        return $expiresAt && strtotime($expiresAt) < time();
    }

    public function updateSyncTime(): static
    {
        $this->setLastSyncAt(date('Y-m-d H:i:s'));
        return $this;
    }
}
