<?php

declare(strict_types=1);

namespace Amayum\Website\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Amayum 官网诊断询盘')]
#[Index(name: 'idx_amayum_inquiry_email', columns: ['email'])]
#[Index(name: 'idx_amayum_inquiry_status_created', columns: ['status', 'created_at'])]
class Inquiry extends Model
{
    public const schema_table = 'amayum_website_inquiry';
    public const schema_primary_key = 'inquiry_id';

    public const STATUS_NEW = 'new';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '询盘 ID')]
    public const schema_fields_ID = 'inquiry_id';
    #[Col('varchar', 80, nullable: false, comment: '姓名')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 254, nullable: false, comment: '工作邮箱')]
    public const schema_fields_EMAIL = 'email';
    #[Col('varchar', 120, nullable: false, default: '', comment: '公司/站点')]
    public const schema_fields_COMPANY = 'company';
    #[Col('varchar', 32, nullable: false, default: '', comment: '当前栈')]
    public const schema_fields_STACK = 'stack';
    #[Col('varchar', 32, nullable: false, default: '', comment: '目标市场')]
    public const schema_fields_MARKET = 'market';
    #[Col('varchar', 80, nullable: false, default: '', comment: '月订单量或 GMV 区间')]
    public const schema_fields_VOLUME_BAND = 'volume_band';
    #[Col('varchar', 80, nullable: false, default: '', comment: '计划上线时间')]
    public const schema_fields_TIMELINE = 'timeline';
    #[Col('text', nullable: false, comment: '当前最大卡点')]
    public const schema_fields_MESSAGE = 'message';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '同意条款')]
    public const schema_fields_CONSENT = 'consent';
    #[Col('varchar', 16, nullable: false, default: 'zh-CN', comment: '语言')]
    public const schema_fields_LOCALE = 'locale';
    #[Col('varchar', 255, nullable: false, default: '/diagnosis', comment: '来源路径')]
    public const schema_fields_SOURCE_PATH = 'source_path';
    #[Col('varchar', 64, nullable: false, default: '', comment: 'IP 哈希（不存明文）')]
    public const schema_fields_IP_HASH = 'ip_hash';
    #[Col('varchar', 255, nullable: false, default: '', comment: 'User-Agent')]
    public const schema_fields_USER_AGENT = 'user_agent';
    #[Col('varchar', 16, nullable: false, default: self::STATUS_NEW, comment: '状态 new/sent/failed')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: false, comment: '创建时间 UTC')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: '邮件接受时间 UTC')]
    public const schema_fields_SENT_AT = 'sent_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_id_field_name = self::schema_fields_ID;
    }

    public function save_before(): void
    {
        parent::save_before();

        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, \gmdate('Y-m-d H:i:s'));
        }
    }
}
