<?php

declare(strict_types=1);

namespace Weline\Dropship\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Fake 货源远程仓表（Provider 私有；演示拉取/列表）。
 */
#[Table(comment: 'Dropship fake provider warehouse')]
#[Index(name: 'uk_ds_fake_wh_ext', columns: ['external_id'], type: 'UNIQUE')]
class FakeProviderWarehouse extends Model
{
    public const schema_table = 'weline_dropship_fake_warehouse';
    public const schema_primary_key = 'id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';

    #[Col('varchar', 64, nullable: false, comment: 'External warehouse / storage id')]
    public const schema_fields_EXTERNAL_ID = 'external_id';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'Display name')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 8, nullable: false, default: '', comment: 'Country code')]
    public const schema_fields_COUNTRY_CODE = 'country_code';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
