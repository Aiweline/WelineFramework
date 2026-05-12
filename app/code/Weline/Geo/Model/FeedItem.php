<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** Feed条目模型 @package Weline_Geo */
#[Table(comment: 'GEO Feed条目表')]
#[Index(name: 'idx_feed_id', columns: ['feed_id'], comment: 'Feed ID索引')]
#[Index(name: 'idx_item_type_id', columns: ['item_type', 'item_id'], comment: '条目类型和ID复合索引')]
#[Index(name: 'idx_is_published', columns: ['is_published'], comment: '发布状态索引')]
class FeedItem extends Model
{
    public const schema_table = 'geo_feed_item';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'feed_id', 'item_type', 'item_id', 'is_published'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', nullable: false, comment: '关联Feed ID')]
    public const schema_fields_FEED_ID = 'feed_id';
    #[Col('varchar', 50, nullable: false, comment: '条目类型')]
    public const schema_fields_ITEM_TYPE = 'item_type';
    #[Col('int', nullable: false, comment: '条目ID')]
    public const schema_fields_ITEM_ID = 'item_id';
    #[Col('varchar', 255, nullable: false, comment: '标题')]
    public const schema_fields_TITLE = 'title';
    #[Col('text', comment: '内容')]
    public const schema_fields_CONTENT = 'content';
    #[Col('varchar', 500, nullable: false, comment: 'URL')]
    public const schema_fields_URL = 'url';
    #[Col('text', comment: '元数据JSON')]
    public const schema_fields_METADATA = 'metadata';
    #[Col('int', 1, default: 0, comment: '是否已发布')]
    public const schema_fields_IS_PUBLISHED = 'is_published';
    #[Col('int', comment: '发布时间')]
    public const schema_fields_PUBLISHED_AT = 'published_at';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

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
        return self::schema_fields_ID;
    }
/**
     * 获取元数据数组
     * 
     * @return array
     */
    public function getMetadataArray(): array
    {
        $metadata = $this->getData(self::schema_fields_METADATA);
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
        $this->setData(self::schema_fields_METADATA, json_encode($metadata, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 检查是否已发布
     * 
     * @return bool
     */
    public function isPublished(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_PUBLISHED) === 1;
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
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
