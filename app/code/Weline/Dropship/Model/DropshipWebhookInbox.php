<?php

declare(strict_types=1);

namespace Weline\Dropship\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Dropship webhook inbox')]
#[Index(name: 'uk_dropship_webhook_endpoint_ext', columns: ['endpoint_code', 'external_event_id'], type: 'UNIQUE')]
class DropshipWebhookInbox extends Model
{
    public const schema_table = 'weline_dropship_webhook_inbox';
    public const schema_primary_key = 'inbox_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'inbox_id';

    #[Col('varchar', 128, nullable: false, comment: 'endpoint_code')]
    public const schema_fields_ENDPOINT_CODE = 'endpoint_code';

    #[Col('varchar', 64, nullable: false, comment: 'Provider')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col('varchar', 191, nullable: false, default: '', comment: 'External event id')]
    public const schema_fields_EXTERNAL_EVENT_ID = 'external_event_id';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Event type')]
    public const schema_fields_EVENT_TYPE = 'event_type';

    #[Col('text', nullable: true, comment: 'Body')]
    public const schema_fields_BODY = 'body';

    #[Col('varchar', 32, nullable: false, default: 'pending', comment: 'Status')]
    public const schema_fields_STATUS = 'status';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
