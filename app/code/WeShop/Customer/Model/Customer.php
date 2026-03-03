<?php

declare(strict_types=1);

namespace WeShop\Customer\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 客户模型（WeShop扩展）
 */
class Customer extends \Weline\Framework\Database\Model implements AuthenticableInterface
{
    public const table = 'weshop_customer';
    public const primary_key = 'customer_id';
    public string $indexer = 'customer_indexer';
    
    public const fields_ID = 'customer_id';
    public const fields_USER_ID = 'user_id';
    public const fields_FIRST_NAME = 'first_name';
    public const fields_LAST_NAME = 'last_name';
    public const fields_EMAIL = 'email';
    public const fields_PHONE = 'phone';
    public const fields_GENDER = 'gender';
    public const fields_BIRTHDAY = 'birthday';
    public const fields_AVATAR = 'avatar';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['customer_id'];
    public array $_index_sort_keys = ['user_id', 'email', 'status'];
    
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
        // 升级逻辑
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('WeShop客户表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '客户ID')
                ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, 0, 'not null unique', '用户ID')
                ->addColumn(self::fields_FIRST_NAME, TableInterface::column_type_VARCHAR, 50, '', '名')
                ->addColumn(self::fields_LAST_NAME, TableInterface::column_type_VARCHAR, 50, '', '姓')
                ->addColumn(self::fields_EMAIL, TableInterface::column_type_VARCHAR, 100, '', '邮箱')
                ->addColumn(self::fields_PHONE, TableInterface::column_type_VARCHAR, 20, '', '电话')
                ->addColumn(self::fields_GENDER, TableInterface::column_type_VARCHAR, 10, '', '性别')
                ->addColumn(self::fields_BIRTHDAY, TableInterface::column_type_DATE, 0, '', '生日')
                ->addColumn(self::fields_AVATAR, TableInterface::column_type_VARCHAR, 255, '', '头像')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'active'", '状态')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_user_id', self::fields_USER_ID, '用户ID唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_email', self::fields_EMAIL, '邮箱索引')
                ->create();
        }
    }

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
        return (string) ($this->getData(self::fields_EMAIL) ?? '');
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
        $firstName = (string) ($this->getData(self::fields_FIRST_NAME) ?? '');
        $lastName = (string) ($this->getData(self::fields_LAST_NAME) ?? '');
        
        return \trim($firstName . ' ' . $lastName);
    }

    /**
     * 检查客户是否启用
     */
    public function getIsEnabled(): bool
    {
        return $this->getData(self::fields_STATUS) === 'active';
    }
}
