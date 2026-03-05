<?php
declare(strict_types=1);
namespace Weline\Backend\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '后台用户 Token 表')]
#[Index(name: 'uk_user_id', columns: ['user_id'], type: 'UNIQUE', comment: '用户ID唯一')]
class BackendUserToken extends Model
{
    public const schema_table = 'weline_backend_user_token';
    public const schema_primary_key = 'user_id';
    #[Col(type: 'int', primaryKey: true, nullable: false, comment: '用户ID')]
    public const schema_fields_ID = 'user_id';
    #[Col(type: 'varchar', length: 255, comment: 'token')]
    public const schema_fields_token = 'token';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '类型')]
    public const schema_fields_type = 'type';
    #[Col(type: 'varchar', length: 11, comment: '过期时间')]
    public const schema_fields_token_expire_time = 'token_expire_time';
}

