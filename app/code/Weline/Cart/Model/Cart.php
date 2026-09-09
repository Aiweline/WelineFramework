<?php

declare(strict_types=1);

namespace Weline\Cart\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Persistent storefront cart（guest + customer）.
 */
#[Table(comment: '万能购物车持久化')]
#[Index(name: 'uk_weline_cart_key', columns: ['cart_key'], type: 'UNIQUE')]
#[Index(name: 'idx_weline_cart_scope', columns: ['scope_key', 'cart_type'])]
#[Index(name: 'idx_weline_cart_owner', columns: ['owner_kind', 'owner_id'])]
#[Index(name: 'idx_weline_cart_expires', columns: ['expires_at'])]
class Cart extends Model
{
    public const schema_table = 'weline_cart';
    public const schema_primary_key = 'cart_id';
    public string $_primary_key = 'cart_id';
    public array $_unit_primary_keys = ['cart_id'];
    public array $_index_sort_keys = ['cart_key', 'scope_key', 'owner_kind', 'owner_id', 'expires_at'];

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'cart_id';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Scope|owner|type cart key')]
    public const schema_fields_CART_KEY = 'cart_key';

    #[Col(type: 'varchar', length: 191, nullable: false, comment: 'Scope canonical key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col(type: 'varchar', length: 16, nullable: false, default: 'guest', comment: 'guest|customer')]
    public const schema_fields_OWNER_KIND = 'owner_kind';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'guest token or customer id')]
    public const schema_fields_OWNER_ID = 'owner_id';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Guest token when owner_kind=guest')]
    public const schema_fields_GUEST_TOKEN = 'guest_token';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'toc', comment: 'Registered cart_type')]
    public const schema_fields_CART_TYPE = 'cart_type';

    #[Col(type: 'varchar', length: 16, nullable: false, default: 'CNY', comment: 'Cart currency')]
    public const schema_fields_CURRENCY = 'currency';

    #[Col(type: 'text', nullable: false, comment: 'Full cart JSON payload')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col(type: 'datetime', nullable: true, comment: 'NULL = permanent (customer); guest TTL')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
