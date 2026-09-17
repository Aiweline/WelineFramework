<?php
declare(strict_types=1);

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 转化事件去重账本：website + event + business_key 唯一，TTL 内重复丢弃。
 */
#[Table(comment: '像素转化事件去重账本')]
#[Index(name: 'uniq_pixel_conversion_dedupe', columns: ['website_id', 'event', 'business_key'], type: 'UNIQUE')]
#[Index(name: 'idx_pixel_conversion_dedupe_expires', columns: ['expires_at'])]
class PixelConversionDedupe extends Model
{
    public const schema_table = 'w_pixel_conversion_dedupe';
    public const schema_primary_key = 'dedupe_id';

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '去重ID')]
    public const schema_fields_ID = 'dedupe_id';

    #[Col('int', 0, nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, default: '', comment: '事件名')]
    public const schema_fields_EVENT = 'event';

    #[Col('varchar', 191, nullable: false, default: '', comment: '业务键')]
    public const schema_fields_BUSINESS_KEY = 'business_key';

    #[Col('datetime', nullable: false, comment: '首次计入时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, comment: '过期时间')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
}
