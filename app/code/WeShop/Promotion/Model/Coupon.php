<?php
declare(strict_types=1);
namespace WeShop\Promotion\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 优惠券模型
 */
#[Table(comment: 'WeShop优惠券表')]
#[Index(name: 'idx_code', columns: ['code'], type: 'UNIQUE', comment: '优惠券代码唯一索引')]
class Coupon extends Model
{
    public const schema_table = 'weshop_coupon';
    public const schema_primary_key = 'coupon_id';
    public string $indexer = 'coupon_indexer';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '优惠券ID')]
    public const schema_fields_ID = 'coupon_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '优惠券代码')]
    public const schema_fields_CODE = 'code';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'fixed', comment: '折扣类型（fixed/percent）')]
    public const schema_fields_DISCOUNT_TYPE = 'discount_type';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: '0.00', comment: '折扣值')]
    public const schema_fields_DISCOUNT_VALUE = 'discount_value';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: '0.00', comment: '最低消费金额')]
    public const schema_fields_MIN_AMOUNT = 'min_amount';
    #[Col(type: 'decimal', length: '10,2', nullable: true, default: '0.00', comment: '最大折扣金额')]
    public const schema_fields_MAX_DISCOUNT = 'max_discount';
    #[Col(type: 'datetime', nullable: false, comment: '开始日期')]
    public const schema_fields_START_DATE = 'start_date';
    #[Col(type: 'datetime', nullable: false, comment: '结束日期')]
    public const schema_fields_END_DATE = 'end_date';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public array $_unit_primary_keys = ['coupon_id'];
    public array $_index_sort_keys = ['code', 'is_active'];
}
