<?php

declare(strict_types=1);

namespace Weline\Feed\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '购物目录 Feed 定义')]
#[Index(name: 'uk_feed_definition_public_token', columns: ['public_token'], type: 'UNIQUE')]
#[Index(name: 'idx_feed_definition_website_channel', columns: ['website_id', 'channel_code'])]
final class FeedDefinition extends Model
{
    public const schema_table = 'feed_definition';
    public const schema_primary_key = 'definition_id';

    /** @var list<string> */
    public array $_unit_primary_keys = ['definition_id'];

    /** @var list<string> */
    public array $_index_sort_keys = ['definition_id', 'website_id', 'channel_code'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '定义 ID')]
    public const schema_fields_ID = 'definition_id';

    #[Col('int', nullable: false, default: 0, comment: '网站 ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, default: '', comment: '来源码')]
    public const schema_fields_SOURCE_CODE = 'source_code';

    #[Col('varchar', 64, nullable: false, default: '', comment: '渠道码')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';

    #[Col('varchar', 32, nullable: false, default: '', comment: 'locale')]
    public const schema_fields_LOCALE = 'locale';

    #[Col('varchar', 8, nullable: false, default: '', comment: '币种')]
    public const schema_fields_CURRENCY = 'currency';

    #[Col('varchar', 255, nullable: false, default: '', comment: '标题')]
    public const schema_fields_TITLE = 'title';

    #[Col('int', nullable: false, default: 0, comment: '启停')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('varchar', 32, nullable: false, default: '', comment: '连接态')]
    public const schema_fields_CONNECTION_MODE = 'connection_mode';

    #[Col('int', nullable: false, default: 0, comment: '账号 ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';

    #[Col('varchar', 64, nullable: true, comment: '公开 token')]
    public const schema_fields_PUBLIC_TOKEN = 'public_token';

    #[Col('text', nullable: true, comment: '过滤条件')]
    public const schema_fields_FILTER_JSON = 'filter_json';

    #[Col('timestamp', nullable: true, comment: '最近生成时间')]
    public const schema_fields_LAST_GENERATED_AT = 'last_generated_at';

    #[Col('int', nullable: false, default: 0, comment: '归档')]
    public const schema_fields_ARCHIVED = 'archived';
}
