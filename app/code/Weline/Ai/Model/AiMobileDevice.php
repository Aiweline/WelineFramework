<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Mobile Device Entity
 * 
 * Manages mobile device information.
 * 
 * @package Weline_Ai
 */
class AiMobileDevice extends Model
{
    // 框架自动推导表名：AiMobileDevice → ai_mobile_device
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'user_id', 'device_id'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_USER_ID = 'user_id';
    public const fields_DEVICE_ID = 'device_id';
    public const fields_DEVICE_TYPE = 'device_type';
    public const fields_DEVICE_TOKEN = 'device_token';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_LAST_ACTIVE_AT = 'last_active_at';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Device type constants
     */
    public const DEVICE_TYPE_IOS = 'ios';
    public const DEVICE_TYPE_ANDROID = 'android';

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
            $setup->createTable('AI Mobile Device')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '设备ID'
            )
            ->addColumn(
                self::fields_USER_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '用户ID'
            )
            ->addColumn(
                self::fields_DEVICE_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null unique',
                '设备唯一标识'
            )
            ->addColumn(
                self::fields_DEVICE_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '设备类型'
            )
            ->addColumn(
                self::fields_DEVICE_TOKEN,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'null',
                '推送令牌'
            )
            ->addColumn(
                self::fields_IS_ACTIVE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                1,
                'not null default 1',
                '是否激活'
            )
            ->addColumn(
                self::fields_LAST_ACTIVE_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'null',
                '最后活跃时间'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('UNIQUE', 'uk_device_id', self::fields_DEVICE_ID)
            ->addIndex('INDEX', 'idx_user_id', self::fields_USER_ID)
            ->addIndex('INDEX', 'idx_is_active', self::fields_IS_ACTIVE)
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
