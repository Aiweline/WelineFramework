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
#[Table(comment: '免邮规则表')]
#[Index(name: 'idx_rule_code', columns: ['rule_code'], type: 'UNIQUE')]
#[Index(name: 'idx_condition_type', columns: ['condition_type'])]
#[Index(name: 'idx_priority', columns: ['priority'])]
class FreeShippingRule extends AbstractModel
{
    public const schema_table = 'w_shipping_free_shipping_rules';
    public const schema_primary_key = 'rule_id';
    public const schema_primary_keys = ['rule_id'];
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '规则ID')]
    public const schema_fields_ID = 'rule_id';
    #[Col('varchar', 255, nullable: false, comment: '规则名称')]
    public const schema_fields_RULE_NAME = 'rule_name';
    #[Col('varchar', 50, nullable: false, unique: true, comment: '规则代码')]
    public const schema_fields_RULE_CODE = 'rule_code';
    #[Col('varchar', 20, nullable: false, comment: '条件类型')]
    public const schema_fields_CONDITION_TYPE = 'condition_type';
    #[Col('decimal', '10,2', comment: '最小订单金额')]
    public const schema_fields_MIN_ORDER_AMOUNT = 'min_order_amount';
    #[Col('text', comment: '会员等级ID列表JSON')]
    public const schema_fields_MEMBER_LEVEL_IDS = 'member_level_ids';
    #[Col('text', comment: '适用地区ID列表JSON')]
    public const schema_fields_REGION_IDS = 'region_ids';
    #[Col('text', comment: '优惠券代码列表JSON')]
    public const schema_fields_COUPON_CODES = 'coupon_codes';
    #[Col('text', comment: '混合条件配置JSON')]
    public const schema_fields_MIXED_CONFIG = 'mixed_config';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', null, nullable: false, default: 0, comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    // 条件类型常量
    public const CONDITION_ORDER_AMOUNT = 'order_amount';
    public const CONDITION_MEMBER_LEVEL = 'member_level';
    public const CONDITION_REGION = 'region';
    public const CONDITION_COUPON = 'coupon';
    public const CONDITION_MIXED = 'mixed';
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['rule_id', 'rule_code', 'priority'];
    /**
     * 初始化模型
     */
    public function _init(): void
    {
    }

    /**
/**
     * 获取会员等级ID列表
     * 
     * @return array
     */
    public function getMemberLevelIds(): array
    {
        $ids = $this->getData(self::schema_fields_MEMBER_LEVEL_IDS);
        if (empty($ids)) {
            return [];
        }
        return json_decode($ids, true) ?: [];
    }
    /**
     * 设置会员等级ID列表
     * 
     * @param array $ids
     * @return $this
     */
    public function setMemberLevelIds(array $ids): self
    {
        $this->setData(self::schema_fields_MEMBER_LEVEL_IDS, json_encode($ids, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * 获取地区ID列表
     * 
     * @return array
     */
    public function getRegionIds(): array
    {
        $ids = $this->getData(self::schema_fields_REGION_IDS);
        if (empty($ids)) {
            return [];
        }
        return json_decode($ids, true) ?: [];
    }
    /**
     * 设置地区ID列表
     * 
     * @param array $ids
     * @return $this
     */
    public function setRegionIds(array $ids): self
    {
        $this->setData(self::schema_fields_REGION_IDS, json_encode($ids, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * 获取优惠券代码列表
     * 
     * @return array
     */
    public function getCouponCodes(): array
    {
        $codes = $this->getData(self::schema_fields_COUPON_CODES);
        if (empty($codes)) {
            return [];
        }
        return json_decode($codes, true) ?: [];
    }
    /**
     * 设置优惠券代码列表
     * 
     * @param array $codes
     * @return $this
     */
    public function setCouponCodes(array $codes): self
    {
        $this->setData(self::schema_fields_COUPON_CODES, json_encode($codes, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * 获取混合条件配置
     * 
     * @return array
     */
    public function getMixedConfig(): array
    {
        $config = $this->getData(self::schema_fields_MIXED_CONFIG);
        if (empty($config)) {
            return [];
        }
        return json_decode($config, true) ?: [];
    }
    /**
     * 设置混合条件配置
     * 
     * @param array $config
     * @return $this
     */
    public function setMixedConfig(array $config): self
    {
        $this->setData(self::schema_fields_MIXED_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }
}
