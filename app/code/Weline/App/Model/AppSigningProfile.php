<?php
declare(strict_types=1);

namespace Weline\App\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'App 签名配置表')]
#[Index(name: 'uk_scope_platform', columns: ['scope_website_id', 'platform'], type: 'UNIQUE', comment: '范围平台唯一索引')]
class AppSigningProfile extends Model
{
    public const schema_table = 'weline_app_signing_profile';
    public const schema_primary_key = 'id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '站点ID（0=全局）')]
    public const schema_fields_scope_website_id = 'scope_website_id';

    #[Col(type: 'varchar', length: 16, nullable: false, comment: '平台')]
    public const schema_fields_platform = 'platform';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '包名')]
    public const schema_fields_bundle_id = 'bundle_id';

    #[Col(type: 'text', nullable: true, comment: '密文引用')]
    public const schema_fields_keystore_ref = 'keystore_ref';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '密钥别名')]
    public const schema_fields_keystore_alias = 'keystore_alias';

    #[Col(type: 'text', nullable: true, comment: '密钥库密码密文')]
    public const schema_fields_keystore_password_enc = 'keystore_password_enc';

    #[Col(type: 'text', nullable: true, comment: '密钥密码密文')]
    public const schema_fields_key_password_enc = 'key_password_enc';

    #[Col(type: 'text', nullable: true, comment: '证书密文引用')]
    public const schema_fields_cert_ref = 'cert_ref';

    #[Col(type: 'text', nullable: true, comment: '描述文件密文引用')]
    public const schema_fields_provisioning_profile_ref = 'provisioning_profile_ref';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '团队ID')]
    public const schema_fields_team_id = 'team_id';

    #[Col(type: 'varchar', length: 16, nullable: false, default: 'development', comment: '导出方式（development|ad-hoc|app-store）')]
    public const schema_fields_export_method = 'export_method';

    #[Col(type: 'varchar', length: 16, nullable: false, default: 'active', comment: '状态（active|retired）')]
    public const schema_fields_status = 'status';

    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';
}
