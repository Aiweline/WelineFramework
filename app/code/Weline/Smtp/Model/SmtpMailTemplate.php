<?php

declare(strict_types=1);

namespace Weline\Smtp\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'SMTP 发信渠道邮件模板')]
#[Index(name: 'uk_smtp_mail_template_channel_scope_locale', columns: ['channel_code', 'storage_scope', 'locale'], type: 'UNIQUE')]
#[Index(name: 'idx_smtp_mail_template_scope', columns: ['storage_scope', 'locale'], type: 'DEFAULT')]
class SmtpMailTemplate extends Model
{
    public const schema_table = 'weline_smtp_mail_template';
    public const schema_primary_key = 'template_id';

    public const SOURCE_MODULE = 'module';
    public const SOURCE_CUSTOM = 'custom';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '模板 ID')]
    public const schema_fields_ID = 'template_id';

    #[Col(type: 'varchar', length: 191, nullable: false, comment: '发信渠道 code')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';

    #[Col(type: 'varchar', length: 191, nullable: false, default: 'default.default.default', comment: 'storage_scope')]
    public const schema_fields_STORAGE_SCOPE = 'storage_scope';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: '真实 locale，如 zh_Hans_CN')]
    public const schema_fields_LOCALE = 'locale';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '邮件主题')]
    public const schema_fields_SUBJECT = 'subject';

    #[Col(type: 'longtext', nullable: false, comment: 'HTML 正文')]
    public const schema_fields_BODY_HTML = 'body_html';

    #[Col(type: 'mediumtext', nullable: true, comment: '纯文本正文')]
    public const schema_fields_BODY_TEXT = 'body_text';

    #[Col(type: 'varchar', length: 16, nullable: false, default: self::SOURCE_MODULE, comment: 'module|custom')]
    public const schema_fields_SOURCE = 'source';

    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否仍跟随 Extends 默认')]
    public const schema_fields_USE_DEFAULT = 'use_default';

    #[Col(type: 'varchar', length: 64, nullable: true, default: '', comment: '默认正文 hash')]
    public const schema_fields_SEED_HASH = 'seed_hash';

    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['template_id'];
}
