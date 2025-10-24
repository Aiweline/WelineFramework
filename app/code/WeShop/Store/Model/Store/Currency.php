<?php

namespace WeShop\Store\Model\Store;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Currency extends Model
{

    public const fields_ID = 'store_currency_id';
    public const fields_store_id = 'store_id';
    public const fields_currency_id = 'currency_id';

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
//        if(!$setup->tableExist()){
//            $setup->createTable('店铺货币表')
//        }
    }
}