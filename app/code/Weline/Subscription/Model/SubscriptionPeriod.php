<?php

declare(strict_types=1);

namespace Weline\Subscription\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable unique billing-period identity and state. */
#[Table(comment: 'Subscription period identity and state')]
#[Index(name: 'uk_subscription_period_key', columns: ['period_key'], type: 'UNIQUE')]
#[Index(
    name: 'uk_subscription_period_index',
    columns: ['subscription_id', 'period_index'],
    type: 'UNIQUE',
)]
#[Index(name: 'idx_subscription_period_scope_status', columns: ['website_id', 'status'])]
class SubscriptionPeriod extends Model
{
    public const schema_table = 'weline_subscription_period';
    public const schema_primary_key = 'period_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Period row ID')]
    public const schema_fields_ID = 'period_row_id';

    #[Col('varchar', 160, nullable: false, comment: 'Canonical period key')]
    public const schema_fields_PERIOD_KEY = 'period_key';

    #[Col('varchar', 64, nullable: false, comment: 'Subscription ID')]
    public const schema_fields_SUBSCRIPTION_ID = 'subscription_id';

    #[Col('bigint', 20, nullable: false, comment: 'One-based period index')]
    public const schema_fields_PERIOD_INDEX = 'period_index';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 16, nullable: false, default: 'open', comment: 'Period status')]
    public const schema_fields_STATUS = 'status';

    #[Col('bigint', 20, nullable: false, default: 1, comment: 'Monotonic period version')]
    public const schema_fields_VERSION = 'period_version';

    #[Col('varchar', 64, nullable: false, comment: 'Writer-owned CAS token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('varchar', 64, nullable: true, comment: 'One Order per period')]
    public const schema_fields_ORDER_REF = 'order_ref';

    #[Col('varchar', 255, nullable: true, comment: 'Last missed reason')]
    public const schema_fields_MISSED_REASON = 'missed_reason';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Opened')]
    public const schema_fields_OPENED_AT = 'opened_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        SubscriptionState::assertWebsiteId((int) $this->getData(self::schema_fields_WEBSITE_ID));
        SubscriptionState::assertPeriodStatus((string) $this->getData(self::schema_fields_STATUS));
        if (trim((string) $this->getData(self::schema_fields_PERIOD_KEY)) === ''
            || trim((string) $this->getData(self::schema_fields_SUBSCRIPTION_ID)) === ''
            || (int) $this->getData(self::schema_fields_PERIOD_INDEX) < 1
            || (int) $this->getData(self::schema_fields_VERSION) < 1
            || !preg_match('/^[a-f0-9]{64}$/', (string) $this->getData(self::schema_fields_CAS_TOKEN))
        ) {
            throw new \InvalidArgumentException(__('SubscriptionPeriod identity/version/CAS 非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
