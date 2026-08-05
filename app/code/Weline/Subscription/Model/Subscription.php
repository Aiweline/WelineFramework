<?php

declare(strict_types=1);

namespace Weline\Subscription\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable Customer-owned Subscription aggregate identity and CAS state. */
#[Table(comment: 'Subscription aggregate identity and state')]
#[Index(name: 'uk_subscription_id', columns: ['subscription_id'], type: 'UNIQUE')]
#[Index(name: 'uk_subscription_idempotency', columns: ['idempotency_key'], type: 'UNIQUE')]
#[Index(
    name: 'uk_subscription_owner_plan',
    columns: ['customer_id', 'website_id', 'plan_code'],
    type: 'UNIQUE',
)]
#[Index(name: 'idx_subscription_scope_status', columns: ['website_id', 'status'])]
class Subscription extends Model
{
    public const schema_table = 'weline_subscription';
    public const schema_primary_key = 'subscription_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Subscription row ID')]
    public const schema_fields_ID = 'subscription_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable Subscription ID')]
    public const schema_fields_SUBSCRIPTION_ID = 'subscription_id';

    #[Col('varchar', 64, nullable: false, comment: 'Owning Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Frozen Store ID including 0')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('varchar', 64, nullable: false, comment: 'Subscription Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col('varchar', 128, nullable: false, comment: 'Immutable plan code')]
    public const schema_fields_PLAN_CODE = 'plan_code';

    #[Col('varchar', 16, nullable: false, default: 'sandbox', comment: 'sandbox|live')]
    public const schema_fields_ENVIRONMENT = 'environment';

    #[Col('varchar', 16, nullable: false, default: 'active', comment: 'Subscription status')]
    public const schema_fields_STATUS = 'status';

    #[Col('bigint', 20, nullable: false, default: 1, comment: 'Monotonic aggregate version')]
    public const schema_fields_VERSION = 'version';

    #[Col('varchar', 64, nullable: false, comment: 'Writer-owned CAS token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Current due period index')]
    public const schema_fields_CURRENT_PERIOD_INDEX = 'current_period_index';

    #[Col('varchar', 128, nullable: false, comment: 'Create idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col('varchar', 64, nullable: false, comment: 'Canonical create request SHA-256')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    #[Col('datetime', nullable: true, comment: 'Cancelled')]
    public const schema_fields_CANCELLED_AT = 'cancelled_at';

    public function save_before(): void
    {
        SubscriptionState::assertWebsiteId((int) $this->getData(self::schema_fields_WEBSITE_ID));
        if ((int) $this->getData(self::schema_fields_STORE_ID) < 0) {
            throw new \InvalidArgumentException(__('store_id 不能为负数：%{1}', [
                $this->getData(self::schema_fields_STORE_ID),
            ]));
        }
        SubscriptionState::assertStatus((string) $this->getData(self::schema_fields_STATUS));
        SubscriptionState::assertEnvironment((string) $this->getData(self::schema_fields_ENVIRONMENT));
        foreach ([
            self::schema_fields_SUBSCRIPTION_ID,
            self::schema_fields_CUSTOMER_ID,
            self::schema_fields_PROVIDER_CODE,
            self::schema_fields_PLAN_CODE,
            self::schema_fields_IDEMPOTENCY_KEY,
        ] as $field) {
            if (trim((string) $this->getData($field)) === '') {
                throw new \InvalidArgumentException(__('Subscription 必填字段为空：%{1}', [$field]));
            }
        }
        if ((int) $this->getData(self::schema_fields_VERSION) < 1
            || (int) $this->getData(self::schema_fields_CURRENT_PERIOD_INDEX) < 0
            || !preg_match('/^[a-f0-9]{64}$/', (string) $this->getData(self::schema_fields_CAS_TOKEN))
            || !preg_match('/^[a-f0-9]{64}$/', (string) $this->getData(self::schema_fields_REQUEST_HASH))
        ) {
            throw new \InvalidArgumentException(__('Subscription version/CAS/hash 非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
