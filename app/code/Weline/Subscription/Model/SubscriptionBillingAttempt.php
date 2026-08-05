<?php

declare(strict_types=1);

namespace Weline\Subscription\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable Subscription billing Attempt journal. */
#[Table(comment: 'Subscription billing attempt journal')]
#[Index(name: 'uk_subscription_billing_attempt_id', columns: ['attempt_id'], type: 'UNIQUE')]
#[Index(
    name: 'uk_subscription_billing_attempt_no',
    columns: ['period_key', 'attempt_no'],
    type: 'UNIQUE',
)]
#[Index(
    name: 'uk_subscription_billing_attempt_active',
    columns: ['period_key', 'active_guard'],
    type: 'UNIQUE',
)]
#[Index(name: 'idx_subscription_billing_attempt_subscription', columns: ['subscription_id', 'status'])]
class SubscriptionBillingAttempt extends Model
{
    public const schema_table = 'weline_subscription_billing_attempt';
    public const schema_primary_key = 'attempt_row_id';

    public const ACTIVE_GUARD = 'active';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Attempt row ID')]
    public const schema_fields_ID = 'attempt_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable Attempt ID')]
    public const schema_fields_ATTEMPT_ID = 'attempt_id';

    #[Col('varchar', 160, nullable: false, comment: 'Subscription period key')]
    public const schema_fields_PERIOD_KEY = 'period_key';

    #[Col('varchar', 64, nullable: false, comment: 'Subscription ID')]
    public const schema_fields_SUBSCRIPTION_ID = 'subscription_id';

    #[Col('bigint', 20, nullable: false, comment: 'Attempt ordinal within period')]
    public const schema_fields_ATTEMPT_NO = 'attempt_no';

    #[Col('varchar', 128, nullable: false, comment: 'Worker identity')]
    public const schema_fields_WORKER_ID = 'worker_id';

    #[Col('varchar', 16, nullable: false, default: 'pending', comment: 'pending|unknown|succeeded|failed')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 16, nullable: true, comment: 'Unique active guard while pending/unknown')]
    public const schema_fields_ACTIVE_GUARD = 'active_guard';

    #[Col('varchar', 64, nullable: true, comment: 'Order UUID')]
    public const schema_fields_ORDER_REF = 'order_ref';

    #[Col('varchar', 64, nullable: true, comment: 'Payment Intent code')]
    public const schema_fields_PAYMENT_INTENT_CODE = 'payment_intent_code';

    #[Col('varchar', 64, nullable: true, comment: 'Payment Attempt code')]
    public const schema_fields_PAYMENT_ATTEMPT_CODE = 'payment_attempt_code';

    #[Col('varchar', 32, nullable: true, comment: 'Sanitized Payment status')]
    public const schema_fields_PAYMENT_STATUS = 'payment_status';

    #[Col('varchar', 128, nullable: true, comment: 'Sanitized error code')]
    public const schema_fields_ERROR_CODE = 'error_code';

    #[Col('bigint', 20, nullable: false, default: 1, comment: 'Monotonic Attempt version')]
    public const schema_fields_VERSION = 'attempt_version';

    #[Col('varchar', 64, nullable: false, comment: 'Writer-owned CAS token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Started')]
    public const schema_fields_STARTED_AT = 'started_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    #[Col('datetime', nullable: true, comment: 'Terminal time')]
    public const schema_fields_FINISHED_AT = 'finished_at';

    public function save_before(): void
    {
        $status = (string) $this->getData(self::schema_fields_STATUS);
        if (trim((string) $this->getData(self::schema_fields_ATTEMPT_ID)) === ''
            || trim((string) $this->getData(self::schema_fields_PERIOD_KEY)) === ''
            || trim((string) $this->getData(self::schema_fields_SUBSCRIPTION_ID)) === ''
            || trim((string) $this->getData(self::schema_fields_WORKER_ID)) === ''
            || !\in_array($status, ['pending', 'unknown', 'succeeded', 'failed'], true)
            || (int) $this->getData(self::schema_fields_ATTEMPT_NO) < 1
            || (int) $this->getData(self::schema_fields_VERSION) < 1
            || !preg_match('/^[a-f0-9]{64}$/', (string) $this->getData(self::schema_fields_CAS_TOKEN))
        ) {
            throw new \InvalidArgumentException(__('Subscription Billing Attempt 非法'));
        }
        $guard = $this->getData(self::schema_fields_ACTIVE_GUARD);
        if (\in_array($status, ['pending', 'unknown'], true)) {
            if ($guard !== self::ACTIVE_GUARD) {
                throw new \InvalidArgumentException(__('Subscription Billing Attempt active guard 非法'));
            }
        } elseif ($guard !== null && $guard !== '') {
            throw new \InvalidArgumentException(__('Subscription Billing Attempt terminal guard 非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}

