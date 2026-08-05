<?php

declare(strict_types=1);

namespace Weline\Consent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Append-only Website-scoped consent audit')]
#[Index(name: 'idx_consent_audit_website_visitor', columns: ['website_id', 'visitor_key'])]
#[Index(name: 'idx_consent_audit_recorded', columns: ['recorded_at'])]
class ConsentAudit extends Model
{
    public const ACTION_GRANTED = 'granted';
    public const ACTION_WITHDRAWN = 'withdrawn';

    public const use_main_db_master = true;
    public const schema_table = 'consent_audit';
    public const schema_primary_key = 'audit_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Audit ID')]
    public const schema_fields_ID = 'audit_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (0=default site)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: 'Opaque server-issued visitor key')]
    public const schema_fields_VISITOR_KEY = 'visitor_key';

    #[Col('varchar', 64, nullable: false, comment: 'Category code')]
    public const schema_fields_CATEGORY_CODE = 'category_code';

    #[Col('varchar', 16, nullable: false, comment: 'granted|withdrawn')]
    public const schema_fields_ACTION = 'action';

    #[Col('datetime', nullable: false, comment: 'UTC audit time')]
    public const schema_fields_RECORDED_AT = 'recorded_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
