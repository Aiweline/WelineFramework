<?php

declare(strict_types=1);

namespace Weline\Saas\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 一站式配置订单（单域名一条流程）- 记录域名、当前步骤、各步骤供应商/账户、是否申请证书等 */
#[Table(comment: 'SaaS 配置订单表')]
#[Index(name: 'idx_domain', columns: ['domain'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_current_step', columns: ['current_step'])]
class ProvisioningOrder extends Model
{

    public const schema_table = 'saas_provisioning_order';
    public const schema_primary_key = 'provisioning_order_id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '配置订单ID')]
    public const schema_fields_ID = 'provisioning_order_id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '配置订单ID')]
    public const schema_fields_ORDER_ID = 'provisioning_order_id';
    #[Col('varchar', 255, nullable: false, comment: '域名')]
    public const schema_fields_DOMAIN = 'domain';
    #[Col('varchar', 32, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 11, default: 0, comment: '域名商账号ID')]
    public const schema_fields_REGISTRAR_ACCOUNT_ID = 'registrar_account_id';
    #[Col('varchar', 64, default: '', comment: 'DNS 供应商代码')]
    public const schema_fields_DNS_VENDOR = 'dns_vendor';
    #[Col('int', 11, default: 0, comment: 'DNS 账户ID')]
    public const schema_fields_DNS_ACCOUNT_ID = 'dns_account_id';
    #[Col('varchar', 64, default: '', comment: 'CDN 供应商代码')]
    public const schema_fields_CDN_VENDOR = 'cdn_vendor';
    #[Col('int', 11, default: 0, comment: 'CDN 账户ID')]
    public const schema_fields_CDN_ACCOUNT_ID = 'cdn_account_id';
    #[Col('int', 11, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('tinyint', 1, default: 1, comment: '是否申请SSL证书')]
    public const schema_fields_APPLY_SSL = 'apply_ssl';
    #[Col('varchar', 32, default: '', comment: '当前步骤')]
    public const schema_fields_CURRENT_STEP = 'current_step';
    #[Col('varchar', 500, default: '', comment: '错误信息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public const STATUS_PENDING = 'pending';
    public const STATUS_STEP_PURCHASE = 'step_purchase';
    public const STATUS_STEP_DNS = 'step_dns';
    public const STATUS_STEP_CDN = 'step_cdn';
    public const STATUS_STEP_SSL = 'step_ssl';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STEP_PURCHASE = 'purchase';
    public const STEP_DNS = 'dns';
    public const STEP_CDN = 'cdn';
    public const STEP_SSL = 'ssl';
    public array $_unit_primary_keys = ['provisioning_order_id'];
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
        if (!$this->getData(self::schema_fields_ORDER_ID)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
    }

    public function getOrderId(): int
    {
        return (int) $this->getData(self::schema_fields_ORDER_ID);
    }

    public function getDomain(): string
    {
        return (string) $this->getData(self::schema_fields_DOMAIN);
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

