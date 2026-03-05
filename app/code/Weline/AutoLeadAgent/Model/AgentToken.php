<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'Token存储表')]
#[Index(name: 'idx_domain', columns: ['domain'], comment: '域名索引')]
#[Index(name: 'idx_expires_at', columns: ['expires_at'], comment: '过期时间索引')]
#[Index(name: 'idx_token', columns: ['token'], type: 'UNIQUE', comment: 'Token唯一索引')]
class AgentToken extends Model
{
    public const schema_table = 'weline_auto_lead_agent_token';
    public const schema_primary_key = 'token_id';


    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'Token ID')]
    public const schema_fields_ID = 'token_id';
    #[Col('varchar', 512, nullable: false, comment: 'Token字符串')]
    public const schema_fields_TOKEN = 'token';
    #[Col('varchar', 255, nullable: false, comment: '授权域名')]
    public const schema_fields_DOMAIN = 'domain';
    #[Col('datetime', nullable: false, comment: '过期时间')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('varchar', 64, nullable: false, comment: 'WASM文件哈希值')]
    public const schema_fields_WASM_HASH = 'wasm_hash';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['token_id', 'domain', 'expires_at'];

    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
    }
}


