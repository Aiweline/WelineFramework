<?php

declare(strict_types=1);

/*
 * 脱敏规则模型
 */

namespace GuoLaiRen\Desensitization\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class DesensitizationRule extends Model
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

        $setup->createTable('脱敏规则表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '规则ID')
            ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 100, 'not null', '规则名称')
            ->addColumn(self::fields_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '规则类型')
            ->addColumn(self::fields_PATTERN, TableInterface::column_type_TEXT, 0, 'not null', '匹配模式')
            ->addColumn(self::fields_REPLACEMENT, TableInterface::column_type_TEXT, 0, 'not null', '替换内容')
            ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_VARCHAR, 255, 'null', '规则描述')
            ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'not null default 1', '是否激活')
            ->addColumn(self::fields_PRIORITY, TableInterface::column_type_INTEGER, 0, 'not null default 0', '优先级')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_TIMESTAMP, 0, 'not null default current_timestamp', '创建时间')
            ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_TIMESTAMP, 0, 'not null default current_timestamp on update current_timestamp', '更新时间')
            ->addIndex(TableInterface::index_type_KEY, 'idx_type', self::fields_TYPE, '规则类型索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '状态索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_priority', self::fields_PRIORITY, '优先级索引')
            ->create();
    }

    public const fields_ID = 'rule_id';
    public const fields_NAME = 'name';
    public const fields_TYPE = 'type';
    public const fields_PATTERN = 'pattern';
    public const fields_REPLACEMENT = 'replacement';
    public const fields_DESCRIPTION = 'description';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_PRIORITY = 'priority';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    public int $rule_id = 0;
    public string $name = '';
    public string $type = '';
    public string $pattern = '';
    public string $replacement = '';
    public string $description = '';
    public int $is_active = 1;
    public int $priority = 0;
    public string $created_at = '';
    public string $updated_at = '';

    protected function _init()
    {
        $this->_setTable('desensitization_rule');
        $this->_setPrimaryKey('rule_id');
    }

    /**
     * 获取激活的规则列表
     *
     * @return $this
     */
    public function getActiveRules(): self
    {
        return $this->where(self::fields_IS_ACTIVE, 1)
            ->order('priority', 'DESC')
            ->order('rule_id', 'ASC');
    }

    /**
     * 根据类型获取规则
     *
     * @param string $type
     * @return $this
     */
    public function getByType(string $type): self
    {
        return $this->where(self::fields_TYPE, $type)
            ->where(self::fields_IS_ACTIVE, 1)
            ->order('priority', 'DESC')
            ->order('rule_id', 'ASC');
    }
}

