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
#[Table(comment: 'WASM哈希记录表')]
#[Index(name: 'idx_version', columns: ['version'], comment: '版本索引')]
#[Index(name: 'idx_hash_value', columns: ['hash_value'], type: 'UNIQUE', comment: '哈希值唯一索引')]
class WasmHash extends Model
{
    public const schema_table = 'weline_auto_lead_agent_wasm_hash';
    public const schema_primary_key = 'hash_id';


    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '哈希ID')]
    public const schema_fields_ID = 'hash_id';
    #[Col('varchar', 512, nullable: false, comment: 'WASM文件路径')]
    public const schema_fields_WASM_PATH = 'wasm_path';
    #[Col('varchar', 64, nullable: false, comment: 'SHA-256哈希值')]
    public const schema_fields_HASH_VALUE = 'hash_value';
    #[Col('varchar', 50, nullable: false, comment: '版本号')]
    public const schema_fields_VERSION = 'version';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['hash_id', 'version', 'created_at'];

    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
    }
}


