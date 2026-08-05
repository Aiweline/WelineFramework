<?php

declare(strict_types=1);

namespace Weline\Tax\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Tax rule（P3B-001；additive）.
 * jurisdiction_key = country|region；rate_bps = basis points（10000=100%）.
 */
#[Table(comment: 'Tax rule')]
#[Index(name: 'uk_tax_rule_identity', columns: ['website_id', 'class_code', 'jurisdiction_key', 'rule_version'], type: 'UNIQUE')]
#[Index(name: 'idx_tax_rule_lookup', columns: ['website_id', 'class_code', 'jurisdiction_key', 'enabled'])]
class TaxRule extends Model
{
    public const schema_table = 'weline_tax_rule';
    public const schema_primary_key = 'tax_rule_id';

    public const ROUNDING_HALF_UP = 'half_up';
    public const ROUNDING_MODES = [self::ROUNDING_HALF_UP];
    public const JURISDICTION_PATTERN = '/^[A-Z]{2}\|[A-Z0-9_-]{0,32}$/D';
    public const RATE_BPS_MIN = 0;
    public const RATE_BPS_MAX = 10000;

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Tax rule ID')]
    public const schema_fields_ID = 'tax_rule_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (>=0)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: 'Tax class code')]
    public const schema_fields_CLASS_CODE = 'class_code';

    #[Col('varchar', 64, nullable: false, comment: 'Jurisdiction key country|region')]
    public const schema_fields_JURISDICTION_KEY = 'jurisdiction_key';

    #[Col('int', 11, nullable: false, comment: 'Rate in basis points')]
    public const schema_fields_RATE_BPS = 'rate_bps';

    #[Col('int', 11, nullable: false, default: 1, comment: 'Rule version')]
    public const schema_fields_RULE_VERSION = 'rule_version';

    #[Col('varchar', 16, nullable: false, default: 'half_up', comment: 'Rounding mode')]
    public const schema_fields_ROUNDING = 'rounding';

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
