<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * 预热URL模型
 * 
 * @package Weline_Cdn
 */
class WarmupUrl extends Model
{
    public const table = 'cdn_warmup_url';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['warmup_url_id'];
    
    /**
     * Field name constants
     */
    public const fields_WARMUP_URL_ID = 'warmup_url_id';
    public const fields_MODULE = 'module';
    public const fields_PROVIDER = 'provider';
    public const fields_URL = 'url';
    public const fields_SITE_ID = 'site_id';
    public const fields_DOMAIN_ID = 'domain_id';
    public const fields_STATUS = 'status';
    public const fields_TARGET_COUNT = 'target_count';
    public const fields_PROCESSED_COUNT = 'processed_count';
    public const fields_SUCCESS_COUNT = 'success_count';
    public const fields_FAIL_COUNT = 'fail_count';
    public const fields_RETRIES = 'retries';
    public const fields_ENABLED = 'enabled';
    public const fields_LAST_WARMED_AT = 'last_warmed_at';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAIL = 'fail';

    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    /**
     * 获取主键字段名
     * 
     * @return string
     */
    public function getIdFieldName(): string
    {
        return self::fields_WARMUP_URL_ID;
    }

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('预热URL表')
                ->addColumn(self::fields_WARMUP_URL_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '预热URL ID')
                ->addColumn(self::fields_MODULE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 128, 'not null', '来源模块')
                ->addColumn(self::fields_PROVIDER, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 128, 'not null', '提供者')
                ->addColumn(self::fields_URL, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 512, 'not null', 'URL地址')
                ->addColumn(self::fields_SITE_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '站点ID')
                ->addColumn(self::fields_DOMAIN_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '域名ID')
                ->addColumn(self::fields_STATUS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '状态')
                ->addColumn(self::fields_TARGET_COUNT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 1', '目标次数')
                ->addColumn(self::fields_PROCESSED_COUNT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '已处理次数')
                ->addColumn(self::fields_SUCCESS_COUNT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '成功次数')
                ->addColumn(self::fields_FAIL_COUNT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '失败次数')
                ->addColumn(self::fields_RETRIES, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '重试次数')
                ->addColumn(self::fields_ENABLED, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 1', '是否启用')
                ->addColumn(self::fields_LAST_WARMED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '最后预热时间')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(self::fields_MODULE, '', 'KEY', 'idx_module')
                ->addIndex(self::fields_STATUS, '', 'KEY', 'idx_status')
                ->addIndex(self::fields_ENABLED, '', 'KEY', 'idx_enabled')
                ->addIndex(self::fields_DOMAIN_ID, '', 'KEY', 'idx_domain_id')
                ->addIndex(self::fields_URL, '', 'KEY', 'idx_url')
                ->create();
        }
    }

    /**
     * 检查是否启用
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (int)$this->getData(self::fields_ENABLED) === 1;
    }

    /**
     * 保存前处理
     * 
     * @return self
     */
    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getData(self::fields_CREATED_AT)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
        return parent::beforeSave();
    }
}

