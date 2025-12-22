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

class AgentToken extends AbstractModel
{
    public const table = 'weline_auto_lead_agent_token';
    
    public const fields_ID = 'token_id';
    public const fields_TOKEN = 'token';
    public const fields_DOMAIN = 'domain';
    public const fields_EXPIRES_AT = 'expires_at';
    public const fields_WASM_HASH = 'wasm_hash';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['token_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['token_id', 'domain', 'expires_at'];

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
            $setup->createTable(__('Token存储表'))
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    __('Token ID')
                )
                ->addColumn(
                    self::fields_TOKEN,
                    TableInterface::column_type_VARCHAR,
                    512,
                    'not null',
                    __('Token字符串')
                )
                ->addColumn(
                    self::fields_DOMAIN,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    __('授权域名')
                )
                ->addColumn(
                    self::fields_EXPIRES_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'not null',
                    __('过期时间')
                )
                ->addColumn(
                    self::fields_WASM_HASH,
                    TableInterface::column_type_VARCHAR,
                    64,
                    'not null',
                    __('WASM文件哈希值')
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
                    'idx_domain',
                    self::fields_DOMAIN,
                    __('域名索引')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_expires_at',
                    self::fields_EXPIRES_AT,
                    __('过期时间索引')
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_token',
                    self::fields_TOKEN,
                    __('Token唯一索引')
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

