<?php

declare(strict_types=1);

namespace Weline\Captcha\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 验证码结果模型
 */
#[Table(comment: '验证码结果表')]
#[Index(name: 'idx_token', columns: ['token'], type: 'UNIQUE', comment: '令牌唯一索引')]
#[Index(name: 'idx_expires_at', columns: ['expires_at'], comment: '过期时间索引')]
class CaptchaResult extends Model
{
    public const schema_table = 'weline_captcha_result';
    public const schema_primary_key = 'id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 100, nullable: false, comment: '令牌')]
    public const schema_fields_TOKEN = 'token';
    #[Col('varchar', 50, nullable: false, comment: '验证码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 50, nullable: false, comment: '类型')]
    public const schema_fields_TYPE = 'type';
    #[Col('datetime', nullable: false, comment: '过期时间')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['token', 'expires_at'];
}
