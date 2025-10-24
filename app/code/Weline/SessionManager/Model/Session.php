<?php

namespace Weline\SessionManager\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Db\Ddl\Table;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Session extends Model
{
    public const fields_ID = 'sess_id';
    public const fields_SESSION_DATA = 'sess_data';

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
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        //        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_VARCHAR, 128, 'primary key', 'Session ID')
                ->addColumn(self::fields_SESSION_DATA, TableInterface::column_type_TEXT, 65535, "", 'Session数据')
                ->create();
        }
    }
}
