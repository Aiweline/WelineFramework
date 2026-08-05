<?php

declare(strict_types=1);

namespace Weline\Consent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Consent categories')]
#[Index(name: 'idx_consent_category_code', columns: ['code'], type: 'UNIQUE')]
class ConsentCategory extends Model
{
    public const use_main_db_master = true;
    public const schema_table = 'consent_category';
    public const schema_primary_key = 'category_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Category ID')]
    public const schema_fields_ID = 'category_id';

    #[Col('varchar', 64, nullable: false, unique: true, comment: 'Category code')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 128, nullable: false, comment: 'Display name')]
    public const schema_fields_NAME = 'name';

    #[Col('int', 1, nullable: false, default: 0, comment: 'Required (cannot withdraw)')]
    public const schema_fields_REQUIRED = 'is_required';

    #[Col('int', 1, nullable: false, default: 1, comment: 'Active')]
    public const schema_fields_ACTIVE = 'is_active';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
