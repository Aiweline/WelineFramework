<?php

declare(strict_types=1);

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable tombstone historical-resource authorization decision. */
#[Table(comment: 'Store tombstone historical resource audit')]
#[Index(name: 'uniq_historical_resource_decision', columns: ['decision_key'], type: 'UNIQUE')]
#[Index(name: 'idx_historical_resource_store', columns: ['store_id', 'created_at'])]
#[Index(name: 'idx_historical_resource_urgent', columns: ['urgent', 'created_at'])]
class HistoricalResourceAudit extends Model
{
    public const schema_table = 'weline_order_historical_resource_audit';
    public const schema_primary_key = 'historical_resource_audit_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Audit ID')]
    public const schema_fields_ID = 'historical_resource_audit_id';
    #[Col('char', 64, nullable: false, comment: 'Deterministic decision key')]
    public const schema_fields_DECISION_KEY = 'decision_key';
    #[Col('int', 11, nullable: false, comment: 'Store ID')]
    public const schema_fields_STORE_ID = 'store_id';
    #[Col('varchar', 64, nullable: false, comment: 'Store code snapshot')]
    public const schema_fields_STORE_CODE = 'store_code';
    #[Col('varchar', 48, nullable: false, comment: 'Requested action')]
    public const schema_fields_ACTION = 'action';
    #[Col('varchar', 191, nullable: false, comment: 'Correlation key')]
    public const schema_fields_CORRELATION_KEY = 'correlation_key';
    #[Col('smallint', 1, nullable: false, default: 0, comment: 'Allowed flag')]
    public const schema_fields_ALLOWED = 'allowed';
    #[Col('varchar', 32, nullable: false, comment: 'normal|historical_only')]
    public const schema_fields_RESOURCE_MODE = 'resource_mode';
    #[Col('smallint', 1, nullable: false, default: 0, comment: 'Urgent review required')]
    public const schema_fields_URGENT = 'urgent';
    #[Col('varchar', 96, nullable: true, comment: 'Decision error code')]
    public const schema_fields_ERROR_CODE = 'error_code';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['historical_resource_audit_id'];
    public array $_index_sort_keys = ['decision_key', 'store_id', 'action', 'urgent', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
