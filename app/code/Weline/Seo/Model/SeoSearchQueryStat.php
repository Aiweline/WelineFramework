<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** GSC/Bing query-level search analytics for site word-cloud heat. */
#[Table(comment: '站点搜索查询词统计')]
#[Index(name: 'uniq_website_account_platform_query_window', columns: ['website_id', 'account_id', 'platform', 'query_hash', 'window_end'], type: 'UNIQUE')]
#[Index(name: 'idx_website_heat', columns: ['website_id', 'heat'])]
#[Index(name: 'idx_website_window', columns: ['website_id', 'window_end'])]
class SeoSearchQueryStat extends Model
{
    public const schema_table = 'weline_seo_search_query_stat';
    public const schema_primary_key = 'id';

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '记录ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', 0, nullable: false, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', 0, nullable: false, comment: 'SEO账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('varchar', 50, nullable: false, comment: '平台代码')]
    public const schema_fields_PLATFORM = 'platform';
    #[Col('varchar', 512, nullable: false, comment: '查询词')]
    public const schema_fields_QUERY = 'query';
    #[Col('varchar', 64, nullable: false, comment: '查询词哈希')]
    public const schema_fields_QUERY_HASH = 'query_hash';
    #[Col('int', 0, nullable: false, default: 0, comment: '点击量')]
    public const schema_fields_CLICKS = 'clicks';
    #[Col('int', 0, nullable: false, default: 0, comment: '展示量')]
    public const schema_fields_IMPRESSIONS = 'impressions';
    #[Col('decimal', '8,6', nullable: false, default: 0, comment: '点击率')]
    public const schema_fields_CTR = 'ctr';
    #[Col('decimal', '8,2', nullable: false, default: 0, comment: '平均排名')]
    public const schema_fields_AVERAGE_POSITION = 'average_position';
    #[Col('decimal', '8,2', nullable: false, default: 0, comment: '热度 0-100')]
    public const schema_fields_HEAT = 'heat';
    #[Col('date', nullable: false, comment: '窗口开始')]
    public const schema_fields_WINDOW_START = 'window_start';
    #[Col('date', nullable: false, comment: '窗口结束')]
    public const schema_fields_WINDOW_END = 'window_end';
    #[Col('datetime', comment: '最后同步时间')]
    public const schema_fields_LAST_SYNC_AT = 'last_sync_at';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }
}
