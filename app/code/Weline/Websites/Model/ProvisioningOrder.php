<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 一站式配置订单。物理表名 `saas_provisioning_order` 为历史命名，与代码模块无关。
 */
#[Table(comment: '一站式配置订单')]
#[Index(name: 'idx_domain', columns: ['domain'])]
#[Index(name: 'idx_status', columns: ['status'])]
class ProvisioningOrder extends Model
{
    public const schema_table = 'saas_provisioning_order';

    public const schema_primary_key = 'provisioning_order_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'provisioning_order_id';

    #[Col('varchar', 255, nullable: false, default: '', comment: '根域名')]
    public const schema_fields_DOMAIN = 'domain';

    #[Col('varchar', 32, nullable: false, default: 'pending', comment: '流程状态')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 64, nullable: false, default: '', comment: '当前步骤')]
    public const schema_fields_CURRENT_STEP = 'current_step';

    #[Col('int', 11, nullable: false, default: 0, comment: '注册商账户ID')]
    public const schema_fields_REGISTRAR_ACCOUNT_ID = 'registrar_account_id';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'DNS 供应商')]
    public const schema_fields_DNS_VENDOR = 'dns_vendor';

    #[Col('int', 11, nullable: false, default: 0, comment: 'DNS 账户ID')]
    public const schema_fields_DNS_ACCOUNT_ID = 'dns_account_id';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'CDN 供应商')]
    public const schema_fields_CDN_VENDOR = 'cdn_vendor';

    #[Col('int', 11, nullable: false, default: 0, comment: 'CDN 账户ID')]
    public const schema_fields_CDN_ACCOUNT_ID = 'cdn_account_id';

    #[Col('int', 11, nullable: false, default: 0, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: '是否申请SSL')]
    public const schema_fields_APPLY_SSL = 'apply_ssl';

    #[Col('text', nullable: true, comment: '错误信息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';

    #[Col('datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_PENDING = 'pending';

    /** 订单 status 列（流程阶段）；与 current_step 的 STEP_* 可并存 */
    public const STATUS_STEP_PURCHASE = 'step_purchase';

    public const STATUS_STEP_DNS = 'step_dns';

    public const STATUS_STEP_RESOLVE = 'step_resolve';

    public const STATUS_STEP_VERIFY = 'step_verify';

    public const STATUS_STEP_CDN = 'step_cdn';

    public const STATUS_STEP_SSL = 'step_ssl';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STEP_PURCHASE = 'purchase';

    public const STEP_DNS = 'dns';

    public const STEP_RESOLVE = 'resolve';

    public const STEP_VERIFY = 'verify';

    public const STEP_CDN = 'cdn';

    public const STEP_SSL = 'ssl';

    /** @var array<string> */
    public array $_unit_primary_keys = ['provisioning_order_id'];

    /** @var array<string> */
    public array $_index_sort_keys = ['provisioning_order_id', 'domain', 'status'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ORDER_ID;
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!(int) $this->getData(self::schema_fields_ORDER_ID)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
    }

    public function getOrderId(): int
    {
        return (int) $this->getData(self::schema_fields_ORDER_ID);
    }

    public function getDomain(): string
    {
        return strtolower(trim((string) $this->getData(self::schema_fields_DOMAIN)));
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::schema_fields_STATUS);
    }

    public function getCurrentStep(): string
    {
        return (string) $this->getData(self::schema_fields_CURRENT_STEP);
    }

    public function getApplySsl(): bool
    {
        return (int) $this->getData(self::schema_fields_APPLY_SSL) === 1;
    }
}
