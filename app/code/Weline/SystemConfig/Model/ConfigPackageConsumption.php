<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 配置包 UUID 一次性消费账本（TASK-P1D-003 / DEC-021）。
 */
#[Table(comment: 'Config package AEAD consumption ledger')]
#[Index(name: 'idx_config_pkg_uuid', columns: ['package_uuid'], type: 'UNIQUE')]
#[Index(name: 'idx_config_pkg_scope', columns: ['scope_key', 'consumed_at'])]
class ConfigPackageConsumption extends Model
{
    public const schema_table = 'system_config_package_consumption';
    public const schema_primary_key = 'consumption_id';

    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Consumption ID')]
    public const schema_fields_ID = 'consumption_id';

    #[Col('varchar', 36, nullable: false, unique: true, comment: 'Package UUID')]
    public const schema_fields_PACKAGE_UUID = 'package_uuid';

    #[Col('varchar', 64, nullable: false, comment: 'Recipient key id')]
    public const schema_fields_RECIPIENT_KID = 'recipient_kid';

    #[Col('varchar', 191, nullable: false, comment: 'Canonical scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col('varchar', 120, nullable: true, comment: 'Source instance id (audit only)')]
    public const schema_fields_SOURCE_INSTANCE = 'source_instance';

    #[Col('varchar', 255, nullable: true, comment: 'Original filename bound in AAD')]
    public const schema_fields_FILENAME = 'filename';

    #[Col('varchar', 32, nullable: false, default: self::STATUS_CLAIMED, comment: 'claimed|applied|failed')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 64, nullable: true, comment: 'Ciphertext sha256 for audit')]
    public const schema_fields_PAYLOAD_HASH = 'payload_hash';

    #[Col('datetime', nullable: false, comment: 'Consumed at')]
    public const schema_fields_CONSUMED_AT = 'consumed_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
