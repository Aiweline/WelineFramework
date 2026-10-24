<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 已扫描页面台账：按站点记录看过的页面，供后续运行跳过，避免重复检索与重复抓取。
 *
 * url_hash 存归一化地址（host+path，忽略协议/查询串/锚点）的 sha256，保证同页不同参数只记一次。
 */
#[Table(comment: 'AI获客已扫描页面')]
#[Index(name: 'idx_ai_acquisition_scan_unique', columns: ['website_id', 'url_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_ai_acquisition_scan_site_time', columns: ['website_id', 'created_at'])]
#[Index(name: 'idx_ai_acquisition_scan_domain', columns: ['website_id', 'domain'])]
class Scan extends Model
{
    public const schema_table = 'ai_acquisition_scan';
    public const schema_primary_key = 'id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';

    #[Col('int', default: 0, comment: '归属网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('char', 64, nullable: false, comment: '归一化地址摘要')]
    public const schema_fields_URL_HASH = 'url_hash';

    #[Col('varchar', 500, default: '', comment: '原始地址')]
    public const schema_fields_URL = 'url';

    #[Col('varchar', 200, default: '', comment: '归一化域名')]
    public const schema_fields_DOMAIN = 'domain';

    #[Col('int', default: 0, comment: '首次扫描的运行ID')]
    public const schema_fields_RUN_ID = 'run_id';

    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
