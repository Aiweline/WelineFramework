<?php

declare(strict_types=1);

namespace Weline\Feed\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '购物目录 Feed 渠道账号')]
#[Index(name: 'idx_feed_channel_account_website', columns: ['website_id', 'channel_code'])]
final class FeedChannelAccount extends Model
{
    public const schema_table = 'feed_channel_account';
    public const schema_primary_key = 'account_id';

    /** @var list<string> */
    public array $_unit_primary_keys = ['account_id'];

    /** @var list<string> */
    public array $_index_sort_keys = ['account_id', 'website_id', 'channel_code'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '账号 ID')]
    public const schema_fields_ID = 'account_id';

    #[Col('int', nullable: false, default: 0, comment: '网站 ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, default: '', comment: '渠道码')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';

    #[Col('varchar', 32, nullable: false, default: '', comment: '连接态')]
    public const schema_fields_CONNECTION_MODE = 'connection_mode';

    #[Col('varchar', 255, nullable: false, default: '', comment: '显示名')]
    public const schema_fields_DISPLAY_NAME = 'display_name';

    #[Col('text', nullable: true, comment: '加密后的密钥载荷')]
    public const schema_fields_SECRET_PAYLOAD = 'secret_payload';

    #[Col('varchar', 64, nullable: false, default: '', comment: '密钥指纹')]
    public const schema_fields_SECRET_FINGERPRINT = 'secret_fingerprint';

    #[Col('varchar', 64, nullable: false, default: '', comment: '状态')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 64, nullable: false, default: '', comment: '最近错误码')]
    public const schema_fields_LAST_ERROR_CODE = 'last_error_code';
}
