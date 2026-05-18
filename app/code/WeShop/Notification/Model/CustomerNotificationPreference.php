<?php

declare(strict_types=1);

namespace WeShop\Notification\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop customer notification topic preference')]
#[Index(name: 'idx_weshop_customer_notification_preference_customer_id', columns: ['customer_id'], comment: 'Customer index')]
#[Index(name: 'uk_weshop_customer_notification_preference_topic_channel', columns: ['customer_id', 'topic_code', 'channel_code'], type: 'UNIQUE', comment: 'Unique customer topic channel preference')]
class CustomerNotificationPreference extends Model
{
    public const schema_table = 'weshop_customer_notification_preference';
    public const schema_primary_key = 'preference_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Preference ID')]
    public const schema_fields_ID = 'preference_id';
    #[Col(type: 'int', nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: 'Topic code')]
    public const schema_fields_TOPIC_CODE = 'topic_code';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: 'Channel code')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'info', comment: 'Minimum notification type')]
    public const schema_fields_MIN_TYPE = 'min_type';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: 'Enabled flag')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['preference_id', 'customer_id', 'topic_code', 'channel_code'];
}
