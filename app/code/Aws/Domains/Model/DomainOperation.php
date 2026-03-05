<?php
declare(strict_types=1);
/*
 * AWS Domains 管理模块
 * 域名操作记录模型
 */
namespace Aws\Domains\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
/**
 * 域名操作记录模型
 * 记录所有域名相关的操作日志
 */
#[Table(comment: 'AWS域名操作记录表')]
#[Index(name: 'idx_domain_name', columns: ['domain_name'], comment: '域名索引')]
#[Index(name: 'idx_operation_type', columns: ['operation_type'], comment: '操作类型索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_aws_operation_id', columns: ['aws_operation_id'], comment: 'AWS操作ID索引')]
#[Index(name: 'idx_created_at', columns: ['created_at'], comment: '创建时间索引')]
class DomainOperation extends Model
{
    public const schema_table = 'aws_domains_operation';
    public const schema_primary_key = 'operation_id';
    // 字段常量
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '操作ID')]
    public const schema_fields_OPERATION_ID = 'operation_id';
    #[Col(type: 'int', nullable: true, comment: '使用的AWS配置ID')]
    public const schema_fields_CONFIG_ID = 'config_id';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '域名')]
    public const schema_fields_DOMAIN_NAME = 'domain_name';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '操作类型')]
    public const schema_fields_OPERATION_TYPE = 'operation_type';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'AWS操作ID')]
    public const schema_fields_AWS_OPERATION_ID = 'aws_operation_id';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'pending', comment: '操作状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'text', nullable: true, comment: '请求数据JSON')]
    public const schema_fields_REQUEST_DATA = 'request_data';
    #[Col(type: 'text', nullable: true, comment: '响应数据JSON')]
    public const schema_fields_RESPONSE_DATA = 'response_data';
    #[Col(type: 'text', nullable: true, comment: '错误信息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col(type: 'int', nullable: true, comment: '操作人ID')]
    public const schema_fields_OPERATOR_ID = 'operator_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '操作人名称')]
    public const schema_fields_OPERATOR_NAME = 'operator_name';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
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
        return self::schema_fields_OPERATION_ID;
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
        $log->setData(self::schema_fields_OPERATION_TYPE, $operationType)
            ->setData(self::schema_fields_CONFIG_ID, $configId)
            ->setData(self::schema_fields_DOMAIN_NAME, $domainName)
            ->setData(self::schema_fields_STATUS, self::STATUS_PENDING)
            ->setData(self::schema_fields_OPERATOR_ID, $operatorId)
            ->setData(self::schema_fields_OPERATOR_NAME, $operatorName);
        if ($requestData !== null) {
            $log->setData(self::schema_fields_REQUEST_DATA, json_encode($requestData, JSON_UNESCAPED_UNICODE));
        }
        $log->save();
        return $log;
    }
    /**
     * 更新为成功状态
     */
    public function markSuccess(?string $awsOperationId = null, ?array $responseData = null): self
    {
        $this->setData(self::schema_fields_STATUS, self::STATUS_SUCCESS);
        if ($awsOperationId !== null) {
            $this->setData(self::schema_fields_AWS_OPERATION_ID, $awsOperationId);
        }
        if ($responseData !== null) {
            $this->setData(self::schema_fields_RESPONSE_DATA, json_encode($responseData, JSON_UNESCAPED_UNICODE));
        }
        return $this->save();
    }
    /**
     * 更新为失败状态
     */
    public function markFailed(string $errorMessage, ?array $responseData = null): self
    {
        $this->setData(self::schema_fields_STATUS, self::STATUS_FAILED)
            ->setData(self::schema_fields_ERROR_MESSAGE, $errorMessage);
        if ($responseData !== null) {
            $this->setData(self::schema_fields_RESPONSE_DATA, json_encode($responseData, JSON_UNESCAPED_UNICODE));
        }
        return $this->save();
    }
    /**
     * 更新为处理中状态
     */
    public function markInProgress(?string $awsOperationId = null): self
    {
        $this->setData(self::schema_fields_STATUS, self::STATUS_IN_PROGRESS);
        if ($awsOperationId !== null) {
            $this->setData(self::schema_fields_AWS_OPERATION_ID, $awsOperationId);
        }
        return $this->save();
    }
    /**
     * 获取操作类型显示名称
     */
    public function getOperationTypeDisplayName(): string
    {
        $type = $this->getData(self::schema_fields_OPERATION_TYPE);
        return self::OPERATION_TYPES[$type] ?? $type;
    }
    /**
     * 获取状态显示名称
     */
    public function getStatusDisplayName(): string
    {
        $status = $this->getData(self::schema_fields_STATUS);
        return self::STATUSES[$status] ?? $status;
    }
    /**
     * 获取请求数据数组
     */
    public function getRequestDataArray(): array
    {
        $data = $this->getData(self::schema_fields_REQUEST_DATA);
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
        $data = $this->getData(self::schema_fields_RESPONSE_DATA);
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
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
