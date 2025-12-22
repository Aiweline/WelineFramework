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
 * Feed条目模型
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class FeedItem extends Model
{
    public const table = 'geo_feed_item';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'feed_id', 'item_type', 'item_id', 'is_published'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_FEED_ID = 'feed_id';
    public const fields_ITEM_TYPE = 'item_type';
    public const fields_ITEM_ID = 'item_id';
    public const fields_TITLE = 'title';
    public const fields_CONTENT = 'content';
    public const fields_URL = 'url';
    public const fields_METADATA = 'metadata';
    public const fields_IS_PUBLISHED = 'is_published';
    public const fields_PUBLISHED_AT = 'published_at';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

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
            $setup->createTable('GEO Feed条目表')
                ->addColumn(self::fields_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_FEED_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '关联Feed ID')
                ->addColumn(self::fields_ITEM_TYPE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '条目类型')
                ->addColumn(self::fields_ITEM_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '条目ID（关联源数据）')
                ->addColumn(self::fields_TITLE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '标题')
                ->addColumn(self::fields_CONTENT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '内容')
                ->addColumn(self::fields_URL, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 500, 'not null', 'URL')
                ->addColumn(self::fields_METADATA, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '元数据JSON')
                ->addColumn(self::fields_IS_PUBLISHED, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否已发布')
                ->addColumn(self::fields_PUBLISHED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '发布时间')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_feed_id', self::fields_FEED_ID, 'Feed ID索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_item_type_id', [self::fields_ITEM_TYPE, self::fields_ITEM_ID], '条目类型和ID复合索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_is_published', self::fields_IS_PUBLISHED, '发布状态索引')
                ->create();
        }
    }

    /**
     * 获取元数据数组
     * 
     * @return array
     */
    public function getMetadataArray(): array
    {
        $metadata = $this->getData(self::fields_METADATA);
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($metadata) ? $metadata : [];
    }

    /**
     * 设置元数据数组
     * 
     * @param array $metadata
     * @return self
     */
    public function setMetadataArray(array $metadata): self
    {
        $this->setData(self::fields_METADATA, json_encode($metadata, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 检查是否已发布
     * 
     * @return bool
     */
    public function isPublished(): bool
    {
        return (int)$this->getData(self::fields_IS_PUBLISHED) === 1;
    }

    /**
     * 保存前处理
     * 
     * @return void
     */
    public function save_before(): void
    {
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
    }
}
