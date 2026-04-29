<?php

declare(strict_types=1);

namespace Weline\Database\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\ModelInterface;

class DatabaseAdminAuditLog extends Model implements ModelInterface
{
    public const schema_table = 'weline_database_admin_audit_log';
    public const schema_primary_key = 'log_id';

    public const schema_fields_ID = 'log_id';
    public const schema_fields_ACTION = 'action';
    public const schema_fields_DATABASE = 'database_name';
    public const schema_fields_TABLE = 'table_name';
    public const schema_fields_SQL = 'sql_text';
    public const schema_fields_PAYLOAD = 'payload_json';
    public const schema_fields_AFFECTED_ROWS = 'affected_rows';
    public const schema_fields_STATUS = 'status';
    public const schema_fields_MESSAGE = 'message';
    public const schema_fields_USER_ID = 'user_id';
    public const schema_fields_USERNAME = 'username';
    public const schema_fields_CLIENT_IP = 'client_ip';
    public const schema_fields_CREATED_AT = 'created_at';
}
