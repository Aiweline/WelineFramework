<?php

declare(strict_types=1);

namespace Weline\Blog\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '博客结构化文章')]
#[Index(name: 'idx_blog_post_website_slug', columns: ['website_id', 'slug'], type: 'UNIQUE')]
#[Index(name: 'idx_blog_post_website_status_published', columns: ['website_id', 'status', 'published_at'])]
class Post extends Model
{
    public const schema_table = 'weline_blog_post';
    public const schema_primary_key = 'post_id';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DISABLED = 'disabled';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '文章 ID')]
    public const schema_fields_ID = 'post_id';
    #[Col('int', nullable: false, default: 0, comment: '网站 ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 16, nullable: false, default: 'default', comment: '语言')]
    public const schema_fields_LOCALE = 'locale';
    #[Col('varchar', 160, nullable: false, comment: 'URL slug')]
    public const schema_fields_SLUG = 'slug';
    #[Col('varchar', 255, nullable: false, comment: '标题')]
    public const schema_fields_TITLE = 'title';
    #[Col('text', nullable: true, comment: '摘要')]
    public const schema_fields_EXCERPT = 'excerpt';
    #[Col('mediumtext', nullable: true, comment: '正文')]
    public const schema_fields_CONTENT = 'content';
    #[Col('varchar', 255, nullable: true, comment: '封面图')]
    public const schema_fields_COVER_IMAGE = 'cover_image';
    #[Col('varchar', 120, nullable: true, comment: '作者')]
    public const schema_fields_AUTHOR = 'author';
    #[Col('varchar', 500, nullable: true, comment: '关键词')]
    public const schema_fields_KEYWORDS = 'keywords';
    #[Col('int', nullable: true, comment: '分类 ID')]
    public const schema_fields_CATEGORY_ID = 'category_id';
    #[Col('varchar', 32, nullable: false, default: 'draft', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: true, comment: '发布时间')]
    public const schema_fields_PUBLISHED_AT = 'published_at';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_id_field_name = self::schema_fields_ID;
    }

    public function getPostId(): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: 0);
    }

    public function isPublished(): bool
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?? '') === self::STATUS_PUBLISHED;
    }
}
