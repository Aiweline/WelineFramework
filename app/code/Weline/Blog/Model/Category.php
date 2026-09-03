<?php

declare(strict_types=1);

namespace Weline\Blog\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '博客分类')]
#[Index(name: 'idx_blog_category_website_slug', columns: ['website_id', 'slug'], type: 'UNIQUE')]
class Category extends Model
{
    public const schema_table = 'weline_blog_category';
    public const schema_primary_key = 'category_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '分类 ID')]
    public const schema_fields_ID = 'category_id';
    #[Col('int', nullable: false, default: 0, comment: '网站 ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 120, nullable: false, comment: '分类 slug')]
    public const schema_fields_SLUG = 'slug';
    #[Col('varchar', 255, nullable: false, comment: '分类名称')]
    public const schema_fields_NAME = 'name';
    #[Col('int', nullable: false, default: 0, comment: '父分类 ID（0=顶级；限深 2）')]
    public const schema_fields_PARENT_ID = 'parent_id';
    #[Col('int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
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

    public function getCategoryId(): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: 0);
    }
}
