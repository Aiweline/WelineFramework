<?php
declare(strict_types=1);
/*
 * 脱敏日志模型
 */
namespace GuoLaiRen\Desensitization\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '脱敏日志表')]
#[Index(name: 'idx_rule_id', columns: ['rule_id'], comment: '规则ID索引')]
#[Index(name: 'idx_user_id', columns: ['user_id'], comment: '用户ID索引')]
#[Index(name: 'idx_created_at', columns: ['created_at'], comment: '创建时间索引')]
#[Index(name: 'idx_method', columns: ['method'], comment: '方法索引')]
class DesensitizationLog extends Model
{
    public const schema_table = 'guolairen_desensitization_log';
    public const schema_primary_key = 'log_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '日志ID')]
    public const schema_fields_LOG_ID = 'log_id';
    #[Col(type: 'text', nullable: true, comment: '原始内容')]
    public const schema_fields_ORIGINAL = 'original_content';
    #[Col(type: 'text', nullable: true, comment: '脱敏后内容')]
    public const schema_fields_DESENSITIZED = 'desensitized_content';
    #[Col(type: 'int', nullable: true, comment: '规则ID')]
    public const schema_fields_RULE_ID = 'rule_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '方法')]
    public const schema_fields_METHOD = 'method';
    #[Col(type: 'decimal', length: '12,4', nullable: true, comment: '执行耗时')]
    public const schema_fields_EXECUTION_TIME = 'execution_time';
    #[Col(type: 'int', nullable: true, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'varchar', length: 45, nullable: true, comment: 'IP地址')]
    public const schema_fields_IP = 'ip_address';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_LOG_ID;
    }

    /**
     * 记录脱敏操作
     */
    public function logOperation(
        string $original,
        string $desensitized,
        int $ruleId,
        string $method,
        float $executionTime
    ): self {
        $this->reset();
        $this->setData(self::schema_fields_ORIGINAL, $original)
            ->setData(self::schema_fields_DESENSITIZED, $desensitized)
            ->setData(self::schema_fields_RULE_ID, $ruleId)
            ->setData(self::schema_fields_METHOD, $method)
            ->setData(self::schema_fields_EXECUTION_TIME, $executionTime)
            ->setData(self::schema_fields_USER_ID, 0)
            ->setData(self::schema_fields_IP, (string)\w_env('server.remote_addr', ''))
            ->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        $this->save();
        return $this;
    }
}
