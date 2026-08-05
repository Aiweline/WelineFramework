<?php

declare(strict_types=1);

namespace Weline\Inventory\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Store logical stock projection（可由 ledger 重算；CAS 用 stock_version）.
 */
#[Table(comment: 'Inventory store stock projection')]
#[Index(name: 'uk_inv_stock_offer', columns: ['website_id', 'store_id', 'offer_id'], type: 'UNIQUE')]
#[Index(name: 'idx_inv_stock_strategy', columns: ['strategy'])]
class InventoryStock extends Model
{
    public const schema_table = 'weline_inventory_stock';
    public const schema_primary_key = 'stock_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Stock ID')]
    public const schema_fields_ID = 'stock_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (>=0)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Store ID (>=0; 0=website default stock)')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('bigint', 20, nullable: false, comment: 'Offer ID in website shard')]
    public const schema_fields_OFFER_ID = 'offer_id';

    #[Col('varchar', 32, nullable: false, default: 'strict', comment: 'Strategy')]
    public const schema_fields_STRATEGY = 'strategy';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'On-hand minor qty')]
    public const schema_fields_ON_HAND_MINOR = 'on_hand_minor';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Reserved minor qty')]
    public const schema_fields_RESERVED_MINOR = 'reserved_minor';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Oversell allowance minor')]
    public const schema_fields_OVERSELL_ALLOWANCE = 'oversell_allowance';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Preorder allowance minor')]
    public const schema_fields_PREORDER_ALLOWANCE = 'preorder_allowance';

    #[Col('int', 11, nullable: false, default: 0, comment: 'CAS version')]
    public const schema_fields_STOCK_VERSION = 'stock_version';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
