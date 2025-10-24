<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Tenant User Entity
 * 
 * Manages tenant-user associations.
 * 
 * @package Weline_Ai
 */
class AiTenantUser extends Model
{
    // 框架自动推导表名：AiTenantUser → ai_tenant_user
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'tenant_id', 'user_id'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_USER_ID = 'user_id';
    public const fields_ROLE = 'role';
    public const fields_PERMISSIONS = 'permissions';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Role constants
     */
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    /**
     * Install database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        $this->useMainDbMaster();
        
        if ($setup->tableExist() === false) {
            $setup->createTable('AI Tenant User')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '映射ID'
            )
            ->addColumn(
                self::fields_TENANT_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '租户ID'
            )
            ->addColumn(
                self::fields_USER_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '用户ID'
            )
            ->addColumn(
                self::fields_ROLE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '角色'
            )
            ->addColumn(
                self::fields_PERMISSIONS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '权限列表（JSON）'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('UNIQUE', 'uk_tenant_user', [self::fields_TENANT_ID, self::fields_USER_ID])
            ->addIndex('INDEX', 'idx_user_id', self::fields_USER_ID)
            ->addIndex('INDEX', 'idx_role', self::fields_ROLE)
            ->create();
        }
    }

    /**
     * Setup database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * Upgrade database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // Future upgrades will be added here
    }
}
