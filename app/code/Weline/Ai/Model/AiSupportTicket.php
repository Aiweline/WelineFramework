<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Support Ticket Entity
 * 
 * Manages customer support tickets.
 * 
 * @package Weline_Ai
 */
class AiSupportTicket extends Model
{
    // 框架自动推导表名：AiSupportTicket → ai_support_ticket
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'user_id', 'status', 'priority'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_TICKET_NUMBER = 'ticket_number';
    public const fields_USER_ID = 'user_id';
    public const fields_SUBJECT = 'subject';
    public const fields_DESCRIPTION = 'description';
    public const fields_PRIORITY = 'priority';
    public const fields_STATUS = 'status';
    public const fields_ASSIGNED_TO = 'assigned_to';
    public const fields_CREATED_AT = 'created_at';
    public const fields_RESOLVED_AT = 'resolved_at';

    /**
     * Priority constants
     */
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    /**
     * Status constants
     */
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

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
            $setup->createTable('AI Support Ticket')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '工单ID'
            )
            ->addColumn(
                self::fields_TICKET_NUMBER,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                100,
                'not null unique',
                '工单号'
            )
            ->addColumn(
                self::fields_USER_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '用户ID'
            )
            ->addColumn(
                self::fields_SUBJECT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '工单主题'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'not null',
                '问题描述'
            )
            ->addColumn(
                self::fields_PRIORITY,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null default \'normal\'',
                '优先级'
            )
            ->addColumn(
                self::fields_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null default \'open\'',
                '状态'
            )
            ->addColumn(
                self::fields_ASSIGNED_TO,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'null',
                '分配给（客服ID）'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addColumn(
                self::fields_RESOLVED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'null',
                '解决时间'
            )
            ->addIndex('UNIQUE', 'uk_ticket_number', self::fields_TICKET_NUMBER)
            ->addIndex('INDEX', 'idx_user_id', self::fields_USER_ID)
            ->addIndex('INDEX', 'idx_status', self::fields_STATUS)
            ->addIndex('INDEX', 'idx_priority', self::fields_PRIORITY)
            ->addIndex('INDEX', 'idx_assigned_to', self::fields_ASSIGNED_TO)
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
