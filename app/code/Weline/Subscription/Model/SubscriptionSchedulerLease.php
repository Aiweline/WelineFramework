<?php

declare(strict_types=1);

namespace Weline\Subscription\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable, fenced scheduler lease owned by one worker at a time. */
#[Table(comment: 'Subscription scheduler lease')]
#[Index(name: 'uk_subscription_scheduler_lease', columns: ['subscription_id'], type: 'UNIQUE')]
#[Index(name: 'idx_subscription_scheduler_lease_expiry', columns: ['expires_at_epoch'])]
class SubscriptionSchedulerLease extends Model
{
    public const schema_table = 'weline_subscription_scheduler_lease';
    public const schema_primary_key = 'lease_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Lease row ID')]
    public const schema_fields_ID = 'lease_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Subscription ID')]
    public const schema_fields_SUBSCRIPTION_ID = 'subscription_id';

    #[Col('varchar', 128, nullable: false, comment: 'Worker identity')]
    public const schema_fields_WORKER_ID = 'worker_id';

    #[Col('varchar', 64, nullable: false, comment: 'Lease fencing token')]
    public const schema_fields_TOKEN = 'lease_token';

    #[Col('bigint', 20, nullable: false, default: 1, comment: 'Monotonic lease version')]
    public const schema_fields_VERSION = 'lease_version';

    #[Col('bigint', 20, nullable: false, comment: 'UTC epoch expiry')]
    public const schema_fields_EXPIRES_AT_EPOCH = 'expires_at_epoch';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        if (trim((string) $this->getData(self::schema_fields_SUBSCRIPTION_ID)) === ''
            || trim((string) $this->getData(self::schema_fields_WORKER_ID)) === ''
            || !preg_match('/^[a-f0-9]{64}$/', (string) $this->getData(self::schema_fields_TOKEN))
            || (int) $this->getData(self::schema_fields_VERSION) < 1
            || (int) $this->getData(self::schema_fields_EXPIRES_AT_EPOCH) < 1
        ) {
            throw new \InvalidArgumentException(__('Subscription scheduler lease 非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}

