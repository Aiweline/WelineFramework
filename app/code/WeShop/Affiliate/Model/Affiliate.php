<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 分销联盟模型
 */
#[Table(comment: 'WeShop分销联盟表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'UNIQUE', comment: '客户ID唯一索引')]
#[Index(name: 'idx_referral_code', columns: ['referral_code'], type: 'UNIQUE', comment: '推荐码唯一索引')]
class Affiliate extends Model
{
    public const schema_table = 'weshop_affiliate';
    public const schema_primary_key = 'affiliate_id';
    public string $indexer = 'affiliate_indexer';

    #[Col('integer', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: '分销ID')]
    public const schema_fields_ID = 'affiliate_id';
    #[Col('integer', 0, nullable: false, unique: true, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('varchar', 50, nullable: false, unique: true, comment: '推荐码')]
    public const schema_fields_REFERRAL_CODE = 'referral_code';
    #[Col('decimal', '5,2', nullable: false, default: 0.00, comment: '佣金比例')]
    public const schema_fields_COMMISSION_RATE = 'commission_rate';
    #[Col('decimal', '10,2', nullable: false, default: 0.00, comment: '总佣金')]
    public const schema_fields_TOTAL_COMMISSION = 'total_commission';
    #[Col('decimal', '10,2', nullable: false, default: 0.00, comment: '已支付佣金')]
    public const schema_fields_PAID_COMMISSION = 'paid_commission';
    #[Col('varchar', 20, nullable: true, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', 0, nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', 0, nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';


    public array $_unit_primary_keys = ['affiliate_id'];
    public array $_index_sort_keys = ['customer_id', 'referral_code'];
}
