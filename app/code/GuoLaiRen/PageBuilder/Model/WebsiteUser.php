<?php
declare(strict_types=1);
namespace GuoLaiRen\PageBuilder\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 页面构建器 - 站点与后台用户一对一映射
 *
 * 约束：
 * - 一个站点同一时间只能分配给一个后台用户
 * - 后续分配给新的用户时，会自动替换之前的分配关系
 */
#[Table(comment: '页面构建器-站点用户一对一映射表')]
#[Index(name: 'uniq_website', columns: ['website_id'], type: 'UNIQUE', comment: '站点唯一分配约束')]
class WebsiteUser extends Model
{
    public const schema_table = 'guolairen_page_builder_website_user';
    public const schema_primary_key = 'id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'int', nullable: false, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'int', nullable: false, comment: '后台用户ID')]
    public const schema_fields_BACKEND_USER_ID = 'backend_user_id';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否站点创建者')]
    public const schema_fields_IS_OWNER = 'is_owner';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
}
