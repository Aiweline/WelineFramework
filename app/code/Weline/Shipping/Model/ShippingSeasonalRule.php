<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '旺季/燃油季节加价')]
#[Index(name: 'uk_shipping_seasonal_scope_code', columns: ['scope_type', 'scope_id', 'rule_code'], type: 'UNIQUE')]
#[Index(name: 'idx_shipping_seasonal_scope', columns: ['scope_type', 'scope_id', 'is_active'])]
class ShippingSeasonalRule extends AbstractModel
{
    public const schema_table = 'w_shipping_seasonal_rules';
    public const schema_primary_key = 'rule_id';

    public const SCOPE_WEBSITE = 'website';
    public const AMOUNT_FIXED = 'fixed';
    public const AMOUNT_PERCENT = 'percent';

    public const SEED_PEAK = 'SEED_SEASONAL_PEAK';
    public const SEED_FUEL = 'SEED_SEASONAL_FUEL';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '规则ID')]
    public const schema_fields_ID = 'rule_id';
    #[Col('varchar', 16, nullable: false, default: 'website', comment: '作用范围类型')]
    public const schema_fields_SCOPE_TYPE = 'scope_type';
    #[Col('int', null, nullable: false, default: 0, comment: '作用范围ID')]
    public const schema_fields_SCOPE_ID = 'scope_id';
    #[Col('varchar', 64, nullable: false, comment: '规则代码')]
    public const schema_fields_RULE_CODE = 'rule_code';
    #[Col('varchar', 255, nullable: false, comment: '规则名称')]
    public const schema_fields_RULE_NAME = 'rule_name';
    #[Col('date', nullable: false, comment: '开始日期')]
    public const schema_fields_START_DATE = 'start_date';
    #[Col('date', nullable: false, comment: '结束日期')]
    public const schema_fields_END_DATE = 'end_date';
    #[Col('varchar', 16, nullable: false, default: 'percent', comment: '加价方式 fixed|percent')]
    public const schema_fields_AMOUNT_TYPE = 'amount_type';
    #[Col('decimal', '12,4', nullable: false, default: 0, comment: '加价数值')]
    public const schema_fields_AMOUNT_VALUE = 'amount_value';
    #[Col('int', 1, nullable: false, default: 0, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', null, nullable: false, default: 0, comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['rule_id'];
    public array $_index_sort_keys = ['rule_id', 'scope_type', 'scope_id', 'rule_code'];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_primary_key = self::schema_fields_ID;
    }
}
