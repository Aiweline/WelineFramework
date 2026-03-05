<?php

declare(strict_types=1);

namespace WeShop\Customer\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Session\Auth\AuthenticableInterface;
/**
 * 客户模型（WeShop扩展）
 */
#[Table(comment: 'WeShop客户表')]
#[Index(name: 'idx_user_id', columns: ['user_id'], type: 'UNIQUE', comment: '用户ID唯一索引')]
#[Index(name: 'idx_email', columns: ['email'], comment: '邮箱索引')]
class Customer extends Model implements AuthenticableInterface
{
    public const schema_table = 'weshop_customer';
    public const schema_primary_key = 'customer_id';
    public string $indexer = 'customer_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '客户ID')]
    public const schema_fields_ID = 'customer_id';
    #[Col(type: 'int', nullable: false, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: '名')]
    public const schema_fields_FIRST_NAME = 'first_name';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: '姓')]
    public const schema_fields_LAST_NAME = 'last_name';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '邮箱')]
    public const schema_fields_EMAIL = 'email';
    #[Col(type: 'varchar', length: 20, nullable: true, comment: '电话')]
    public const schema_fields_PHONE = 'phone';
    #[Col(type: 'varchar', length: 10, nullable: true, comment: '性别')]
    public const schema_fields_GENDER = 'gender';
    #[Col(type: 'date', nullable: true, comment: '生日')]
    public const schema_fields_BIRTHDAY = 'birthday';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '头像')]
    public const schema_fields_AVATAR = 'avatar';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['customer_id'];
    public array $_index_sort_keys = ['user_id', 'email', 'status'];

    // ==================== AuthenticableInterface 实现 ====================

    /**
     * @inheritDoc
     */
    public function getAuthIdentifier(): int|string
    {
        return $this->getId();
    }

    /**
     * @inheritDoc
     */
    public function getAuthUsername(): string
    {
        return (string) ($this->getData(self::schema_fields_EMAIL) ?? '');
    }

    /**
     * @inheritDoc
     */
    public function getAuthSessionId(): string
    {
        return (string) ($this->getData('sess_id') ?? '');
    }

    /**
     * @inheritDoc
     */
    public static function getAuthModelClass(): string
    {
        return self::class;
    }

    // ==================== 辅助方法 ====================

    /**
     * 获取客户全名
     */
    public function getFullName(): string
    {
        $firstName = (string) ($this->getData(self::schema_fields_FIRST_NAME) ?? '');
        $lastName = (string) ($this->getData(self::schema_fields_LAST_NAME) ?? '');

        return \trim($firstName . ' ' . $lastName);
    }

    /**
     * 检查客户是否启用
     */
    public function getIsEnabled(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === 'active';
    }
}
