<?php

declare(strict_types=1);

namespace Weline\Faq\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '万能 FAQ：实体问答条目')]
#[Index(name: 'idx_faq_item_entity_status', columns: ['type_code', 'entity_uuid', 'status', 'sort_order'])]
#[Index(name: 'idx_faq_item_scope', columns: ['website_id', 'store_code', 'channel_code', 'locale_code', 'status'])]
#[Index(
    name: 'idx_faq_item_scope_key_unique',
    columns: ['type_code', 'entity_uuid', 'website_id', 'store_code', 'channel_code', 'locale_code', 'faq_key'],
    type: 'UNIQUE',
)]
class FaqItem extends Model
{
    public const schema_table = 'weline_faq_item';
    public const schema_primary_key = 'faq_id';

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'FAQ ID')]
    public const schema_fields_ID = 'faq_id';
    #[Col('int', nullable: false, default: 0, comment: '网站 ID（0=全局默认）')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 64, nullable: false, default: '', comment: '店铺 code（空=网站层）')]
    public const schema_fields_STORE_CODE = 'store_code';
    #[Col('varchar', 64, nullable: false, default: '', comment: '渠道 code（空=店铺/网站层）')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';
    #[Col('varchar', 32, nullable: false, default: '', comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = 'locale_code';
    #[Col('varchar', 64, nullable: false, comment: '类型编码（product/site/template/…）')]
    public const schema_fields_TYPE_CODE = 'type_code';
    #[Col('varchar', 64, nullable: false, comment: '实体全局 UUID / 模板 pack')]
    public const schema_fields_ENTITY_UUID = 'entity_uuid';
    #[Col('varchar', 64, nullable: false, default: '', comment: '稳定 FAQ 键（同 type+entity 内唯一于 scope）')]
    public const schema_fields_FAQ_KEY = 'faq_key';
    #[Col('text', nullable: false, comment: '问题')]
    public const schema_fields_QUESTION = 'question';
    #[Col('text', nullable: false, comment: '答案')]
    public const schema_fields_ANSWER = 'answer';
    #[Col('int', nullable: false, default: 0, comment: '排序（升序）')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('varchar', 16, nullable: false, default: 'enabled', comment: '状态 enabled/disabled（下级 disabled=抑制该 key）')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_TYPE_CODE,
        self::schema_fields_ENTITY_UUID,
        self::schema_fields_STATUS,
        self::schema_fields_SORT_ORDER,
    ];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_id_field_name = self::schema_fields_ID;
    }
}
