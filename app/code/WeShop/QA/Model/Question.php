<?php
declare(strict_types=1);
namespace WeShop\QA\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 问题模型
 */
#[Table(comment: 'WeShop问题表')]
#[Index(name: 'idx_product_id', columns: ['product_id'], comment: '产品ID索引')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], comment: '客户ID索引')]
#[Index(name: 'idx_product_status_sort', columns: ['product_id', 'status', 'is_recommended', 'created_at'], comment: '产品问答分页排序索引')]
class Question extends Model
{
    public const schema_table = 'weshop_question';
    public const schema_primary_key = 'question_id';
    public string $indexer = 'question_indexer';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '问题ID')]
    public const schema_fields_ID = 'question_id';
    #[Col(type: 'int', nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'int', nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'int', nullable: true, default: 0, comment: '关联订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'customer', comment: '问答来源类型')]
    public const schema_fields_SOURCE_TYPE = 'source_type';
    #[Col(type: 'smallint', nullable: false, default: 0, comment: '是否匿名')]
    public const schema_fields_IS_ANONYMOUS = 'is_anonymous';
    #[Col(type: 'smallint', nullable: false, default: 0, comment: '是否推荐')]
    public const schema_fields_IS_RECOMMENDED = 'is_recommended';
    #[Col(type: 'varchar', length: 120, nullable: true, comment: '展示名称')]
    public const schema_fields_DISPLAY_NAME = 'display_name';
    #[Col(type: 'text', nullable: true, comment: '提及客户ID JSON')]
    public const schema_fields_MENTIONED_CUSTOMER_IDS = 'mentioned_customer_ids';
    #[Col(type: 'text', nullable: false, comment: '问题')]
    public const schema_fields_QUESTION = 'question';
    #[Col(type: 'text', nullable: true, comment: '答案')]
    public const schema_fields_ANSWER = 'answer';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public array $_unit_primary_keys = ['question_id'];
    public array $_index_sort_keys = ['product_id', 'customer_id', 'status', 'source_type', 'is_recommended', 'created_at'];
}
