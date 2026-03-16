<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '平台开发者表')]
#[Index(name: 'idx_user_id', columns: ['user_id'], type: 'UNIQUE', comment: '用户ID唯一索引')]
#[Index(name: 'idx_status', columns: ['status'], type: 'KEY', comment: '状态索引')]
class PlatformDeveloper extends Model
{
    public const schema_table = 'weline_platform_developer';
    public const schema_primary_key = 'developer_id';

    // 开发者状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SUSPENDED = 'suspended';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '开发者ID')]
    public const schema_fields_ID = 'developer_id';

    #[Col(type: 'int', nullable: false, comment: '关联用户ID')]
    public const schema_fields_user_id = 'user_id';

    #[Col(type: 'varchar', length: 100, nullable: false, comment: '开发者名称')]
    public const schema_fields_name = 'name';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '开发者标识 (用于URL)')]
    public const schema_fields_slug = 'slug';

    #[Col(type: 'text', nullable: true, comment: '开发者简介')]
    public const schema_fields_description = 'description';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '头像')]
    public const schema_fields_avatar = 'avatar';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '网站URL')]
    public const schema_fields_website = 'website';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '联系邮箱')]
    public const schema_fields_contact_email = 'contact_email';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: 0, comment: '收益余额')]
    public const schema_fields_balance = 'balance';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: 0, comment: '累计收益')]
    public const schema_fields_total_earnings = 'total_earnings';

    #[Col(type: 'int', nullable: false, default: 0, comment: '模块数量')]
    public const schema_fields_module_count = 'module_count';

    #[Col(type: 'varchar', length: 20, nullable: false, default: self::STATUS_PENDING, comment: '状态')]
    public const schema_fields_status = 'status';

    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public function getDeveloperId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setDeveloperId(int $developerId): static
    {
        $this->setData(self::schema_fields_ID, $developerId);
        return $this;
    }

    public function getUserId(): int
    {
        return (int)$this->getData(self::schema_fields_user_id);
    }

    public function setUserId(int $userId): static
    {
        $this->setData(self::schema_fields_user_id, $userId);
        return $this;
    }

    public function getName(): string
    {
        return $this->getData(self::schema_fields_name) ?? '';
    }

    public function setName(string $name): static
    {
        $this->setData(self::schema_fields_name, $name);
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->getData(self::schema_fields_slug);
    }

    public function setSlug(?string $slug): static
    {
        $this->setData(self::schema_fields_slug, $slug);
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->getData(self::schema_fields_description);
    }

    public function setDescription(?string $description): static
    {
        $this->setData(self::schema_fields_description, $description);
        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->getData(self::schema_fields_avatar);
    }

    public function setAvatar(?string $avatar): static
    {
        $this->setData(self::schema_fields_avatar, $avatar);
        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->getData(self::schema_fields_website);
    }

    public function setWebsite(?string $website): static
    {
        $this->setData(self::schema_fields_website, $website);
        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->getData(self::schema_fields_contact_email);
    }

    public function setContactEmail(?string $email): static
    {
        $this->setData(self::schema_fields_contact_email, $email);
        return $this;
    }

    public function getBalance(): float
    {
        return (float)$this->getData(self::schema_fields_balance);
    }

    public function setBalance(float $balance): static
    {
        $this->setData(self::schema_fields_balance, $balance);
        return $this;
    }

    public function getTotalEarnings(): float
    {
        return (float)$this->getData(self::schema_fields_total_earnings);
    }

    public function setTotalEarnings(float $totalEarnings): static
    {
        $this->setData(self::schema_fields_total_earnings, $totalEarnings);
        return $this;
    }

    public function addEarnings(float $amount): static
    {
        $this->setBalance($this->getBalance() + $amount);
        $this->setTotalEarnings($this->getTotalEarnings() + $amount);
        return $this;
    }

    public function getModuleCount(): int
    {
        return (int)$this->getData(self::schema_fields_module_count);
    }

    public function setModuleCount(int $count): static
    {
        $this->setData(self::schema_fields_module_count, $count);
        return $this;
    }

    public function getStatus(): string
    {
        return $this->getData(self::schema_fields_status) ?? self::STATUS_PENDING;
    }

    public function setStatus(string $status): static
    {
        $this->setData(self::schema_fields_status, $status);
        return $this;
    }

    public function isApproved(): bool
    {
        return $this->getStatus() === self::STATUS_APPROVED;
    }
}
