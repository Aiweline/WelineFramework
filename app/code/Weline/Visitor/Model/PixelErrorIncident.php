<?php
declare(strict_types=1);

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 全站像素错误现场（事故包）。邮箱仅存本表，不进营销像素元数据。
 */
#[Table(comment: '像素错误监控事故表')]
#[Index(name: 'idx_pixel_error_website_created', columns: ['website_id', 'created_at'])]
#[Index(name: 'idx_pixel_error_type_created', columns: ['error_type', 'created_at'])]
#[Index(name: 'idx_pixel_error_disposition', columns: ['disposition', 'created_at'])]
#[Index(name: 'idx_pixel_error_stale', columns: ['stale_client', 'created_at'])]
#[Index(name: 'idx_pixel_error_session', columns: ['session_id', 'created_at'])]
#[Index(name: 'idx_pixel_error_fingerprint', columns: ['website_id', 'fingerprint', 'created_at'])]
#[Index(name: 'idx_pixel_error_pixel_id', columns: ['pixel_id'])]
class PixelErrorIncident extends Model
{
    public const schema_table = 'w_pixel_error_incident';
    public const schema_primary_key = 'incident_id';

    public const DISPOSITION_OPEN = 'open';
    public const DISPOSITION_IGNORED_STALE = 'ignored_stale';
    public const DISPOSITION_RESOLVED = 'resolved';

    public const IDENTITY_VISITOR = 'visitor';
    public const IDENTITY_EMAIL = 'email';
    public const IDENTITY_USER = 'user';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_URGENT = 'urgent';

    /** @var list<string> */
    public const DISPOSITIONS = [
        self::DISPOSITION_OPEN,
        self::DISPOSITION_IGNORED_STALE,
        self::DISPOSITION_RESOLVED,
    ];

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '事故ID')]
    public const schema_fields_ID = 'incident_id';

    #[Col('bigint', 0, nullable: false, default: 0, comment: '关联像素ID')]
    public const schema_fields_PIXEL_ID = 'pixel_id';

    #[Col('int', 0, nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, default: '', comment: '像素会话ID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col('varchar', 64, nullable: false, comment: '错误类型 topic_code')]
    public const schema_fields_ERROR_TYPE = 'error_type';

    #[Col('varchar', 128, nullable: false, default: '', comment: '业务 error_code')]
    public const schema_fields_ERROR_CODE = 'error_code';

    #[Col('varchar', 16, nullable: false, default: self::SEVERITY_ERROR, comment: '严重级别')]
    public const schema_fields_SEVERITY = 'severity';

    #[Col('varchar', 32, nullable: false, default: self::DISPOSITION_OPEN, comment: '处置状态')]
    public const schema_fields_DISPOSITION = 'disposition';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: '客户端版本落后')]
    public const schema_fields_STALE_CLIENT = 'stale_client';

    #[Col('varchar', 16, nullable: false, default: self::IDENTITY_VISITOR, comment: '身份种类')]
    public const schema_fields_IDENTITY_KIND = 'identity_kind';

    #[Col('varchar', 255, nullable: true, comment: '识别邮箱（仅事故表）')]
    public const schema_fields_IDENTITY_EMAIL = 'identity_email';

    #[Col('int', 0, nullable: false, default: 0, comment: '登录用户ID')]
    public const schema_fields_USER_ID = 'user_id';

    #[Col('varchar', 128, nullable: false, default: '', comment: '客户端 deploy_version')]
    public const schema_fields_CLIENT_DEPLOY_VERSION = 'client_deploy_version';

    #[Col('varchar', 128, nullable: false, default: '', comment: '服务端 deploy_version')]
    public const schema_fields_SERVER_DEPLOY_VERSION = 'server_deploy_version';

    #[Col('varchar', 128, nullable: false, default: '', comment: '客户端 worker_build_id')]
    public const schema_fields_WORKER_BUILD_ID = 'worker_build_id';

    #[Col('varchar', 64, nullable: true, comment: '主题已发布版本ID（可空）')]
    public const schema_fields_THEME_PUBLISHED_VERSION_ID = 'theme_published_version_id';

    #[Col('varchar', 128, nullable: true, comment: '主题已发布版本号/名（可空）')]
    public const schema_fields_THEME_PUBLISHED_VERSION = 'theme_published_version';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'pixel.js 脚本版本')]
    public const schema_fields_PIXEL_SCRIPT_VERSION = 'pixel_script_version';

    #[Col('varchar', 32, nullable: false, default: '', comment: '事件字典版本')]
    public const schema_fields_DICT_VERSION = 'dict_version';

    #[Col('varchar', 512, nullable: false, default: '', comment: '页面 URL')]
    public const schema_fields_PAGE_URL = 'page_url';

    #[Col('text', comment: '步骤短路径 JSON')]
    public const schema_fields_STEP_PATH_JSON = 'step_path_json';

    #[Col('text', comment: '脱敏表单快照 JSON')]
    public const schema_fields_FORM_SNAPSHOT_JSON = 'form_snapshot_json';

    #[Col('varchar', 1024, nullable: false, default: '', comment: '错误消息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';

    #[Col('text', comment: '错误栈摘要')]
    public const schema_fields_ERROR_STACK = 'error_stack';

    #[Col('varchar', 64, nullable: false, default: '', comment: '去重指纹')]
    public const schema_fields_FINGERPRINT = 'fingerprint';

    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
