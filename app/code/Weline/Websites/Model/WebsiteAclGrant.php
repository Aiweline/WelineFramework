<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 网站级 ACL/菜单授权包：非默认站后台能力天花板。
 * website_id=0（默认站）禁止写入。
 */
#[Table(comment: '网站 ACL 授权包')]
#[Index(name: 'uk_website_source', columns: ['website_id', 'source_id'], type: 'UNIQUE', comment: '站+资源唯一')]
#[Index(name: 'idx_website_acl_grant_website', columns: ['website_id'], comment: '按站查询')]
class WebsiteAclGrant extends Model
{
    public const schema_table = 'weline_websites_acl_grant';
    public const schema_primary_key = 'grant_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '授权ID')]
    public const schema_fields_ID = 'grant_id';

    #[Col('int', nullable: false, comment: '网站ID（禁止 0）')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 255, nullable: false, comment: 'ACL source_id')]
    public const schema_fields_SOURCE_ID = 'source_id';

    #[Col('datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
}
