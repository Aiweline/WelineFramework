<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客文章模型
 */

namespace GuoLaiRen\Blog\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Post extends Model
{
    public const table = 'guolairen_blog_post';

    // 字段定义
    public const fields_ID           = 'post_id';
    public const fields_SITE_ID      = 'site_id';
    public const fields_CATEGORY_ID  = 'category_id';
    public const fields_TITLE        = 'title';
    public const fields_SLUG         = 'slug';
    public const fields_SUMMARY      = 'summary';
    public const fields_CONTENT      = 'content';
    public const fields_COVER_IMAGE  = 'cover_image';
    public const fields_AUTHOR       = 'author';
    public const fields_TAGS         = 'tags';
    public const fields_VIEW_COUNT   = 'view_count';
    public const fields_STATUS       = 'status';
    public const fields_IS_FEATURED  = 'is_featured';
    public const fields_PUBLISHED_AT   = 'published_at';
    public const fields_CREATED_AT     = 'created_at';
    public const fields_UPDATED_AT     = 'updated_at';
    public const fields_TREND_PROFILE_ID = 'trend_profile_id';

    // 状态常量
    public const STATUS_DRAFT     = 0;
    public const STATUS_PUBLISHED = 1;

    /**
     * 获取状态名称
     */
    public function getStatusName(): string
    {
        return $this->getData(self::fields_STATUS) == self::STATUS_PUBLISHED
            ? __('已发布')
            : __('草稿');
    }

    /**
     * 获取状态列表
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_DRAFT     => __('草稿'),
            self::STATUS_PUBLISHED => __('已发布'),
        ];
    }

    /**
     * 获取分类
     */
    public function getCategory(): ?Category
    {
        $categoryId = $this->getData(self::fields_CATEGORY_ID);
        if (!$categoryId) {
            return null;
        }

        $category = \Weline\Framework\Manager\ObjectManager::getInstance(Category::class);
        $category->load($categoryId);
        return $category->getId() ? $category : null;
    }

    /**
     * 获取分类名称
     */
    public function getCategoryName(): string
    {
        $category = $this->getCategory();
        return $category ? $category->getData(Category::fields_NAME) : '';
    }

    /**
     * 获取标签数组
     */
    public function getTagsArray(): array
    {
        $tags = $this->getData(self::fields_TAGS);
        if (empty($tags)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $tags)));
    }

    /**
     * 增加浏览量
     */
    public function incrementViewCount(): self
    {
        $count = (int)$this->getData(self::fields_VIEW_COUNT);
        $this->setData(self::fields_VIEW_COUNT, $count + 1);
        return $this;
    }

    /**
     * 是否为精选文章
     */
    public function isFeatured(): bool
    {
        return (bool)$this->getData(self::fields_IS_FEATURED);
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('博客文章表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '文章ID'
            )
            ->addColumn(
                self::fields_SITE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '所属站点ID'
            )
            ->addColumn(
                self::fields_CATEGORY_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '分类ID'
            )
            ->addColumn(
                self::fields_TITLE,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '文章标题'
            )
            ->addColumn(
                self::fields_SLUG,
                TableInterface::column_type_VARCHAR,
                255,
                'not null unique',
                'URL别名（唯一）'
            )
            ->addColumn(
                self::fields_SUMMARY,
                TableInterface::column_type_TEXT,
                0,
                '',
                '文章摘要'
            )
            ->addColumn(
                self::fields_CONTENT,
                TableInterface::column_type_TEXT,
                0,
                '',
                '文章内容HTML'
            )
            ->addColumn(
                self::fields_COVER_IMAGE,
                TableInterface::column_type_VARCHAR,
                500,
                '',
                '封面图片URL'
            )
            ->addColumn(
                self::fields_AUTHOR,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                '作者'
            )
            ->addColumn(
                self::fields_TAGS,
                TableInterface::column_type_VARCHAR,
                500,
                '',
                '标签（逗号分隔）'
            )
            ->addColumn(
                self::fields_VIEW_COUNT,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '浏览量'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 0',
                '状态:0草稿,1已发布'
            )
            ->addColumn(
                self::fields_IS_FEATURED,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 0',
                '是否精选:0否,1是'
            )
            ->addColumn(
                self::fields_PUBLISHED_AT,
                TableInterface::column_type_DATETIME,
                0,
                '',
                '发布时间'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addColumn(
                self::fields_TREND_PROFILE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '趋势画像ID（自动发文时填充）'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_site_id',
                [self::fields_SITE_ID],
                '站点索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_category_id',
                [self::fields_CATEGORY_ID],
                '分类索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_status',
                [self::fields_STATUS],
                '状态索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_published_at',
                [self::fields_PUBLISHED_AT],
                '发布时间索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_is_featured',
                [self::fields_IS_FEATURED],
                '精选索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_trend_profile_id',
                [self::fields_TREND_PROFILE_ID],
                '趋势画像索引'
            )
            ->create();
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            return;
        }
        
        // 添加 site_id 字段
        if (!$setup->hasField(self::fields_SITE_ID)) {
            $setup->alterTable()->addColumn(
                self::fields_SITE_ID,
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '所属站点ID'
            )->addIndex(
                TableInterface::index_type_KEY,
                'idx_site_id',
                [self::fields_SITE_ID],
                '站点索引'
            )->alter();
        }
        
        // 添加 category_id 字段
        if (!$setup->hasField(self::fields_CATEGORY_ID)) {
            $setup->alterTable()->addColumn(
                self::fields_CATEGORY_ID,
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '分类ID'
            )->addIndex(
                TableInterface::index_type_KEY,
                'idx_category_id',
                [self::fields_CATEGORY_ID],
                '分类索引'
            )->alter();
        }
        
        // 添加 author 字段
        if (!$setup->hasField(self::fields_AUTHOR)) {
            $setup->alterTable()->addColumn(
                self::fields_AUTHOR,
                self::fields_COVER_IMAGE,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                '作者'
            )->alter();
        }
        
        // 添加 tags 字段
        if (!$setup->hasField(self::fields_TAGS)) {
            $setup->alterTable()->addColumn(
                self::fields_TAGS,
                self::fields_AUTHOR,
                TableInterface::column_type_VARCHAR,
                500,
                '',
                '标签（逗号分隔）'
            )->alter();
        }
        
        // 添加 view_count 字段
        if (!$setup->hasField(self::fields_VIEW_COUNT)) {
            $setup->alterTable()->addColumn(
                self::fields_VIEW_COUNT,
                self::fields_TAGS,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '浏览量'
            )->alter();
        }
        
        // 添加 is_featured 字段
        if (!$setup->hasField(self::fields_IS_FEATURED)) {
            $setup->alterTable()->addColumn(
                self::fields_IS_FEATURED,
                self::fields_STATUS,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 0',
                '是否精选:0否,1是'
            )->addIndex(
                TableInterface::index_type_KEY,
                'idx_is_featured',
                [self::fields_IS_FEATURED],
                '精选索引'
            )->alter();
        }

        // 趋势自动发文来源画像 ID（用于按站点+画像统计当日已发篇数）
        if (!$setup->hasField(self::fields_TREND_PROFILE_ID)) {
            $setup->alterTable()->addColumn(
                self::fields_TREND_PROFILE_ID,
                self::fields_CATEGORY_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '趋势画像ID（自动发文时填充）'
            )->addIndex(
                TableInterface::index_type_KEY,
                'idx_trend_profile_id',
                [self::fields_TREND_PROFILE_ID],
                '趋势画像索引'
            )->alter();
        }
    }

    /**
     * 设置表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}

