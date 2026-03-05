<?php

declare(strict_types=1);

namespace Weline\Captcha\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 失败尝试模型
 */
#[Table(comment: '验证码失败尝试表')]
#[Index(name: 'idx_ip', columns: ['ip'], comment: 'IP地址索引')]
#[Index(name: 'idx_attempted_at', columns: ['attempted_at'], comment: '尝试时间索引')]
class FailedAttempt extends Model
{
    public const schema_table = 'weline_captcha_failed_attempt';
    public const schema_primary_key = 'id';

#[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 45, nullable: false, comment: 'IP地址')]
    public const schema_fields_IP = 'ip';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '尝试时间')]
    public const schema_fields_ATTEMPTED_AT = 'attempted_at';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['ip', 'attempted_at'];
    
}

