<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class WasmHash extends AbstractModel
{
    public const table = 'weline_auto_lead_agent_wasm_hash';
    
    public const fields_ID = 'hash_id';
    public const fields_WASM_PATH = 'wasm_path';
    public const fields_HASH_VALUE = 'hash_value';
    public const fields_VERSION = 'version';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['hash_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['hash_id', 'version', 'created_at'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable(__('WASM哈希记录表'))
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    __('哈希ID')
                )
                ->addColumn(
                    self::fields_WASM_PATH,
                    TableInterface::column_type_VARCHAR,
                    512,
                    'not null',
                    __('WASM文件路径')
                )
                ->addColumn(
                    self::fields_HASH_VALUE,
                    TableInterface::column_type_VARCHAR,
                    64,
                    'not null',
                    __('SHA-256哈希值')
                )
                ->addColumn(
                    self::fields_VERSION,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    __('版本号')
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    null,
                    'not null default current_timestamp',
                    __('创建时间')
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    null,
                    'not null default current_timestamp on update current_timestamp',
                    __('更新时间')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_version',
                    self::fields_VERSION,
                    __('版本索引')
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_hash_value',
                    self::fields_HASH_VALUE,
                    __('哈希值唯一索引')
                )
                ->create();
        }
    }

    /**
     * 设置表结构（开发模式）
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }
}

