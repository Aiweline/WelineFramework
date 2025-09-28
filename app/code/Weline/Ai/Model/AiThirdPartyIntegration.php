<?php
declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 第三方集成模型
 */
class AiThirdPartyIntegration extends Model
{
    public const table = 'ai_third_party_integration';
    
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_TYPE = 'type';
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
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '集成名称')
                ->addColumn(self::fields_TYPE, TableInterface::column_type_VARCHAR, 100, 'not null', '集成类型')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 50, 'not null default "active"', '状态')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->create();
        }
    }
}
