<?php

declare(strict_types=1);

namespace WeShop\Membership\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 会员模型
 */
#[Table(comment: 'WeShop会员表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'UNIQUE', comment: '客户ID唯一索引')]
class Membership extends Model
{
    public const schema_table = 'weshop_membership';
    public const schema_primary_key = 'membership_id';
    public string $indexer = 'membership_indexer';

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '会员ID')]
    public const schema_fields_ID = 'membership_id';
    #[Col('int', 0, nullable: false, unique: true, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('varchar', 50, nullable: true, default: 'bronze', comment: '等级')]
    public const schema_fields_LEVEL = 'level';
    #[Col('int', 0, nullable: false, default: 0, comment: '积分')]
    public const schema_fields_POINTS = 'points';
    #[Col('datetime', 0, nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', 0, nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['membership_id'];
    public array $_index_sort_keys = ['customer_id', 'level'];
}

