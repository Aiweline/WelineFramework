<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Query;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Frontend Worker credential capacity guards')]
#[Index(name: 'uk_worker_credential_guard_bucket', columns: ['bucket_key'], type: 'UNIQUE')]
final class FrontendWorkerCredentialGuard extends Model
{
    public const use_main_db_master = true;
    public const schema_table = 'weline_frontend_worker_credential_guard';
    public const schema_primary_key = 'id';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Internal ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Capacity bucket')]
    public const schema_fields_BUCKET_KEY = 'bucket_key';
    #[Col(type: 'bigint', length: 20, nullable: false, comment: 'Creation Unix timestamp')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_BUCKET_KEY];
}
