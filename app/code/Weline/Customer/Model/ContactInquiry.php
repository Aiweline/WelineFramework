<?php

declare(strict_types=1);

namespace Weline\Customer\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '前端客户联系表单')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], comment: '客户ID索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_created_at', columns: ['created_at'], comment: '创建时间索引')]
class ContactInquiry extends Model
{
    public const schema_table = 'weline_customer_contact_inquiry';
    public const schema_primary_key = 'inquiry_id';

    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const CATEGORY_ORDER = 'order';
    public const CATEGORY_RETURN = 'return';
    public const CATEGORY_SHIPPING = 'shipping';
    public const CATEGORY_ACCOUNT = 'account';
    public const CATEGORY_PRODUCT = 'product';
    public const CATEGORY_COOPERATION = 'cooperation';
    public const CATEGORY_OTHER = 'other';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'inquiry_id';
    #[Col(type: 'int', nullable: true, comment: '客户ID')]
    public const schema_fields_customer_id = 'customer_id';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '联系人姓名')]
    public const schema_fields_name = 'name';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '联系邮箱')]
    public const schema_fields_email = 'email';
    #[Col(type: 'varchar', length: 32, nullable: true, comment: '联系电话')]
    public const schema_fields_phone = 'phone';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: '订单号')]
    public const schema_fields_order_number = 'order_number';
    #[Col(type: 'varchar', length: 32, nullable: false, default: self::CATEGORY_OTHER, comment: '咨询分类')]
    public const schema_fields_category = 'category';
    #[Col(type: 'varchar', length: 150, nullable: false, comment: '主题')]
    public const schema_fields_subject = 'subject';
    #[Col(type: 'text', nullable: false, comment: '留言内容')]
    public const schema_fields_message = 'message';
    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_NEW, comment: '处理状态')]
    public const schema_fields_status = 'status';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '来源页面')]
    public const schema_fields_source_url = 'source_url';
    #[Col(type: 'varchar', length: 45, nullable: true, comment: '提交IP')]
    public const schema_fields_ip = 'ip_address';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '用户代理')]
    public const schema_fields_user_agent = 'user_agent';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_status, self::schema_fields_created_at];

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_NEW => __('待处理'),
            self::STATUS_PROCESSING => __('处理中'),
            self::STATUS_RESOLVED => __('已解决'),
            self::STATUS_CLOSED => __('已关闭'),
        ];
    }

    public static function getCategoryOptions(): array
    {
        return [
            self::CATEGORY_ORDER => __('订单问题'),
            self::CATEGORY_RETURN => __('退换货'),
            self::CATEGORY_SHIPPING => __('物流配送'),
            self::CATEGORY_ACCOUNT => __('账户访问'),
            self::CATEGORY_PRODUCT => __('商品咨询'),
            self::CATEGORY_COOPERATION => __('商务合作'),
            self::CATEGORY_OTHER => __('其他问题'),
        ];
    }
}
