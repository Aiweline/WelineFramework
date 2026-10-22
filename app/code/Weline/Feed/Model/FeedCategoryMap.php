<?php

declare(strict_types=1);

namespace Weline\Feed\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '购物目录站点分类映射')]
#[Index(name: 'uk_feed_category_map_site', columns: ['website_id', 'vocabulary', 'store_category_id'], type: 'UNIQUE')]
final class FeedCategoryMap extends Model
{
    public const schema_table = 'feed_category_map';
    public const schema_primary_key = 'map_id';

    /** @var list<string> */
    public array $_unit_primary_keys = ['map_id'];

    /** @var list<string> */
    public array $_index_sort_keys = ['map_id', 'website_id', 'vocabulary', 'store_category_id'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '映射 ID')]
    public const schema_fields_ID = 'map_id';

    #[Col('int', nullable: false, default: 0, comment: '网站 ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 16, nullable: false, default: '', comment: '词表 google yandex naver')]
    public const schema_fields_VOCABULARY = 'vocabulary';

    #[Col('int', nullable: false, default: 0, comment: '店内分类 ID')]
    public const schema_fields_STORE_CATEGORY_ID = 'store_category_id';

    #[Col('varchar', 255, nullable: false, default: '', comment: '渠道分类值')]
    public const schema_fields_MAP_VALUE = 'map_value';

    #[Col('varchar', 255, nullable: false, default: '', comment: '渠道分类名')]
    public const schema_fields_MAP_LABEL = 'map_label';

    #[Col('timestamp', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
