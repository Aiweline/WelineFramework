<?php

declare(strict_types=1);

namespace Weline\Saas\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 一站式配置订单（单域名一条流程）
 *
 * 记录：域名、当前步骤、各步骤供应商/账户、是否申请证书等。
 */
class ProvisioningOrder extends Model
{
    public const table = 'saas_provisioning_order';

    public const fields_ORDER_ID = 'provisioning_order_id';
    public const fields_DOMAIN = 'domain';
    public const fields_STATUS = 'status';
    public const fields_REGISTRAR_ACCOUNT_ID = 'registrar_account_id';
    public const fields_DNS_VENDOR = 'dns_vendor';
    public const fields_DNS_ACCOUNT_ID = 'dns_account_id';
    public const fields_CDN_VENDOR = 'cdn_vendor';
    public const fields_CDN_ACCOUNT_ID = 'cdn_account_id';
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_APPLY_SSL = 'apply_ssl';
    public const fields_CURRENT_STEP = 'current_step';
    public const fields_ERROR_MESSAGE = 'error_message';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /** 流程状态 */
    public const STATUS_PENDING = 'pending';
    public const STATUS_STEP_PURCHASE = 'step_purchase';
    public const STATUS_STEP_DNS = 'step_dns';
    public const STATUS_STEP_CDN = 'step_cdn';
    public const STATUS_STEP_SSL = 'step_ssl';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** 步骤名（与 current_step 对应） */
    public const STEP_PURCHASE = 'purchase';
    public const STEP_DNS = 'dns';
    public const STEP_CDN = 'cdn';
    public const STEP_SSL = 'ssl';

    public string $_primary_key = 'provisioning_order_id';
    public array $_unit_primary_keys = ['provisioning_order_id'];
    public array $_index_sort_keys = ['provisioning_order_id', 'domain', 'status'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::fields_ORDER_ID;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
        }
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable(__('SaaS 配置订单表'))
            ->addColumn(
                self::fields_ORDER_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                __('配置订单ID')
            )
            ->addColumn(
                self::fields_DOMAIN,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                __('域名')
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                32,
                "default 'pending'",
                __('状态')
            )
            ->addColumn(
                self::fields_REGISTRAR_ACCOUNT_ID,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                __('域名商账号ID')
            )
            ->addColumn(
                self::fields_DNS_VENDOR,
                TableInterface::column_type_VARCHAR,
                64,
                "default ''",
                __('DNS 供应商代码')
            )
            ->addColumn(
                self::fields_DNS_ACCOUNT_ID,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                __('DNS 账户ID')
            )
            ->addColumn(
                self::fields_CDN_VENDOR,
                TableInterface::column_type_VARCHAR,
                64,
                "default ''",
                __('CDN 供应商代码')
            )
            ->addColumn(
                self::fields_CDN_ACCOUNT_ID,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                __('CDN 账户ID')
            )
            ->addColumn(
                self::fields_WEBSITE_ID,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                __('网站ID')
            )
            ->addColumn(
                self::fields_APPLY_SSL,
                TableInterface::column_type_TINYINT,
                1,
                'default 1',
                __('是否申请SSL证书')
            )
            ->addColumn(
                self::fields_CURRENT_STEP,
                TableInterface::column_type_VARCHAR,
                32,
                "default ''",
                __('当前步骤')
            )
            ->addColumn(
                self::fields_ERROR_MESSAGE,
                TableInterface::column_type_VARCHAR,
                500,
                "default ''",
                __('错误信息')
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                '',
                __('创建时间')
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                '',
                __('更新时间')
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_domain', self::fields_DOMAIN)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->addIndex(TableInterface::index_type_KEY, 'idx_current_step', self::fields_CURRENT_STEP)
            ->create();
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = date('Y-m-d H:i:s');
        $this->setData(self::fields_UPDATED_AT, $now);
        if (!$this->getData(self::fields_ORDER_ID)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
    }

    public function getOrderId(): int
    {
        return (int) $this->getData(self::fields_ORDER_ID);
    }

    public function getDomain(): string
    {
        return (string) $this->getData(self::fields_DOMAIN);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::fields_STATUS);
    }

    public function getCurrentStep(): string
    {
        return (string) $this->getData(self::fields_CURRENT_STEP);
    }

    public function getApplySsl(): bool
    {
        return (int) $this->getData(self::fields_APPLY_SSL) === 1;
    }
}
