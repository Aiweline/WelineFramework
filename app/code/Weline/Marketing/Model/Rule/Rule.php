<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Marketing\Model\Rule;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 营销规则模型 @package Weline_Marketing */
#[Table(comment: '营销规则表')]
#[Index(name: 'idx_status', columns: ['status', 'start_date', 'end_date'])]
#[Index(name: 'idx_type', columns: ['rule_type', 'status'])]
#[Index(name: 'idx_priority', columns: ['priority'])]
class Rule extends Model
{
    public const schema_table = 'weline_marketing_rule';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'priority', 'status', 'rule_type'];
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '规则ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 255, nullable: false, comment: '规则名称')]
    public const schema_fields_NAME = 'name';
    #[Col('text', comment: '规则描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('varchar', 50, nullable: false, comment: '规则类型')]
    public const schema_fields_RULE_TYPE = 'rule_type';
    #[Col('varchar', 20, nullable: false, default: 'inactive', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', default: 0, comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('datetime', comment: '开始时间')]
    public const schema_fields_START_DATE = 'start_date';
    #[Col('datetime', comment: '结束时间')]
    public const schema_fields_END_DATE = 'end_date';
    #[Col('text', comment: '条件序列化')]
    public const schema_fields_CONDITIONS_SERIALIZED = 'conditions_serialized';
    #[Col('text', comment: '动作序列化')]
    public const schema_fields_ACTIONS_SERIALIZED = 'actions_serialized';
    #[Col('int', comment: '总使用次数限制')]
    public const schema_fields_USAGE_LIMIT = 'usage_limit';
    #[Col('int', default: 0, comment: '已使用次数')]
    public const schema_fields_USAGE_COUNT = 'usage_count';
    #[Col('int', comment: '每个客户使用次数限制')]
    public const schema_fields_CUSTOMER_LIMIT = 'customer_limit';
    #[Col('smallint', default: 0, comment: '是否停止后续规则处理')]
    public const schema_fields_IS_STOP_PROCESSING = 'is_stop_processing';
    #[Col('int', default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    /**
     * Rule type constants
     */
    public const RULE_TYPE_COUPON = 'coupon';
    public const RULE_TYPE_CAMPAIGN = 'campaign';
    public const RULE_TYPE_AUTOMATIC = 'automatic';
    /**
     * Status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_EXPIRED = 'expired';
    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->_table = 'weline_marketing_rule';
        $this->_id_field_name = 'id';
    }
/**
     * Get conditions as array
     *
     * @return array|null
     */
    public function getConditions(): ?array
    {
        $conditions = $this->getData(self::schema_fields_CONDITIONS_SERIALIZED);
        if (empty($conditions)) {
            return null;
        }
        return json_decode($conditions, true);
    }
    /**
     * Set conditions from array
     *
     * @param array|null $conditions
     * @return $this
     */
    public function setConditions(?array $conditions): self
    {
        $this->setData(
            self::schema_fields_CONDITIONS_SERIALIZED,
            $conditions ? json_encode($conditions, JSON_UNESCAPED_UNICODE) : null
        );
        return $this;
    }
    /**
     * Get actions as array
     *
     * @return array|null
     */
    public function getActions(): ?array
    {
        $actions = $this->getData(self::schema_fields_ACTIONS_SERIALIZED);
        if (empty($actions)) {
            return null;
        }
        return json_decode($actions, true);
    }
    /**
     * Set actions from array
     *
     * @param array|null $actions
     * @return $this
     */
    public function setActions(?array $actions): self
    {
        $this->setData(
            self::schema_fields_ACTIONS_SERIALIZED,
            $actions ? json_encode($actions, JSON_UNESCAPED_UNICODE) : null
        );
        return $this;
    }
    /**
     * Check if rule is active
     *
     * @return bool
     */
    public function isActive(): bool
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
        return true;
    }
}
