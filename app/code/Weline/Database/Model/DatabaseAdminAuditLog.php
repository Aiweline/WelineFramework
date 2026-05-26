<?php

declare(strict_types=1);

namespace Weline\Database\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\ModelInterface;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Database admin audit logs')]
#[Index(name: 'idx_database_admin_audit_action', columns: ['action'], type: 'KEY', comment: 'Action lookup')]
#[Index(name: 'idx_database_admin_audit_created_at', columns: ['created_at'], type: 'KEY', comment: 'Created time lookup')]
class DatabaseAdminAuditLog extends Model implements ModelInterface
{
    public const schema_table = 'weline_database_admin_audit_log';
    public const schema_primary_key = 'log_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Log ID')]
    public const schema_fields_ID = 'log_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Action')]
    public const schema_fields_ACTION = 'action';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Database name')]
    public const schema_fields_DATABASE = 'database_name';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Table name')]
    public const schema_fields_TABLE = 'table_name';
    #[Col(type: 'text', nullable: true, comment: 'SQL text or SQL hash')]
    public const schema_fields_SQL = 'sql_text';
    #[Col(type: 'text', nullable: true, comment: 'Payload JSON')]
    public const schema_fields_PAYLOAD = 'payload_json';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Affected rows')]
    public const schema_fields_AFFECTED_ROWS = 'affected_rows';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'success', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'text', nullable: true, comment: 'Message')]
    public const schema_fields_MESSAGE = 'message';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'User ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Username')]
    public const schema_fields_USERNAME = 'username';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Client IP')]
    public const schema_fields_CLIENT_IP = 'client_ip';
    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
}
