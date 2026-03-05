<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Shipping\Model;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '配送服务表')]
#[Index(name: 'idx_service_code', columns: ['service_code'], type: 'UNIQUE')]
#[Index(name: 'idx_carrier_id', columns: ['carrier_id'])]
#[Index(name: 'idx_zone_id', columns: ['zone_id'])]
#[Index(name: 'idx_rate_template_id', columns: ['rate_template_id'])]
#[Index(name: 'idx_free_shipping_rule_id', columns: ['free_shipping_rule_id'])]
class ShippingService extends AbstractModel
{
    public const schema_table = 'w_shipping_services';
    public const schema_primary_key = 'service_id';
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '服务ID')]
    public const schema_fields_ID = 'service_id';
    #[Col('varchar', 255, nullable: false, comment: '服务名称')]
    public const schema_fields_SERVICE_NAME = 'service_name';
    #[Col('varchar', 50, nullable: false, unique: true, comment: '服务代码')]
    public const schema_fields_SERVICE_CODE = 'service_code';
    #[Col('int', null, nullable: false, comment: '快递公司ID')]
    public const schema_fields_CARRIER_ID = 'carrier_id';
    #[Col('int', null, nullable: false, comment: '配送区域ID')]
    public const schema_fields_ZONE_ID = 'zone_id';
    #[Col('int', null, comment: '费用模板ID')]
    public const schema_fields_RATE_TEMPLATE_ID = 'rate_template_id';
    #[Col('int', null, comment: '免邮规则ID')]
    public const schema_fields_FREE_SHIPPING_RULE_ID = 'free_shipping_rule_id';
    #[Col('int', null, comment: '预计配送天数最小')]
    public const schema_fields_ESTIMATED_DAYS_MIN = 'estimated_days_min';
    #[Col('int', null, comment: '预计配送天数最大')]
    public const schema_fields_ESTIMATED_DAYS_MAX = 'estimated_days_max';
    #[Col('int', 1, nullable: false, default: 0, comment: '是否免邮')]
    public const schema_fields_IS_FREE_SHIPPING = 'is_free_shipping';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', null, nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['service_id'];
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['service_id', 'service_code', 'carrier_id', 'zone_id'];
    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_primary_key = self::schema_fields_ID;
    }
}
