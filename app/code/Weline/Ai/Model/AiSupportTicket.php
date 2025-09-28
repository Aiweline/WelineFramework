<?php
declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 客户支持工单模型
 */
class AiSupportTicket extends Model
{
    public const table = 'ai_support_ticket';
    
    public const fields_ID = 'id';
    public const fields_TITLE = 'title';
    public const fields_DESCRIPTION = 'description';
    public const fields_STATUS = 'status';
    public const fields_CREATED_TIME = 'created_time';

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑 - 目前无需升级
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_TITLE, TableInterface::column_type_VARCHAR, 255, 'not null', '工单标题')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, null, 'null', '工单描述')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 50, 'not null default "open"', '状态')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->create();
        }
    }
}
