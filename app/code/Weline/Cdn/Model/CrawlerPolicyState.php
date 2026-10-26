<?php

declare(strict_types=1);

namespace Weline\Cdn\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Per-domain edge sync state for the website crawler policy.
 */
#[Table(comment: '爬虫策略边缘同步状态')]
#[Index(name: 'uk_cdn_crawler_policy_scope', columns: ['domain_id', 'adapter'], type: 'UNIQUE')]
#[Index(name: 'idx_cdn_crawler_policy_website', columns: ['website_id'])]
class CrawlerPolicyState extends Model
{
    public const schema_table = 'cdn_crawler_policy_state';
    public const schema_primary_key = 'state_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_DRIFT = 'drift';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public string $_primary_key = 'state_id';

    public array $_unit_primary_keys = ['state_id'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '状态ID')]
    public const schema_fields_ID = 'state_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'CDN域名ID')]
    public const schema_fields_DOMAIN_ID = 'domain_id';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'cloudflare', comment: '适配器')]
    public const schema_fields_ADAPTER = 'adapter';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Zone ID')]
    public const schema_fields_ZONE_ID = 'zone_id';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '目标hash')]
    public const schema_fields_DESIRED_HASH = 'desired_hash';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '已应用hash')]
    public const schema_fields_APPLIED_HASH = 'applied_hash';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'text', nullable: true, comment: '最近错误')]
    public const schema_fields_LAST_ERROR = 'last_error';
    #[Col(type: 'int', nullable: false, default: 0, comment: '尝试次数')]
    public const schema_fields_ATTEMPTS = 'attempts';
    #[Col(type: 'datetime', nullable: true, comment: '同步时间')]
    public const schema_fields_SYNCED_AT = 'synced_at';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
