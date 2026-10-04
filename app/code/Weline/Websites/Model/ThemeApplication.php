<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 网站拥有的应用引用存储，不保存 Theme 内容、部件或资产激活字段。 */
#[Table(comment: '网站范围主题应用引用')]
#[Index(name: 'uk_websites_theme_application_identity', columns: ['identity_hash'], type: 'UNIQUE')]
class ThemeApplication extends Model
{
    public const use_main_db_master = true;
    public const schema_table = 'websites_theme_application';
    public const schema_primary_key = 'application_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: '应用引用ID')]
    public const schema_fields_ID = 'application_id';
    #[Col('varchar', 64, nullable: false, comment: '准确范围键、模式与区域的SHA256')]
    public const schema_fields_IDENTITY_HASH = 'identity_hash';
    #[Col('varchar', 512, nullable: false, comment: '权威ScopeIdentity规范键，保留范围类型')]
    public const schema_fields_SCOPE_KEY = 'scope_key';
    #[Col('varchar', 16, nullable: false, default: 'normal', comment: '明确应用模式，全局与网站也独立保存')]
    public const schema_fields_STORE_MODE = 'store_mode';
    #[Col('varchar', 16, nullable: false, comment: '前台或后台区域')]
    public const schema_fields_AREA = 'area';
    #[Col('longtext', nullable: true, comment: '准确主题与内容版本owner引用，null表示跟随上级')]
    public const schema_fields_REFERENCE_JSON = 'reference_json';
    #[Col('bigint', 20, nullable: false, default: 0, comment: '应用实际修订，移除引用后仍保留')]
    public const schema_fields_REVISION = 'revision';
    #[Col('longtext', nullable: true, comment: '迁移与审计来源ID，不作为并行运行权威')]
    public const schema_fields_METADATA_JSON = 'metadata_json';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'UTC更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
