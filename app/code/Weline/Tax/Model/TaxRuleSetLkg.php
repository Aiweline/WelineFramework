<?php

declare(strict_types=1);

namespace Weline\Tax\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Verified, replayable Tax rule-set snapshot.
 *
 * A row contains rules and configuration, never a quote result.
 */
#[Table(comment: 'Verified Tax rule-set LKG')]
#[Index(
    name: 'uk_tax_rule_set_lkg_identity',
    columns: ['scope_key', 'schema_version', 'rule_set_hash'],
    type: 'UNIQUE',
)]
#[Index(name: 'idx_tax_rule_set_lkg_scope', columns: ['website_id', 'store_id', 'verified'])]
class TaxRuleSetLkg extends Model
{
    public const schema_table = 'weline_tax_rule_set_lkg';
    public const schema_primary_key = 'tax_rule_set_lkg_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'LKG ID')]
    public const schema_fields_ID = 'tax_rule_set_lkg_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (0 is valid)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Store ID (0 is valid)')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('varchar', 255, nullable: false, comment: 'Canonical typed Scope identity')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col('varchar', 64, nullable: false, comment: 'Tax schema version')]
    public const schema_fields_SCHEMA_VERSION = 'schema_version';

    #[Col('varchar', 64, nullable: false, comment: 'Canonical rule-set SHA-256')]
    public const schema_fields_RULE_SET_HASH = 'rule_set_hash';

    #[Col('text', nullable: false, comment: 'Canonical replayable rule-set JSON')]
    public const schema_fields_SNAPSHOT_JSON = 'snapshot_json';

    #[Col('varchar', 64, nullable: false, comment: 'Observation request-set SHA-256')]
    public const schema_fields_REQUEST_SET_HASH = 'request_set_hash';

    #[Col('varchar', 64, nullable: false, comment: 'Shadow report SHA-256')]
    public const schema_fields_REPORT_HASH = 'report_hash';

    #[Col('int', 11, nullable: false, comment: 'Unique quote count in observation window')]
    public const schema_fields_SAMPLE_COUNT = 'sample_count';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Verified flag')]
    public const schema_fields_VERIFIED = 'verified';

    #[Col('datetime', nullable: true, comment: 'Verification timestamp')]
    public const schema_fields_VERIFIED_AT = 'verified_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
