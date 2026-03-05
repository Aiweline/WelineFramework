<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Marketing\Model\Coupon;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 优惠券模型 @package Weline_Marketing */
#[Table(comment: '优惠券表')]
#[Index(name: 'idx_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'idx_rule', columns: ['rule_id'])]
#[Index(name: 'idx_status', columns: ['status', 'start_date', 'end_date'])]
class Coupon extends Model
{
    public const schema_table = 'weline_marketing_coupon';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'code', 'rule_id', 'status'];
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '优惠券ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', nullable: false, comment: '关联规则ID')]
    public const schema_fields_RULE_ID = 'rule_id';
    #[Col('varchar', 100, nullable: false, comment: '优惠券代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 50, nullable: false, comment: '优惠类型')]
    public const schema_fields_TYPE = 'type';
    #[Col('decimal', '10,4', comment: '折扣值')]
    public const schema_fields_DISCOUNT_VALUE = 'discount_value';
    #[Col('decimal', '10,4', comment: '最小订单金额')]
    public const schema_fields_MIN_AMOUNT = 'min_amount';
    #[Col('decimal', '10,4', comment: '最大折扣金额')]
    public const schema_fields_MAX_DISCOUNT = 'max_discount';
    #[Col('int', comment: '总使用次数')]
    public const schema_fields_USAGE_LIMIT = 'usage_limit';
    #[Col('int', default: 0, comment: '已使用次数')]
    public const schema_fields_USAGE_COUNT = 'usage_count';
    #[Col('int', default: 1, comment: '每个客户使用次数')]
    public const schema_fields_CUSTOMER_LIMIT = 'customer_limit';
    #[Col('varchar', 20, nullable: false, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', comment: '开始时间')]
    public const schema_fields_START_DATE = 'start_date';
    #[Col('datetime', comment: '结束时间')]
    public const schema_fields_END_DATE = 'end_date';
    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    /**
     * Type constants
     */
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED_AMOUNT = 'fixed_amount';
    public const TYPE_FREE_SHIPPING = 'free_shipping';
    public const TYPE_GIFT = 'gift';
    /**
     * Status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_EXHAUSTED = 'exhausted';
/**
     * Check if coupon is valid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        if ($this->getData(self::schema_fields_STATUS) !== self::STATUS_ACTIVE) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $startDate = $this->getData(self::schema_fields_START_DATE);
        $endDate = $this->getData(self::schema_fields_END_DATE);
        if ($startDate && $now < $startDate) {
            return false;
        }
        if ($endDate && $now > $endDate) {
            return false;
        }
        $usageLimit = $this->getData(self::schema_fields_USAGE_LIMIT);
        $usageCount = $this->getData(self::schema_fields_USAGE_COUNT);
        if ($usageLimit && $usageCount >= $usageLimit) {
            return false;
        }
        return true;
    }
}
