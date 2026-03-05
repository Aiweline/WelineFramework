<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Order\Model;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 订单状态翻译模型 - 存储订单状态的多语言翻译 */
#[Table(comment: '订单状态翻译表')]
#[Index(name: 'idx_status_locale', columns: ['status_code', 'locale'], type: 'UNIQUE')]
#[Index(name: 'idx_status_code', columns: ['status_code'])]
#[Index(name: 'idx_locale', columns: ['locale'])]
class OrderStatusTranslation extends AbstractModel
{
    public const schema_table = 'weline_order_status_translation';
    public const schema_primary_key = 'translation_id';
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '翻译ID')]
    public const schema_fields_ID = 'translation_id';
    #[Col('varchar', 50, nullable: false, comment: '状态代码')]
    public const schema_fields_STATUS_CODE = 'status_code';
    #[Col('varchar', 20, nullable: false, comment: '语言代码')]
    public const schema_fields_LOCALE = 'locale';
    #[Col('varchar', 100, nullable: false, comment: '状态名称翻译')]
    public const schema_fields_NAME = 'name';
    #[Col('text', comment: '状态描述翻译')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['translation_id'];
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['translation_id', 'status_code', 'locale'];
}
