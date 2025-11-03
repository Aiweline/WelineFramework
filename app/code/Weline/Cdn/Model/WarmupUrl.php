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
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;

/**
 * 预热URL模型
 */
class WarmupUrl extends Model
{
    // 字段常量
    public const fields_ID = 'warmup_url_id';
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

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAIL = 'fail';

    /**
     * @inheritDoc
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    /**
     * 获取主键字段名
     */
    public function getIdFieldName(): string
    {
        return self::fields_ID;
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
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('CDN预热URL表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '预热URL ID')
            ->addColumn(self::fields_MODULE, TableInterface::column_type_VARCHAR, 128, 'not null', '来源模块')
            ->addColumn(self::fields_PROVIDER, TableInterface::column_type_VARCHAR, 128, 'not null', '提供者')
            ->addColumn(self::fields_URL, TableInterface::column_type_VARCHAR, 512, 'not null', 'URL地址')
            ->addColumn(self::fields_SITE_ID, TableInterface::column_type_INTEGER, 11, '', '站点ID')
            ->addColumn(self::fields_DOMAIN_ID, TableInterface::column_type_INTEGER, 11, '', '域名ID')
            ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'pending'", '状态')
            ->addColumn(self::fields_TARGET_COUNT, TableInterface::column_type_INTEGER, 11, 'default 0', '目标次数')
            ->addColumn(self::fields_PROCESSED_COUNT, TableInterface::column_type_INTEGER, 11, 'default 0', '已处理次数')
            ->addColumn(self::fields_SUCCESS_COUNT, TableInterface::column_type_INTEGER, 11, 'default 0', '成功次数')
            ->addColumn(self::fields_FAIL_COUNT, TableInterface::column_type_INTEGER, 11, 'default 0', '失败次数')
            ->addColumn(self::fields_RETRIES, TableInterface::column_type_INTEGER, 11, 'default 0', '重试次数')
            ->addColumn(self::fields_ENABLED, TableInterface::column_type_INTEGER, 1, 'default 1', '是否启用')
            ->addColumn(self::fields_LAST_WARMED_AT, TableInterface::column_type_INTEGER, null, '', '最后预热时间')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
            ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
            ->addIndex([self::fields_MODULE, self::fields_URL], '', 'UNIQUE', 'idx_module_url')
            ->addIndex(self::fields_SITE_ID, '', 'INDEX', 'idx_site_id')
            ->addIndex(self::fields_STATUS, '', 'INDEX', 'idx_status')
            ->addIndex(self::fields_ENABLED, '', 'INDEX', 'idx_enabled')
            ->create();
    }

    /**
     * 标记成功
     */
    public function markSuccess(): self
    {
        $processedCount = (int)$this->getData(self::fields_PROCESSED_COUNT);
        $successCount = (int)$this->getData(self::fields_SUCCESS_COUNT);
        
        $this->setData(self::fields_STATUS, self::STATUS_SUCCESS)
            ->setData(self::fields_PROCESSED_COUNT, $processedCount + 1)
            ->setData(self::fields_SUCCESS_COUNT, $successCount + 1)
            ->setData(self::fields_LAST_WARMED_AT, time())
            ->setData(self::fields_UPDATED_AT, time());
            
        return $this;
    }

    /**
     * 标记失败
     */
    public function markFail(): self
    {
        $processedCount = (int)$this->getData(self::fields_PROCESSED_COUNT);
        $failCount = (int)$this->getData(self::fields_FAIL_COUNT);
        $retries = (int)$this->getData(self::fields_RETRIES);
        
        $this->setData(self::fields_STATUS, self::STATUS_FAIL)
            ->setData(self::fields_PROCESSED_COUNT, $processedCount + 1)
            ->setData(self::fields_FAIL_COUNT, $failCount + 1)
            ->setData(self::fields_RETRIES, $retries + 1)
            ->setData(self::fields_LAST_WARMED_AT, time())
            ->setData(self::fields_UPDATED_AT, time());
            
        return $this;
    }

    /**
     * 重置状态为待处理
     */
    public function resetToPending(): self
    {
        $this->setData(self::fields_STATUS, self::STATUS_PENDING)
            ->setData(self::fields_UPDATED_AT, time());
        return $this;
    }
}
