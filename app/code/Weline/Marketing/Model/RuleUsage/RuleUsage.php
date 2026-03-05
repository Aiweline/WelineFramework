<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\RuleUsage;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 规则使用记录模型 @package Weline_Marketing */
#[Table(comment: '规则使用记录表')]
#[Index(name: 'idx_rule', columns: ['rule_id'])]
#[Index(name: 'idx_customer', columns: ['customer_id'])]
#[Index(name: 'idx_order', columns: ['order_id'])]
#[Index(name: 'idx_coupon', columns: ['coupon_id'])]
#[Index(name: 'idx_used_at', columns: ['used_at'])]
class RuleUsage extends Model
{
    public const schema_table = 'weline_marketing_rule_usage';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'rule_id', 'customer_id', 'order_id', 'used_at'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '记录ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'int', nullable: false, comment: '规则ID')]
    public const schema_fields_RULE_ID = 'rule_id';
    #[Col(type: 'int', nullable: true, comment: '优惠券ID')]
    public const schema_fields_COUPON_ID = 'coupon_id';
    #[Col(type: 'int', nullable: true, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'int', nullable: true, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'decimal', length: '10,4', default: 0, comment: '折扣金额')]
    public const schema_fields_DISCOUNT_AMOUNT = 'discount_amount';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '使用时间')]
    public const schema_fields_USED_AT = 'used_at';
}
