<?php

declare(strict_types=1);

namespace WeShop\Notification\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop customer notification contact channel configuration')]
#[Index(name: 'idx_weshop_customer_notification_contact_customer_id', columns: ['customer_id'], comment: 'Customer index')]
#[Index(name: 'idx_weshop_customer_notification_contact_customer_channel', columns: ['customer_id', 'channel_code'], comment: 'Customer channel index')]
#[Index(name: 'uk_weshop_customer_notification_contact_value', columns: ['customer_id', 'channel_code', 'contact_value'], type: 'UNIQUE', comment: 'Unique customer channel contact')]
class CustomerNotificationContact extends Model
{
    public const schema_table = 'weshop_customer_notification_contact';
    public const schema_primary_key = 'contact_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Contact ID')]
    public const schema_fields_ID = 'contact_id';
    #[Col(type: 'int', nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: 'Notification channel code')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Contact value')]
    public const schema_fields_CONTACT_VALUE = 'contact_value';
    #[Col(type: 'varchar', length: 100, nullable: true, default: '', comment: 'Contact display name')]
    public const schema_fields_CONTACT_NAME = 'contact_name';
    #[Col(type: 'text', nullable: true, comment: 'Channel configuration JSON')]
    public const schema_fields_CHANNEL_CONFIG = 'channel_config';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Default contact flag')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Verified flag')]
    public const schema_fields_IS_VERIFIED = 'is_verified';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: 'Enabled flag')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['contact_id', 'customer_id', 'channel_code'];

    public function getChannelConfig(): array
    {
        $value = $this->getData(self::schema_fields_CHANNEL_CONFIG);
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setChannelConfig(array $config): static
    {
        return $this->setData(self::schema_fields_CHANNEL_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
}
