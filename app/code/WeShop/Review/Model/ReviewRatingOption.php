<?php

declare(strict_types=1);

namespace WeShop\Review\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 商品评价细分评分项配置。
 */
#[Table(comment: 'WeShop商品评价评分项配置表')]
#[Index(name: 'uk_code', columns: ['code'], type: 'UNIQUE', comment: '评分项编码唯一索引')]
#[Index(name: 'idx_enabled_sort', columns: ['is_enabled', 'sort_order'], type: 'KEY', comment: '启用评分项排序索引')]
class ReviewRatingOption extends Model
{
    public const schema_table = 'weshop_review_rating_option';
    public const schema_primary_key = 'option_id';
    public string $indexer = 'review_rating_option_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '评分项ID')]
    public const schema_fields_ID = 'option_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '评分项编码')]
    public const schema_fields_CODE = 'code';
    #[Col(type: 'varchar', length: 120, nullable: false, comment: '评分项名称')]
    public const schema_fields_LABEL = 'label';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col(type: 'int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 0, comment: '是否系统内置')]
    public const schema_fields_IS_SYSTEM = 'is_system';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['option_id'];
    public array $_index_sort_keys = ['code', 'is_enabled', 'sort_order'];
}
