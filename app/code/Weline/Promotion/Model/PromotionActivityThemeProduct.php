<?php

declare(strict_types=1);

namespace Weline\Promotion\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 活动主题绑定商品 @package Weline_Promotion */
#[Table(comment: '活动主题绑定商品')]
#[Index(name: 'idx_promotion_theme_product', columns: ['theme_id', 'website_id', 'product_id'], type: 'UNIQUE')]
#[Index(name: 'idx_promotion_theme_product_sort', columns: ['theme_id', 'sort_order'])]
class PromotionActivityThemeProduct extends Model
{
    public const schema_table = 'weline_promotion_activity_theme_product';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'theme_id', 'website_id', 'product_id'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'int', nullable: false, comment: '活动主题 ID')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '商品所属 Website ID，0=沿用主题范围')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'int', nullable: false, comment: '商品 ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
}
