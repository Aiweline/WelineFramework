<?php

namespace Weline\Admin\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class BackendUserToken extends Model
{
    public const fields_ID = 'user_id';
    public const fields_token = 'token';
    public const fields_type = 'type';
    public const fields_token_expire_time = 'token_expire_time';

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
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'unique', '用户ID')
                ->addColumn(self::fields_token, TableInterface::column_type_VARCHAR, 255, '', 'token')
                ->addColumn(self::fields_type, TableInterface::column_type_VARCHAR, 255, 'not null', '类型')
                ->addColumn(self::fields_token_expire_time, TableInterface::column_type_VARCHAR, 11, '', '过期时间')
                ->create();
        }
    }
}
