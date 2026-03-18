<?php
declare(strict_types=1);

/**
 * 域名池处理流转记录（解析 → 源站 → 证书 → 建站）
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '域名池流转记录')]
#[Index(name: 'idx_pool_created', columns: ['pool_id', 'created_at'])]
class DomainPoolFlowLog extends Model
{
    public const schema_table = 'weline_websites_domain_pool_flow_log';
    public const schema_primary_key = 'log_id';

    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true)]
    public const schema_fields_ID = 'log_id';

    #[Col('int', 11, nullable: false, comment: '域名池ID')]
    public const schema_fields_POOL_ID = 'pool_id';

    #[Col('varchar', 40, nullable: false, comment: '事件类型')]
    public const schema_fields_EVENT_KIND = 'event_kind';

    #[Col('text', nullable: true, comment: '说明')]
    public const schema_fields_MESSAGE = 'message';

    #[Col('datetime', nullable: true, comment: '时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const KIND_POOL_CREATED = 'pool_created';
    public const KIND_STAGE_CHANGE = 'stage_change';
    public const KIND_RESOLVE_CHECK = 'resolve_check';
    public const KIND_CERT_START = 'cert_start';
    public const KIND_CERT_OK = 'cert_ok';
    public const KIND_CERT_FAIL = 'cert_fail';
    public const KIND_CERT_INVALID = 'cert_invalid';
    public const KIND_SITE_READY = 'site_ready';

    public function save_before(): void
    {
        parent::save_before();
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'));
        }
    }
}
