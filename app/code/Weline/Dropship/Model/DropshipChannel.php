<?php

declare(strict_types=1);

namespace Weline\Dropship\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Dropship registered provider channel')]
#[Index(name: 'uk_dropship_channel_code', columns: ['code'], type: 'UNIQUE')]
class DropshipChannel extends Model
{
    public const schema_table = 'weline_dropship_channel';
    public const schema_primary_key = 'channel_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'channel_id';

    #[Col('varchar', 64, nullable: false, comment: 'Provider code / source marker')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'Display name')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'Provider class')]
    public const schema_fields_PROVIDER_CLASS = 'provider_class';

    #[Col('varchar', 128, nullable: false, default: '', comment: 'Source module')]
    public const schema_fields_PROVIDER_MODULE = 'provider_module';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('int', 11, nullable: false, default: 100, comment: 'Sort')]
    public const schema_fields_SORT_ORDER = 'sort_order';

    #[Col('text', nullable: true, comment: 'Capabilities JSON')]
    public const schema_fields_CAPABILITIES_JSON = 'capabilities_json';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
