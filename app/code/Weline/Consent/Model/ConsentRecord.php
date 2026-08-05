<?php

declare(strict_types=1);

namespace Weline\Consent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Website-scoped consent records')]
#[Index(name: 'idx_consent_record_lookup', columns: ['website_id', 'visitor_key', 'category_code'], type: 'UNIQUE')]
#[Index(name: 'idx_consent_record_website', columns: ['website_id', 'status'])]
class ConsentRecord extends Model
{
    public const use_main_db_master = true;
    public const schema_table = 'consent_record';
    public const schema_primary_key = 'record_id';

    public const STATUS_GRANTED = 'granted';
    public const STATUS_WITHDRAWN = 'withdrawn';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Record ID')]
    public const schema_fields_ID = 'record_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (0=default site)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: 'Visitor key')]
    public const schema_fields_VISITOR_KEY = 'visitor_key';

    #[Col('varchar', 64, nullable: false, comment: 'Category code')]
    public const schema_fields_CATEGORY_CODE = 'category_code';

    #[Col('varchar', 32, nullable: false, default: self::STATUS_GRANTED, comment: 'granted|withdrawn')]
    public const schema_fields_STATUS = 'status';

    #[Col('datetime', nullable: false, comment: 'Granted at')]
    public const schema_fields_GRANTED_AT = 'granted_at';

    #[Col('datetime', nullable: true, comment: 'Withdrawn at')]
    public const schema_fields_WITHDRAWN_AT = 'withdrawn_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
