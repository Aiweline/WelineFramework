<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Mobile Notification Entity
 * 
 * Records mobile push notifications (reserved for future use).
 * 
 * @package Weline_Ai
 */
class AiMobileNotification extends Model
{
    // 框架自动推导表名：AiMobileNotification → ai_mobile_notification
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'device_id', 'status'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_DEVICE_ID = 'device_id';
    public const fields_NOTIFICATION_TYPE = 'notification_type';
    public const fields_NOTIFICATION_TITLE = 'notification_title';
    public const fields_NOTIFICATION_BODY = 'notification_body';
    public const fields_STATUS = 'status';
    public const fields_SENT_AT = 'sent_at';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

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
            $setup->createTable('AI Mobile Notification')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '通知ID'
            )
            ->addColumn(
                self::fields_DEVICE_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '设备ID'
            )
            ->addColumn(
                self::fields_NOTIFICATION_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '通知类型'
            )
            ->addColumn(
                self::fields_NOTIFICATION_TITLE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '通知标题'
            )
            ->addColumn(
                self::fields_NOTIFICATION_BODY,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '通知内容'
            )
            ->addColumn(
                self::fields_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null default \'pending\'',
                '状态'
            )
            ->addColumn(
                self::fields_SENT_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'null',
                '发送时间'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('INDEX', 'idx_device_id', self::fields_DEVICE_ID)
            ->addIndex('INDEX', 'idx_status', self::fields_STATUS)
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
