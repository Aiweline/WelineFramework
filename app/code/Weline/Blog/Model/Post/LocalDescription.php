<?php

declare(strict_types=1);

namespace Weline\Blog\Model\Post;

use Weline\Blog\Model\Post;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;

/**
 * 博客文章关键词多语言（LocalModel + AI 翻译队列）。
 * 主表 {@see Post} 仍按 locale 分行存正文；本表按 post_id×local_code 存关键词译文。
 */
#[Table(comment: '博客文章关键词多语言')]
#[Index(name: 'uk_blog_post_local', columns: ['post_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_blog_post_local';
    public const schema_primary_key = Post::schema_fields_ID;
    public const indexer = 'blog_post_local';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '文章 ID')]
    public const schema_fields_ID = Post::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 500, nullable: true, comment: '关键词')]
    public const schema_fields_KEYWORDS = Post::schema_fields_KEYWORDS;

    /** TraitLocalModel::getName() 读此列 */
    public const schema_fields_name = Post::schema_fields_KEYWORDS;
}
