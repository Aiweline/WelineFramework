<?php

declare(strict_types=1);

/*
 * 脱敏日志模型
 */

namespace GuoLaiRen\Desensitization\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class DesensitizationLog extends Model
{
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('脱敏日志表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '日志ID')
            ->addColumn(self::fields_ORIGINAL, TableInterface::column_type_TEXT, 0, 'not null', '原始内容')
            ->addColumn(self::fields_DESENSITIZED, TableInterface::column_type_TEXT, 0, 'not null', '脱敏后内容')
            ->addColumn(self::fields_RULE_ID, TableInterface::column_type_INTEGER, 0, 'not null default 0', '规则ID')
            ->addColumn(self::fields_METHOD, TableInterface::column_type_VARCHAR, 50, 'not null default "regex"', '脱敏方法')
            ->addColumn(self::fields_EXECUTION_TIME, TableInterface::column_type_DECIMAL, 0, 'not null default 0', '执行时间')
            ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, 0, 'not null default 0', '用户ID')
            ->addColumn(self::fields_IP, TableInterface::column_type_VARCHAR, 45, 'null', 'IP地址')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_TIMESTAMP, 0, 'not null default current_timestamp', '创建时间')
            ->addIndex(TableInterface::index_type_KEY, 'idx_rule_id', self::fields_RULE_ID, '规则ID索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_USER_ID, '用户ID索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_created_at', self::fields_CREATED_AT, '创建时间索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_method', self::fields_METHOD, '方法索引')
            ->create();
    }

    public const fields_ID = 'log_id';
    public const fields_ORIGINAL = 'original_content';
    public const fields_DESENSITIZED = 'desensitized_content';
    public const fields_RULE_ID = 'rule_id';
    public const fields_METHOD = 'method';
    public const fields_EXECUTION_TIME = 'execution_time';
    public const fields_USER_ID = 'user_id';
    public const fields_IP = 'ip_address';
    public const fields_CREATED_AT = 'created_at';

    public int $log_id = 0;
    public string $original_content = '';
    public string $desensitized_content = '';
    public int $rule_id = 0;
    public string $method = '';
    public float $execution_time = 0.0;
    public int $user_id = 0;
    public string $ip_address = '';
    public string $created_at = '';

    protected function _init()
    {
        $this->_setTable('desensitization_log');
        $this->_setPrimaryKey('log_id');
    }

    /**
     * 记录脱敏操作
     *
     * @param string $original
     * @param string $desensitized
     * @param int $ruleId
     * @param string $method
     * @param float $hideTime
     * @return $this
     */
    public function logOperation(
        string $original,
        string $desensitized,
        int $ruleId,
        string $method,
        float $executionTime
    ): self {
        $this->reset();
        $this->setData([
            self::fields_ORIGINAL => $original,
            self::fields_DESENSITIZED => $desensitized,
            self::fields_RULE_ID => $ruleId,
            self::fields_METHOD => $method,
            self::fields_EXECUTION_TIME => $executionTime,
            self::fields_USER_ID => 0, // TODO: 从会话获取
            self::fields_IP => $_SERVER['REMOTE_ADDR'] ?? '',
            self::fields_CREATED_AT => date('Y-m-d H:i:s')
        ]);
        $this->save();
        return $this;
    }
}

