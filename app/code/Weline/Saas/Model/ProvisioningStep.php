<?php

declare(strict_types=1);

namespace Weline\Saas\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 配置流程步骤记录（每订单每步骤一条，用于重试与历史）
 */
class ProvisioningStep extends Model
{
    public const table = 'saas_provisioning_step';

    public const fields_STEP_ID = 'step_id';
    public const fields_PROVISIONING_ORDER_ID = 'provisioning_order_id';
    public const fields_STEP_NAME = 'step_name';
    public const fields_STATUS = 'status';
    public const fields_VENDOR = 'vendor';
    public const fields_ACCOUNT_ID = 'account_id';
    public const fields_RESULT_JSON = 'result_json';
    public const fields_ERROR_MESSAGE = 'error_message';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public string $_primary_key = 'step_id';
    public array $_unit_primary_keys = ['step_id'];
    public array $_index_sort_keys = ['step_id', 'provisioning_order_id', 'step_name'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::fields_STEP_ID;
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

        $setup->createTable(__('SaaS 配置步骤表'))
            ->addColumn(
                self::fields_STEP_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                __('步骤ID')
            )
            ->addColumn(
                self::fields_PROVISIONING_ORDER_ID,
                TableInterface::column_type_INTEGER,
                11,
                'not null',
                __('配置订单ID')
            )
            ->addColumn(
                self::fields_STEP_NAME,
                TableInterface::column_type_VARCHAR,
                32,
                'not null',
                __('步骤名')
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                "default 'pending'",
                __('状态')
            )
            ->addColumn(
                self::fields_VENDOR,
                TableInterface::column_type_VARCHAR,
                64,
                "default ''",
                __('供应商代码')
            )
            ->addColumn(
                self::fields_ACCOUNT_ID,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                __('账户ID')
            )
            ->addColumn(
                self::fields_RESULT_JSON,
                TableInterface::column_type_TEXT,
                null,
                'null',
                __('结果JSON')
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
            ->addIndex(TableInterface::index_type_KEY, 'idx_order_id', self::fields_PROVISIONING_ORDER_ID)
            ->addIndex(TableInterface::index_type_KEY, 'idx_step_name', self::fields_STEP_NAME)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->create();
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = date('Y-m-d H:i:s');
        $this->setData(self::fields_UPDATED_AT, $now);
        if (!$this->getData(self::fields_STEP_ID)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
    }

    public function getStepId(): int
    {
        return (int) $this->getData(self::fields_STEP_ID);
    }

    public function getProvisioningOrderId(): int
    {
        return (int) $this->getData(self::fields_PROVISIONING_ORDER_ID);
    }

    public function getStepName(): string
    {
        return (string) $this->getData(self::fields_STEP_NAME);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::fields_STATUS);
    }
}
