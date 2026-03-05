<?php

declare(strict_types=1);

namespace WeShop\Compliance\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * Cookie同意模型
 */
#[Table(comment: 'WeShop Cookie同意表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], comment: '客户ID索引')]
class CookieConsent extends Model
{
    public const schema_table = 'weshop_cookie_consent';
    public const schema_primary_key = 'consent_id';
    public string $indexer = 'cookie_consent_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '同意ID')]
    public const schema_fields_ID = 'consent_id';
    #[Col(type: 'int', nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '同意类型')]
    public const schema_fields_CONSENT_TYPE = 'consent_type';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 0, comment: '是否同意')]
    public const schema_fields_IS_ACCEPTED = 'is_accepted';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['consent_id'];
    public array $_index_sort_keys = ['customer_id', 'consent_type'];
}
