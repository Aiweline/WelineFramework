<?php
declare(strict_types=1);
namespace Weline\Ai\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * AI Billing Plan Entity
 *
 * Defines billing plans and pricing strategies.
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI Billing Plan')]
#[Index(name: 'idx_plan_type', columns: ['plan_type'])]
class AiBillingPlan extends Model
{
    public const schema_table = 'weline_ai_ai_billing_plan';
    public const schema_primary_key = 'id';
    /** @var array Unit primary keys */
    public array $_unit_primary_keys = ['id'];
    /** @var array Index sort keys */
    public array $_index_sort_keys = ['id', 'plan_type'];
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '计划ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '计划名称')]
    public const schema_fields_PLAN_NAME = 'plan_name';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '计划类型')]
    public const schema_fields_PLAN_TYPE = 'plan_type';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0, comment: '价格')]
    public const schema_fields_PRICE = 'price';
    #[Col(type: 'varchar', length: 10, nullable: false, default: 'CNY', comment: '货币单位')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col(type: 'varchar', length: 20, nullable: false, comment: '计费周期')]
    public const schema_fields_BILLING_CYCLE = 'billing_cycle';
    #[Col(type: 'text', nullable: true, comment: '功能列表（JSON）')]
    public const schema_fields_FEATURES = 'features';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    public const PLAN_TYPE_FREE = 'free';
    public const PLAN_TYPE_BASIC = 'basic';
    public const PLAN_TYPE_PRO = 'pro';
    public const PLAN_TYPE_ENTERPRISE = 'enterprise';
    public const BILLING_CYCLE_MONTHLY = 'monthly';
    public const BILLING_CYCLE_YEARLY = 'yearly';
}
