<?php

declare(strict_types=1);

/*
 * AWS Domains 管理模块
 * 域名操作记录模型
 */

namespace Aws\Domains\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 域名操作记录模型
 * 记录所有域名相关的操作日志
 */
class DomainOperation extends Model
{
    public const table = 'aws_domains_operation';

    public string $_primary_key = 'operation_id';
    public array $_unit_primary_keys = ['operation_id'];

    // 字段常量
    public const fields_OPERATION_ID = 'operation_id';
    public const fields_CONFIG_ID = 'config_id';
    public const fields_DOMAIN_NAME = 'domain_name';
    public const fields_OPERATION_TYPE = 'operation_type';
    public const fields_AWS_OPERATION_ID = 'aws_operation_id';
    public const fields_STATUS = 'status';
    public const fields_REQUEST_DATA = 'request_data';
    public const fields_RESPONSE_DATA = 'response_data';
    public const fields_ERROR_MESSAGE = 'error_message';
    public const fields_OPERATOR_ID = 'operator_id';
    public const fields_OPERATOR_NAME = 'operator_name';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 操作类型
    public const OPERATION_CHECK_AVAILABILITY = 'check_availability';
    public const OPERATION_REGISTER = 'register';
    public const OPERATION_RENEW = 'renew';
    public const OPERATION_TRANSFER_IN = 'transfer_in';
    public const OPERATION_TRANSFER_OUT = 'transfer_out';
    public const OPERATION_UPDATE_CONTACT = 'update_contact';
    public const OPERATION_UPDATE_NAMESERVER = 'update_nameserver';
    public const OPERATION_UPDATE_PRIVACY = 'update_privacy';
    public const OPERATION_ENABLE_AUTO_RENEW = 'enable_auto_renew';
    public const OPERATION_DISABLE_AUTO_RENEW = 'disable_auto_renew';
    public const OPERATION_GET_DOMAIN_DETAIL = 'get_domain_detail';
    public const OPERATION_LIST_DOMAINS = 'list_domains';
    public const OPERATION_GET_PRICE = 'get_price';
    public const OPERATION_DELETE_DOMAIN = 'delete_domain';

    // 操作类型显示名称
    public const OPERATION_TYPES = [
        self::OPERATION_CHECK_AVAILABILITY => '可用性检查',
        self::OPERATION_REGISTER => '注册域名',
        self::OPERATION_RENEW => '续费域名',
        self::OPERATION_TRANSFER_IN => '转入域名',
        self::OPERATION_TRANSFER_OUT => '转出域名',
        self::OPERATION_UPDATE_CONTACT => '更新联系人',
        self::OPERATION_UPDATE_NAMESERVER => '更新域名服务器',
        self::OPERATION_UPDATE_PRIVACY => '更新隐私保护',
        self::OPERATION_ENABLE_AUTO_RENEW => '开启自动续费',
        self::OPERATION_DISABLE_AUTO_RENEW => '关闭自动续费',
        self::OPERATION_GET_DOMAIN_DETAIL => '获取域名详情',
        self::OPERATION_LIST_DOMAINS => '获取域名列表',
        self::OPERATION_GET_PRICE => '获取价格',
        self::OPERATION_DELETE_DOMAIN => '删除域名',
    ];

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING => '待处理',
        self::STATUS_IN_PROGRESS => '处理中',
        self::STATUS_SUCCESS => '成功',
        self::STATUS_FAILED => '失败',
        self::STATUS_CANCELLED => '已取消',
    ];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::fields_OPERATION_ID;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('AWS域名操作记录表')
                ->addColumn(
                    self::fields_OPERATION_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '操作ID'
                )
                ->addColumn(
                    self::fields_CONFIG_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    '',
                    '使用的AWS配置ID'
                )
                ->addColumn(
                    self::fields_DOMAIN_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '域名'
                )
                ->addColumn(
                    self::fields_OPERATION_TYPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '操作类型'
                )
                ->addColumn(
                    self::fields_AWS_OPERATION_ID,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    'AWS操作ID'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    20,
                    "default 'pending'",
                    '操作状态'
                )
                ->addColumn(
                    self::fields_REQUEST_DATA,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '请求数据JSON'
                )
                ->addColumn(
                    self::fields_RESPONSE_DATA,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '响应数据JSON'
                )
                ->addColumn(
                    self::fields_ERROR_MESSAGE,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '错误信息'
                )
                ->addColumn(
                    self::fields_OPERATOR_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    '',
                    '操作人ID'
                )
                ->addColumn(
                    self::fields_OPERATOR_NAME,
                    TableInterface::column_type_VARCHAR,
                    100,
                    '',
                    '操作人名称'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_domain_name',
                    [self::fields_DOMAIN_NAME],
                    '域名索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_operation_type',
                    [self::fields_OPERATION_TYPE],
                    '操作类型索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status',
                    [self::fields_STATUS],
                    '状态索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_aws_operation_id',
                    [self::fields_AWS_OPERATION_ID],
                    'AWS操作ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_created_at',
                    [self::fields_CREATED_AT],
                    '创建时间索引'
                )
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 预留升级逻辑
    }

    /**
     * 创建操作记录
     */
    public static function createLog(
        string $operationType,
        ?int $configId = null,
        ?string $domainName = null,
        ?array $requestData = null,
        ?int $operatorId = null,
        ?string $operatorName = null
    ): self {
        $log = new self();
        $log->setData(self::fields_OPERATION_TYPE, $operationType)
            ->setData(self::fields_CONFIG_ID, $configId)
            ->setData(self::fields_DOMAIN_NAME, $domainName)
            ->setData(self::fields_STATUS, self::STATUS_PENDING)
            ->setData(self::fields_OPERATOR_ID, $operatorId)
            ->setData(self::fields_OPERATOR_NAME, $operatorName);

        if ($requestData !== null) {
            $log->setData(self::fields_REQUEST_DATA, json_encode($requestData, JSON_UNESCAPED_UNICODE));
        }

        $log->save();
        return $log;
    }

    /**
     * 更新为成功状态
     */
    public function markSuccess(?string $awsOperationId = null, ?array $responseData = null): self
    {
        $this->setData(self::fields_STATUS, self::STATUS_SUCCESS);

        if ($awsOperationId !== null) {
            $this->setData(self::fields_AWS_OPERATION_ID, $awsOperationId);
        }

        if ($responseData !== null) {
            $this->setData(self::fields_RESPONSE_DATA, json_encode($responseData, JSON_UNESCAPED_UNICODE));
        }

        return $this->save();
    }

    /**
     * 更新为失败状态
     */
    public function markFailed(string $errorMessage, ?array $responseData = null): self
    {
        $this->setData(self::fields_STATUS, self::STATUS_FAILED)
            ->setData(self::fields_ERROR_MESSAGE, $errorMessage);

        if ($responseData !== null) {
            $this->setData(self::fields_RESPONSE_DATA, json_encode($responseData, JSON_UNESCAPED_UNICODE));
        }

        return $this->save();
    }

    /**
     * 更新为处理中状态
     */
    public function markInProgress(?string $awsOperationId = null): self
    {
        $this->setData(self::fields_STATUS, self::STATUS_IN_PROGRESS);

        if ($awsOperationId !== null) {
            $this->setData(self::fields_AWS_OPERATION_ID, $awsOperationId);
        }

        return $this->save();
    }

    /**
     * 获取操作类型显示名称
     */
    public function getOperationTypeDisplayName(): string
    {
        $type = $this->getData(self::fields_OPERATION_TYPE);
        return self::OPERATION_TYPES[$type] ?? $type;
    }

    /**
     * 获取状态显示名称
     */
    public function getStatusDisplayName(): string
    {
        $status = $this->getData(self::fields_STATUS);
        return self::STATUSES[$status] ?? $status;
    }

    /**
     * 获取请求数据数组
     */
    public function getRequestDataArray(): array
    {
        $data = $this->getData(self::fields_REQUEST_DATA);
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * 获取响应数据数组
     */
    public function getResponseDataArray(): array
    {
        $data = $this->getData(self::fields_RESPONSE_DATA);
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
    }
}
