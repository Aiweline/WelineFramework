<?php

declare(strict_types=1);

namespace Weline\Promotion\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 促销活动运行态 @package Weline_Promotion */
#[Table(comment: '促销活动运行态表')]
#[Index(name: 'idx_campaign_key', columns: ['campaign_key'], type: 'UNIQUE')]
#[Index(name: 'idx_status_updated', columns: ['status', 'updated_at'])]
class PromotionCampaignRun extends Model
{
    public const schema_table = 'weline_promotion_campaign_run';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'campaign_key', 'status'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '活动键')]
    public const schema_fields_CAMPAIGN_KEY = 'campaign_key';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'review', comment: '运行状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'text', nullable: true, comment: '交接 JSON')]
    public const schema_fields_HANDOFF_JSON = 'handoff_json';
    #[Col(type: 'int', nullable: true, comment: '操作人 ID')]
    public const schema_fields_OPERATOR_ID = 'operator_id';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_CONTINUE = 'continue';
    public const STATUS_PAUSE = 'pause';
    public const STATUS_REPAIR = 'repair';
    public const STATUS_REVIEW = 'review';

    /** @return list<string> */
    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_CONTINUE,
            self::STATUS_PAUSE,
            self::STATUS_REPAIR,
            self::STATUS_REVIEW,
        ];
    }

    /** @return array<string, mixed>|null */
    public function getHandoffPayload(): ?array
    {
        $raw = $this->getData(self::schema_fields_HANDOFF_JSON);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed>|null $payload */
    public function setHandoffPayload(?array $payload): self
    {
        $this->setData(
            self::schema_fields_HANDOFF_JSON,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        );

        return $this;
    }
}
