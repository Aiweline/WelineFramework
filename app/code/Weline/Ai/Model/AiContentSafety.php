<?php
declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 内容安全模型
 */
class AiContentSafety extends Model
{
    public const table = 'ai_content_safety';
    
    public const fields_ID = 'id';
    public const fields_CONTENT = 'content';
    public const fields_SAFETY_LEVEL = 'safety_level';
    public const fields_VIOLATIONS = 'violations';
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
                ->addColumn(self::fields_CONTENT, TableInterface::column_type_TEXT, null, 'not null', '内容')
                ->addColumn(self::fields_SAFETY_LEVEL, TableInterface::column_type_VARCHAR, 50, 'not null', '安全级别')
                ->addColumn(self::fields_VIOLATIONS, TableInterface::column_type_TEXT, null, 'null', '违规信息')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->create();
        }
    }
}
