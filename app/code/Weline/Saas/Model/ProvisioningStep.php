<?php

declare(strict_types=1);

namespace Weline\Saas\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 配置流程步骤记录（每订单每步骤一条，用于重试与历史） */
#[Table(comment: 'SaaS 配置步骤表')]
#[Index(name: 'idx_order_id', columns: ['provisioning_order_id'])]
#[Index(name: 'idx_step_name', columns: ['step_name'])]
#[Index(name: 'idx_status', columns: ['status'])]
class ProvisioningStep extends Model
{

    public const schema_table = 'saas_provisioning_step';
    public const schema_primary_key = 'step_id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '步骤ID')]
    public const schema_fields_ID = 'step_id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '步骤ID')]
    public const schema_fields_STEP_ID = 'step_id';
    #[Col('int', 11, nullable: false, comment: '配置订单ID')]
    public const schema_fields_PROVISIONING_ORDER_ID = 'provisioning_order_id';
    #[Col('varchar', 32, nullable: false, comment: '步骤名')]
    public const schema_fields_STEP_NAME = 'step_name';
    #[Col('varchar', 20, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 64, default: '', comment: '供应商代码')]
    public const schema_fields_VENDOR = 'vendor';
    #[Col('int', 11, default: 0, comment: '账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('text', comment: '结果JSON')]
    public const schema_fields_RESULT_JSON = 'result_json';
    #[Col('varchar', 500, default: '', comment: '错误信息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public array $_unit_primary_keys = ['step_id'];
    public array $_index_sort_keys = ['step_id', 'provisioning_order_id', 'step_name'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_STEP_ID;
    }
public function save_before(): void
    {
        parent::save_before();
        $now = date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!$this->getData(self::schema_fields_STEP_ID)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
    }

    public function getStepId(): int
    {
        return (int) $this->getData(self::schema_fields_STEP_ID);
    }

    public function getProvisioningOrderId(): int
    {
        return (int) $this->getData(self::schema_fields_PROVISIONING_ORDER_ID);
    }

    public function getStepName(): string
    {
        return (string) $this->getData(self::schema_fields_STEP_NAME);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::schema_fields_STATUS);
    }
}

