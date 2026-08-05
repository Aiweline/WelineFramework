<?php

declare(strict_types=1);

namespace Weline\Tax\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Tax class（P3B-001；additive）.
 */
#[Table(comment: 'Tax class')]
#[Index(name: 'uk_tax_class_code', columns: ['website_id', 'class_code'], type: 'UNIQUE')]
class TaxClass extends Model
{
    public const schema_table = 'weline_tax_class';
    public const schema_primary_key = 'tax_class_id';
    public const CLASS_CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/D';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Tax class ID')]
    public const schema_fields_ID = 'tax_class_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (>=0)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: 'Class code')]
    public const schema_fields_CLASS_CODE = 'class_code';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'Display name')]
    public const schema_fields_NAME = 'name';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
