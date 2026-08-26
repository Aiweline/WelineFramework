<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Product identity V2 cutover state')]
#[Index(name: 'uk_product_identity_cutover_key', columns: ['state_key'], type: 'UNIQUE')]
final class ProductIdentityCutoverState extends Model
{
    public const schema_table = 'weline_product_identity_cutover_state';
    public const schema_primary_key = 'state_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'State ID')]
    public const schema_fields_ID = 'state_id';

    #[Col('varchar', 32, nullable: false, unique: true, comment: 'Singleton state key')]
    public const schema_fields_STATE_KEY = 'state_key';

    #[Col('varchar', 32, nullable: false, comment: 'legacy, dual_read or v2_authoritative')]
    public const schema_fields_MODE = 'mode';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Optimistic state version')]
    public const schema_fields_VERSION = 'version';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Current active legacy identity digest')]
    public const schema_fields_SOURCE_DIGEST = 'source_digest';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Last successful verification digest')]
    public const schema_fields_VERIFIED_DIGEST = 'verified_digest';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Verified active legacy identity count')]
    public const schema_fields_VERIFIED_COUNT = 'verified_count';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Last verification error count')]
    public const schema_fields_VERIFICATION_ERROR_COUNT = 'verification_error_count';

    #[Col('datetime', nullable: true, comment: 'Last successful verification time')]
    public const schema_fields_VERIFIED_AT = 'verified_at';

    #[Col('datetime', nullable: true, comment: 'Last authoritative cutover time')]
    public const schema_fields_SWITCHED_AT = 'switched_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
