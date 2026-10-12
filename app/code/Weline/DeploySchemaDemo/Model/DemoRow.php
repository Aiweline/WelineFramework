<?php

declare(strict_types=1);

namespace Weline\DeploySchemaDemo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * SchemaDiff 列备份/恢复 UC 夹具表。禁止站柜种子。
 */
#[Table(comment: 'Deploy schema demo row (UC fixture)')]
class DemoRow extends Model
{
    public const schema_table = 'weline_deploy_schema_demo_row';
    public const schema_primary_key = 'row_id';

    public array $_unit_primary_keys = ['row_id'];
    public array $_index_sort_keys = ['row_id', 'code'];

    #[Col(type: 'integer', nullable: false, primaryKey: true, autoIncrement: true, comment: 'Row ID')]
    public const schema_fields_ROW_ID = 'row_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Stable UC code')]
    public const schema_fields_CODE = 'code';

    #[Col(type: 'varchar', length: 255, nullable: true, default: null, comment: 'UC sample payload column')]
    public const schema_fields_SAMPLE_PAYLOAD = 'sample_payload';
}
