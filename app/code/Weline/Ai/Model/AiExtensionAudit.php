<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * AI Extension Audit Entity
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI扩展助手审计表')]
#[Index(name: 'idx_grant_id', columns: ['grant_id'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class AiExtensionAudit extends Model
{
    public const schema_table = 'ai_extension_audit';
    public const schema_primary_key = 'id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '授权ID')]
    public const schema_fields_GRANT_ID = 'grant_id';
    #[Col(type: 'varchar', length: 20, nullable: false, comment: '操作类型')]
    public const schema_fields_ACTION = 'action';
    #[Col(type: 'varchar', length: 20, nullable: false, comment: '结果')]
    public const schema_fields_RESULT = 'result';
    #[Col(type: 'varchar', length: 45, nullable: false, comment: 'IP地址')]
    public const schema_fields_IP = 'ip';
    #[Col(type: 'timestamp', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const ACTION_PAIRING = 'pairing';
    public const ACTION_RENEW = 'renew';
    public const ACTION_REVOKE = 'revoke';
    public const ACTION_CALL = 'call';
}
