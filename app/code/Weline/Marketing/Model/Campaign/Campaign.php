<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Campaign;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 促销活动模型 @package Weline_Marketing */
#[Table(comment: '促销活动表')]
#[Index(name: 'idx_status', columns: ['status', 'start_date', 'end_date'])]
#[Index(name: 'idx_rule', columns: ['rule_id'])]
class Campaign extends Model
{
    public const schema_table = 'weline_marketing_campaign';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'rule_id', 'status'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '活动ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '活动名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'text', nullable: true, comment: '活动描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'int', nullable: false, comment: '关联规则ID')]
    public const schema_fields_RULE_ID = 'rule_id';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'draft', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: false, comment: '开始时间')]
    public const schema_fields_START_DATE = 'start_date';
    #[Col(type: 'datetime', nullable: false, comment: '结束时间')]
    public const schema_fields_END_DATE = 'end_date';
    #[Col(type: 'decimal', length: '10,2', nullable: true, comment: '预算')]
    public const schema_fields_BUDGET = 'budget';
    #[Col(type: 'decimal', length: '10,2', default: 0, comment: '已花费')]
    public const schema_fields_SPENT = 'spent';
    #[Col(type: 'text', nullable: true, comment: '目标受众JSON')]
    public const schema_fields_TARGET_AUDIENCE = 'target_audience';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Get target audience as array
     */
    public function getTargetAudience(): ?array
    {
        $audience = $this->getData(self::schema_fields_TARGET_AUDIENCE);
        if (empty($audience)) {
            return null;
        }
        return json_decode($audience, true);
    }

    /**
     * Set target audience from array
     */
    public function setTargetAudience(?array $audience): self
    {
        $this->setData(
            self::schema_fields_TARGET_AUDIENCE,
            $audience ? json_encode($audience, JSON_UNESCAPED_UNICODE) : null
        );
        return $this;
    }
}
