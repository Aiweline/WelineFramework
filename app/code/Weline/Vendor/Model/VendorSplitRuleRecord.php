<?php

declare(strict_types=1);

namespace Weline\Vendor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Mutable Vendor commission rule; immutable copies belong to split snapshots. */
#[Table(comment: 'Vendor split rule')]
#[Index(name: 'uk_vendor_split_rule_scope', columns: ['vendor_id', 'website_id'], type: 'UNIQUE')]
#[Index(name: 'idx_vendor_split_rule_currency', columns: ['website_id', 'currency'])]
class VendorSplitRuleRecord extends Model
{
    public const schema_table = 'weline_vendor_split_rule';
    public const schema_primary_key = 'rule_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Rule ID')]
    public const schema_fields_ID = 'rule_id';

    #[Col('varchar', 64, nullable: false, comment: 'Vendor ID')]
    public const schema_fields_VENDOR_ID = 'vendor_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Platform commission basis points')]
    public const schema_fields_COMMISSION_BPS = 'commission_bps';

    #[Col('varchar', 8, nullable: false, comment: 'ISO-like uppercase currency')]
    public const schema_fields_CURRENCY = 'currency';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'Frozen legal entity source')]
    public const schema_fields_LEGAL_ENTITY = 'legal_entity';

    #[Col('bigint', 20, nullable: false, default: 1, comment: 'Monotonic rule version')]
    public const schema_fields_RULE_VERSION = 'rule_version';

    #[Col('varchar', 64, nullable: false, comment: 'CAS writer token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        VendorIdentity::assertWebsiteId((int) $this->getData(self::schema_fields_WEBSITE_ID));
        $bps = (int) $this->getData(self::schema_fields_COMMISSION_BPS);
        if ($bps < 0 || $bps > 10000) {
            throw new \InvalidArgumentException(__('commission_bps 必须在 0..10000'));
        }
        $this->setData(
            self::schema_fields_RULE_VERSION,
            max(1, (int) $this->getData(self::schema_fields_RULE_VERSION)),
        );
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
