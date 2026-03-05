<?php

declare(strict_types=1);

/*
 * 脱敏规则模型
 */

namespace GuoLaiRen\Desensitization\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '脱敏规则表')]
#[Index(name: 'idx_type', columns: ['type'], comment: '规则类型索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '状态索引')]
#[Index(name: 'idx_priority', columns: ['priority'], comment: '优先级索引')]
class DesensitizationRule extends Model
{
    public const schema_table = 'guolairen_desensitization_rule';
    public const schema_primary_key = 'rule_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '规则ID')]
    public const schema_fields_ID = 'rule_id';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '规则名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '规则类型')]
    public const schema_fields_TYPE = 'type';
    #[Col(type: 'text', nullable: false, comment: '匹配模式')]
    public const schema_fields_PATTERN = 'pattern';
    #[Col(type: 'text', nullable: false, comment: '替换内容')]
    public const schema_fields_REPLACEMENT = 'replacement';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '规则描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'int', nullable: false, default: 0, comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * 获取激活的规则列表
     *
     * @return $this
     */
    public function getActiveRules(): self
    {
        return $this->where(self::schema_fields_IS_ACTIVE, 1)
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
        return $this->where(self::schema_fields_TYPE, $type)
            ->where(self::schema_fields_IS_ACTIVE, 1)
            ->order('priority', 'DESC')
            ->order('rule_id', 'ASC');
    }
}

