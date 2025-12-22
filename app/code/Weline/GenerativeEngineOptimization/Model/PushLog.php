<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * 推送日志模型
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class PushLog extends Model
{
    public const table = 'geo_push_log';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'feed_id', 'platform_id', 'status', 'pushed_at'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_FEED_ID = 'feed_id';
    public const fields_PLATFORM_ID = 'platform_id';
    public const fields_PLATFORM_ACCOUNT_ID = 'platform_account_id';
    public const fields_PUSH_TYPE = 'push_type';
    public const fields_STATUS = 'status';
    public const fields_ITEMS_COUNT = 'items_count';
    public const fields_RESPONSE_DATA = 'response_data';
    public const fields_ERROR_MESSAGE = 'error_message';
    public const fields_PUSHED_AT = 'pushed_at';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Push types
     */
    public const TYPE_MANUAL = 'manual';
    public const TYPE_AUTO = 'auto';
    public const TYPE_SCHEDULED = 'scheduled';

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

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
        if ($setup->tableExist() === false) {
            $setup->createTable('GEO推送日志表')
                ->addColumn(self::fields_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_FEED_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '关联Feed ID')
                ->addColumn(self::fields_PLATFORM_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '关联平台ID')
                ->addColumn(self::fields_PLATFORM_ACCOUNT_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '关联账户ID')
                ->addColumn(self::fields_PUSH_TYPE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'manual\'', '推送类型')
                ->addColumn(self::fields_STATUS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '状态')
                ->addColumn(self::fields_ITEMS_COUNT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '推送条目数')
                ->addColumn(self::fields_RESPONSE_DATA, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '响应数据JSON')
                ->addColumn(self::fields_ERROR_MESSAGE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '错误消息')
                ->addColumn(self::fields_PUSHED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '推送时间')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_feed_id', self::fields_FEED_ID, 'Feed ID索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_platform_id', self::fields_PLATFORM_ID, '平台ID索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_pushed_at', self::fields_PUSHED_AT, '推送时间索引')
                ->create();
        }
    }

    /**
     * 获取响应数据数组
     * 
     * @return array
     */
    public function getResponseDataArray(): array
    {
        $data = $this->getData(self::fields_RESPONSE_DATA);
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * 设置响应数据数组
     * 
     * @param array $data
     * @return self
     */
    public function setResponseDataArray(array $data): self
    {
        $this->setData(self::fields_RESPONSE_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 检查是否成功
     * 
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_SUCCESS;
    }

    /**
     * 检查是否失败
     * 
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_FAILED;
    }
}
