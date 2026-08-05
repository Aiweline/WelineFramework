<?php
declare(strict_types=1);

namespace Weline\Smtp\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'SMTP 发送日志表')]
// PG 现网为 BTREE；FULLTEXT 在 PG 映射为 GIN 会导致 setup:upgrade 定义不一致。
// 发件人/收件人/模块检索用 BTREE 足够（跨 MySQL/PG 可对齐）。
#[Index(name: 'FROM_EMAIL', columns: ['from_email'], type: 'DEFAULT', method: 'BTREE')]
#[Index(name: 'TO_EMAIL', columns: ['to_email'], type: 'DEFAULT', method: 'BTREE')]
#[Index(name: 'SEND_MODULE', columns: ['module'], type: 'DEFAULT', method: 'BTREE')]
class SmtpSendLog extends Model
{
    public const schema_table = 'weline_smtp_send_log';
    public const schema_primary_key = 'id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '发送者邮箱')]
    public const schema_fields_FROM_EMAIL = 'from_email';
    #[Col(type: 'varchar', length: 30, nullable: false, comment: '发送者昵称')]
    public const schema_fields_SENDER_NAME = 'sender_name';
    #[Col(type: 'mediumtext', nullable: false, comment: '接收者邮箱')]
    public const schema_fields_TO_EMAIL = 'to_email';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '代理发送者')]
    public const schema_fields_PROXY = 'proxy';
    #[Col(type: 'mediumtext', nullable: true, comment: '回复')]
    public const schema_fields_REPLY_TO = 'reply_to';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '邮件标题')]
    public const schema_fields_SUBJECT = 'subject';
    #[Col(type: 'longtext', nullable: false, comment: '邮件内容')]
    public const schema_fields_CONTENT = 'content';
    #[Col(type: 'mediumtext', nullable: true, comment: '邮件签名')]
    public const schema_fields_ALT = 'alt';
    #[Col(type: 'mediumtext', nullable: true, comment: '抄送')]
    public const schema_fields_CC = 'cc';
    #[Col(type: 'mediumtext', nullable: true, comment: '密送')]
    public const schema_fields_BCC = 'bcc';
    #[Col(type: 'smallint', length: 1, default: 1, comment: '是否HTML')]
    public const schema_fields_IS_HTML = 'is_html';
    #[Col(type: 'mediumtext', nullable: true, comment: '附件')]
    public const schema_fields_ATTACHMENT = 'attachment';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '模组')]
    public const schema_fields_MODULE = 'module';
}
