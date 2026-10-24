<?php
declare(strict_types=1);

namespace Weline\App\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'App 构建配置表')]
#[Index(name: 'uk_website', columns: ['website_id'], type: 'UNIQUE', comment: '网站唯一索引')]
class AppBuild extends Model
{
    public const schema_table = 'weline_app_build';
    public const schema_primary_key = 'id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '构建ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'int', nullable: false, comment: '网站ID')]
    public const schema_fields_website_id = 'website_id';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '绑定域名（空串=通用包）')]
    public const schema_fields_domain = 'domain';

    #[Col(type: 'text', nullable: false, comment: '平台 JSON 数组')]
    public const schema_fields_platforms = 'platforms';

    #[Col(type: 'tinyint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_enabled = 'enabled';

    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';
}
