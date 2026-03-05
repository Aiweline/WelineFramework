<?php

declare(strict_types=1);

namespace WeShop\Store\Model\Store;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '店铺货币表')]
class Currency extends Model
{
    public const schema_table = 'weshop_store_currency';
    public const schema_primary_key = 'store_currency_id';

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '店铺货币ID')]
    public const schema_fields_ID = 'store_currency_id';
    #[Col('int', 0, nullable: false, comment: '店铺ID')]
    public const schema_fields_STORE_ID = 'store_id';
    #[Col('int', 0, nullable: false, comment: '货币ID')]
    public const schema_fields_CURRENCY_ID = 'currency_id';

    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    public function install(ModelSetup $setup, Context $context): void {}
}
