<?php

declare(strict_types=1);

namespace WeShop\Notification\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 通知模型
 */
#[Table(comment: 'WeShop通知表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'KEY', comment: '客户ID索引')]
#[Index(name: 'idx_is_read', columns: ['is_read'], type: 'KEY', comment: '已读状态索引')]
class Notification extends Model
{
    public const schema_table = 'weshop_notification';
    public const schema_primary_key = 'notification_id';
    public string $indexer = 'notification_indexer';

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '通知ID')]
    public const schema_fields_ID = 'notification_id';
    #[Col('int', 0, nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('varchar', 50, nullable: false, comment: '类型')]
    public const schema_fields_TYPE = 'type';
    #[Col('varchar', 255, nullable: false, comment: '标题')]
    public const schema_fields_TITLE = 'title';
    #[Col('text', 0, nullable: true, comment: '内容')]
    public const schema_fields_CONTENT = 'content';
    #[Col('varchar', 500, nullable: true, comment: '目标链接')]
    public const schema_fields_TARGET_URL = 'target_url';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否已读')]
    public const schema_fields_IS_READ = 'is_read';
    #[Col('datetime', 0, nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['notification_id'];
    public array $_index_sort_keys = ['customer_id', 'type', 'is_read', 'created_at'];
}
