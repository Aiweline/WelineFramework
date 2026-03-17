<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客文章模型
 */

namespace GuoLaiRen\Blog\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '博客文章表')]
#[Index(name: 'idx_site_id', columns: ['site_id'], comment: '站点索引')]
#[Index(name: 'idx_category_id', columns: ['category_id'], comment: '分类索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_published_at', columns: ['published_at'], comment: '发布时间索引')]
#[Index(name: 'idx_is_featured', columns: ['is_featured'], comment: '精选索引')]
#[Index(name: 'idx_trend_profile_id', columns: ['trend_profile_id'], comment: '趋势画像索引')]
#[Index(name: 'idx_site_profile_source', columns: ['site_id', 'trend_profile_id', 'source_keyword'], comment: '站点+画像+来源关键词，用于排重')]
class Post extends Model
{
    public const schema_table = 'guolairen_blog_post';
    public const schema_primary_key = 'post_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '文章ID')]
    public const schema_fields_ID           = 'post_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '所属站点ID')]
    public const schema_fields_SITE_ID      = 'site_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '分类ID')]
    public const schema_fields_CATEGORY_ID  = 'category_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '文章标题')]
    public const schema_fields_TITLE        = 'title';
    #[Col(type: 'varchar', length: 255, nullable: false, unique: true, comment: 'URL别名（唯一）')]
    public const schema_fields_SLUG         = 'slug';
    #[Col(type: 'text', nullable: true, comment: '文章摘要')]
    public const schema_fields_SUMMARY      = 'summary';
    #[Col(type: 'text', nullable: true, comment: '文章内容HTML')]
    public const schema_fields_CONTENT      = 'content';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '封面图片URL')]
    public const schema_fields_COVER_IMAGE  = 'cover_image';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '作者')]
    public const schema_fields_AUTHOR       = 'author';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '标签（逗号分隔）')]
    public const schema_fields_TAGS         = 'tags';
    #[Col(type: 'int', nullable: false, default: 0, comment: '浏览量')]
    public const schema_fields_VIEW_COUNT   = 'view_count';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '状态:0草稿,1已发布')]
    public const schema_fields_STATUS       = 'status';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否精选:0否,1是')]
    public const schema_fields_IS_FEATURED  = 'is_featured';
    #[Col(type: 'datetime', nullable: true, comment: '发布时间')]
    public const schema_fields_PUBLISHED_AT   = 'published_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT     = 'created_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT     = 'updated_at';
    #[Col(type: 'int', nullable: false, default: 0, comment: '趋势画像ID（自动发文时填充）')]
    public const schema_fields_TREND_PROFILE_ID = 'trend_profile_id';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '生成时的来源关键词，用于排重')]
    public const schema_fields_SOURCE_KEYWORD = 'source_keyword';

    // 状态常量
    public const STATUS_DRAFT     = 0;
    public const STATUS_PUBLISHED = 1;

    /**
     * 写入时把 published_at 空字符串转为 null，避免 PostgreSQL timestamp 报错。
     * 覆盖所有保存入口（表单、API/Query、批量 setData 等）。
     */
    public function setData($key, $value = null, bool $is_unique = false): static
    {
        if (is_array($key)) {
            if (isset($key[self::schema_fields_PUBLISHED_AT]) && $key[self::schema_fields_PUBLISHED_AT] === '') {
                $key[self::schema_fields_PUBLISHED_AT] = null;
            }
        } elseif ($key === self::schema_fields_PUBLISHED_AT && $value === '') {
            $value = null;
        }
        return parent::setData($key, $value, $is_unique);
    }

    /**
     * 获取状态名称
     */
    public function getStatusName(): string
    {
        return $this->getData(self::schema_fields_STATUS) == self::STATUS_PUBLISHED
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
        $categoryId = $this->getData(self::schema_fields_CATEGORY_ID);
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
        return $category ? $category->getData(Category::schema_fields_NAME) : '';
    }

    /**
     * 获取标签数组
     */
    public function getTagsArray(): array
    {
        $tags = $this->getData(self::schema_fields_TAGS);
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
        $count = (int)$this->getData(self::schema_fields_VIEW_COUNT);
        $this->setData(self::schema_fields_VIEW_COUNT, $count + 1);
        return $this;
    }

    /**
     * 是否为精选文章
     */
    public function isFeatured(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_FEATURED);
    }

}

