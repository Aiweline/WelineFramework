<?php

declare(strict_types=1);

namespace Weline\Subscription\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Monotonic highest missed-period watermark per Subscription. */
#[Table(comment: 'Subscription missed period watermark')]
#[Index(name: 'uk_subscription_missed_watermark', columns: ['subscription_id'], type: 'UNIQUE')]
class SubscriptionMissedWatermark extends Model
{
    public const schema_table = 'weline_subscription_missed_watermark';
    public const schema_primary_key = 'watermark_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Watermark row ID')]
    public const schema_fields_ID = 'watermark_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Subscription ID')]
    public const schema_fields_SUBSCRIPTION_ID = 'subscription_id';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Highest missed period index')]
    public const schema_fields_PERIOD_INDEX = 'period_index';

    #[Col('varchar', 160, nullable: false, comment: 'Last missed period key')]
    public const schema_fields_PERIOD_KEY = 'period_key';

    #[Col('varchar', 255, nullable: false, comment: 'Last missed reason')]
    public const schema_fields_REASON = 'reason';

    #[Col('bigint', 20, nullable: false, default: 1, comment: 'Monotonic watermark version')]
    public const schema_fields_VERSION = 'watermark_version';

    #[Col('varchar', 64, nullable: false, comment: 'Writer-owned CAS token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        if (trim((string) $this->getData(self::schema_fields_SUBSCRIPTION_ID)) === ''
            || trim((string) $this->getData(self::schema_fields_PERIOD_KEY)) === ''
            || trim((string) $this->getData(self::schema_fields_REASON)) === ''
            || (int) $this->getData(self::schema_fields_PERIOD_INDEX) < 1
            || (int) $this->getData(self::schema_fields_VERSION) < 1
            || !preg_match('/^[a-f0-9]{64}$/', (string) $this->getData(self::schema_fields_CAS_TOKEN))
        ) {
            throw new \InvalidArgumentException(__('Subscription missed watermark 非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}

