<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Visitor\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 像素加密Token模型
 *
 * 用于管理基于Deploy版本号的加密token
 */
#[Table(comment: 'weline 像素加密Token')]
#[Index(name: 'idx_version', columns: ['version'], type: 'UNIQUE')]
#[Index(name: 'idx_expires_at', columns: ['expires_at'])]
#[Index(name: 'idx_is_deleted', columns: ['is_deleted'])]
class PixelEncryptionToken extends Model
{
    public const schema_table = 'w_pixel_encryption_token';
    public const schema_primary_key = 'token_id';
    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'token_id';
    #[Col('varchar', 100, nullable: false, comment: '部署版本号（格式：版本号-日期，如：1.0.0-20250101）')]
    public const schema_fields_VERSION = 'version';
    #[Col('varchar', 255, nullable: false, comment: '加密token')]
    public const schema_fields_ENCRYPTION_TOKEN = 'encryption_token';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, comment: '过期时间（创建时间+90天）')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('smallint', 1, default: 0, comment: '是否已删除（0=未删除，1=已删除）')]
    public const schema_fields_IS_DELETED = 'is_deleted';
    #[Col('datetime', comment: '删除时间')]
    public const schema_fields_DELETED_AT = 'deleted_at';
/**
     * 获取Token ID
     */
    public function getTokenId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }
    /**
     * 获取版本号
     */
    public function getVersion(): string
    {
        return (string)$this->getData(self::schema_fields_VERSION);
    }
    /**
     * 获取加密token
     */
    public function getEncryptionToken(): string
    {
        return (string)$this->getData(self::schema_fields_ENCRYPTION_TOKEN);
    }
    /**
     * 获取创建时间
     */
    public function getCreatedAt(): string
    {
        return (string)$this->getData(self::schema_fields_CREATED_AT);
    }
    /**
     * 获取过期时间
     */
    public function getExpiresAt(): string
    {
        return (string)$this->getData(self::schema_fields_EXPIRES_AT);
    }
    /**
     * 是否已删除
     */
    public function getIsDeleted(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_DELETED);
    }
    /**
     * 获取删除时间
     */
    public function getDeletedAt(): ?string
    {
        $deletedAt = $this->getData(self::schema_fields_DELETED_AT);
        return $deletedAt ? (string)$deletedAt : null;
    }
    /**
     * 设置Token ID
     */
    public function setTokenId(int $token_id): static
    {
        return $this->setData(self::schema_fields_ID, $token_id);
    }
    /**
     * 设置版本号
     */
    public function setVersion(string $version): static
    {
        return $this->setData(self::schema_fields_VERSION, $version);
    }
    /**
     * 设置加密token
     */
    public function setEncryptionToken(string $encryption_token): static
    {
        return $this->setData(self::schema_fields_ENCRYPTION_TOKEN, $encryption_token);
    }
    /**
     * 设置创建时间
     */
    public function setCreatedAt(string $created_at): static
    {
        return $this->setData(self::schema_fields_CREATED_AT, $created_at);
    }
    /**
     * 设置过期时间
     */
    public function setExpiresAt(string $expires_at): static
    {
        return $this->setData(self::schema_fields_EXPIRES_AT, $expires_at);
    }
    /**
     * 设置是否已删除
     */
    public function setIsDeleted(bool|int $is_deleted): static
    {
        return $this->setData(self::schema_fields_IS_DELETED, $is_deleted ? 1 : 0);
    }
    /**
     * 设置删除时间
     */
    public function setDeletedAt(?string $deleted_at): static
    {
        return $this->setData(self::schema_fields_DELETED_AT, $deleted_at);
    }
    /**
     * 根据版本号查找token
     */
    public function findByVersion(string $version): ?self
    {
        return $this->reset()
            ->where(self::schema_fields_VERSION, $version)
            ->where(self::schema_fields_IS_DELETED, 0)
            ->find()
            ->fetch();
    }
    /**
     * 获取所有有效的token（未过期且未删除）
     */
    public function getValidTokens(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->reset()
            ->where(self::schema_fields_IS_DELETED, 0)
            ->where(self::schema_fields_EXPIRES_AT, $now, '>=')
            ->select()
            ->fetchArray();
    }
    /**
     * 获取当前版本号的token
     */
    public function getCurrentVersionToken(): ?self
    {
        // 获取最新的未删除token
        return $this->reset()
            ->where(self::schema_fields_IS_DELETED, 0)
            ->order('created_at', 'DESC')
            ->find()
            ->fetch();
    }
    /**
     * 标记90天前的旧token为已删除
     */
    public function markOldTokensAsDeleted(): int
    {
        $ninetyDaysAgo = date('Y-m-d H:i:s', strtotime('-90 days'));
        $now = date('Y-m-d H:i:s');
        
        // 查找90天前创建的且未删除的token
        $oldTokens = $this->reset()
            ->where(self::schema_fields_IS_DELETED, 0)
            ->where(self::schema_fields_CREATED_AT, $ninetyDaysAgo, '<=')
            ->select()
            ->fetchArray();
        
        $count = 0;
        foreach ($oldTokens as $tokenData) {
            $token = clone $this;
            $token->setData($tokenData);
            $token->setIsDeleted(true)
                ->setDeletedAt($now)
                ->save();
            $count++;
        }
        
        return $count;
    }
}
