<?php
declare(strict_types=1);

namespace Weline\App\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'App 设备推送令牌表')]
#[Index(name: 'uk_site_platform_token', columns: ['website_id', 'platform', 'token_hash'], type: 'UNIQUE', comment: '站点平台令牌唯一索引')]
#[Index(name: 'idx_site_enabled', columns: ['website_id', 'enabled'], comment: '站点启用索引')]
class AppDeviceToken extends Model
{
    public const schema_table = 'weline_app_device_token';
    public const schema_primary_key = 'id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'int', nullable: false, comment: '站点ID')]
    public const schema_fields_website_id = 'website_id';

    #[Col(type: 'varchar', length: 16, nullable: false, comment: '平台（android|ios）')]
    public const schema_fields_platform = 'platform';

    #[Col(type: 'varchar', length: 512, nullable: false, comment: '令牌')]
    public const schema_fields_token = 'token';

    #[Col(type: 'char', length: 64, nullable: false, comment: 'token 的 sha256')]
    public const schema_fields_token_hash = 'token_hash';

    #[Col(type: 'varchar', length: 16, nullable: false, default: '', comment: '语言')]
    public const schema_fields_locale = 'locale';

    #[Col(type: 'varchar', length: 8, nullable: false, default: '', comment: '货币')]
    public const schema_fields_currency = 'currency';

    #[Col(type: 'int', nullable: false, default: 0, comment: '客户ID（0=游客）')]
    public const schema_fields_customer_id = 'customer_id';

    #[Col(type: 'varchar', length: 32, nullable: false, default: '', comment: '应用版本')]
    public const schema_fields_app_version = 'app_version';

    #[Col(type: 'varchar', length: 32, nullable: false, default: '', comment: '系统版本')]
    public const schema_fields_os_version = 'os_version';

    #[Col(type: 'tinyint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_enabled = 'enabled';

    #[Col(type: 'datetime', nullable: false, comment: '最后活跃时间')]
    public const schema_fields_last_seen_at = 'last_seen_at';

    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';
}
