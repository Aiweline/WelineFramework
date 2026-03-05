<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * AI Tenant User Entity
 *
 * Manages tenant-user associations.
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI Tenant User')]
#[Index(name: 'uk_tenant_user', columns: ['tenant_id', 'user_id'], type: 'UNIQUE')]
#[Index(name: 'idx_user_id', columns: ['user_id'])]
#[Index(name: 'idx_role', columns: ['role'])]
class AiTenantUser extends Model
{
    public const schema_table = 'ai_tenant_user';
    public const schema_primary_key = 'id';

    /** @var array Unit primary keys */
    public array $_unit_primary_keys = ['id'];

    /** @var array Index sort keys */
    public array $_index_sort_keys = ['id', 'tenant_id', 'user_id'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '映射ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'int', nullable: false, comment: '租户ID')]
    public const schema_fields_TENANT_ID = 'tenant_id';
    #[Col(type: 'int', nullable: false, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '角色')]
    public const schema_fields_ROLE = 'role';
    #[Col(type: 'text', nullable: true, comment: '权限列表（JSON）')]
    public const schema_fields_PERMISSIONS = 'permissions';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';
}

