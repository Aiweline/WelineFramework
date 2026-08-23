<?php

declare(strict_types=1);

namespace Weline\Review\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '通用评论模块：商品类型评论')]
#[Index(name: 'idx_review_product_entity_status_time', columns: ['entity_uuid', 'status', 'created_at'])]
#[Index(name: 'idx_review_product_scope_status', columns: ['website_id', 'store_id', 'status'])]
#[Index(name: 'idx_review_product_customer_status', columns: ['customer_id', 'status'])]
class ProductReview extends Model
{
    public const schema_table = 'weline_review_product';
    public const schema_primary_key = 'review_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_AI_PENDING_BLOCKED = 'ai_pending_blocked';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '评论 ID')]
    public const schema_fields_ID = 'review_id';
    #[Col('int', nullable: false, comment: '类型实体内部 ID')]
    public const schema_fields_ENTITY_ID = 'entity_id';
    #[Col('varchar', 36, nullable: false, comment: '类型实体全局 UUID')]
    public const schema_fields_ENTITY_UUID = 'entity_uuid';
    #[Col('int', nullable: false, default: 0, comment: '网站 ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', nullable: false, default: 0, comment: '店铺 ID')]
    public const schema_fields_STORE_ID = 'store_id';
    #[Col('int', nullable: true, comment: '登录客户 ID，游客为空')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('varchar', 120, nullable: true, comment: '展示名称')]
    public const schema_fields_REVIEWER_NAME = 'reviewer_name';
    #[Col('varchar', 190, nullable: true, comment: '游客联系邮箱，不公开')]
    public const schema_fields_REVIEWER_EMAIL = 'reviewer_email';
    #[Col('boolean', nullable: false, default: 0, comment: '是否匿名展示')]
    public const schema_fields_IS_ANONYMOUS = 'is_anonymous';
    #[Col('smallint', nullable: false, default: 5, comment: '评分 1-5')]
    public const schema_fields_RATING = 'rating';
    #[Col('varchar', 120, nullable: true, comment: '评论标题')]
    public const schema_fields_TITLE = 'title';
    #[Col('text', nullable: false, comment: '评论正文')]
    public const schema_fields_CONTENT = 'content';
    #[Col('json', nullable: true, comment: '类型扩展字段快照')]
    public const schema_fields_EXTRA = 'extra_json';
    #[Col('varchar', 32, nullable: false, default: 'pending', comment: '审核状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', nullable: false, default: 1, comment: '字段定义版本')]
    public const schema_fields_SCHEMA_VERSION = 'schema_version';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ENTITY_UUID, self::schema_fields_STATUS, self::schema_fields_CREATED_AT];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_id_field_name = self::schema_fields_ID;
    }
}
