<?php
declare(strict_types=1);

namespace Weline\Mail\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '企业邮箱测试邮件表')]
#[Index(name: 'idx_weline_mail_message_account_folder', columns: ['account_id', 'folder'])]
#[Index(name: 'idx_weline_mail_message_created', columns: ['created_at'])]
class MailMessage extends Model
{
    public const schema_table = 'weline_mail_message';
    public const schema_primary_key = 'message_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '邮件ID')]
    public const schema_fields_ID = 'message_id';

    #[Col('int', nullable: false, comment: '邮箱账号ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';

    #[Col('varchar', 16, nullable: false, comment: '邮件夹')]
    public const schema_fields_FOLDER = 'folder';

    #[Col('varchar', 255, nullable: false, comment: '发件人')]
    public const schema_fields_FROM_EMAIL = 'from_email';

    #[Col('varchar', 255, nullable: false, comment: '收件人')]
    public const schema_fields_TO_EMAIL = 'to_email';

    #[Col('varchar', 180, nullable: false, comment: '主题')]
    public const schema_fields_SUBJECT = 'subject';

    #[Col('text', nullable: true, comment: '正文')]
    public const schema_fields_BODY = 'body';

    #[Col('smallint', default: 0, comment: '是否已读')]
    public const schema_fields_IS_READ = 'is_read';

    #[Col('varchar', 32, default: 'delivered', comment: '投递状态')]
    public const schema_fields_DELIVERY_STATUS = 'delivery_status';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
}
