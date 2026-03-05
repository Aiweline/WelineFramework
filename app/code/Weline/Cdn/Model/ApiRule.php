<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * API规则模型 - 存储从Api和Controller方法注释中收集到的CDN缓存规则，规则格式对标Cloudflare Cache Rules
 * @package Weline_Cdn
 */
#[Table(comment: 'CDN API规则表')]
#[Index(name: 'idx_module_class_method', columns: ['module', 'class', 'method'], type: 'UNIQUE')]
#[Index(name: 'idx_route', columns: ['route'])]
#[Index(name: 'idx_trigger', columns: ['trigger'])]
#[Index(name: 'idx_enabled', columns: ['enabled'])]
class ApiRule extends Model
{

    public const schema_table = 'cdn_api_rule';
    public const schema_primary_key = 'rule_id';
    /** 供 AbstractModel 推断 _primary_key / _unit_primary_keys，无需再声明同名属性 */
    public const schema_primary_keys = ['rule_id'];

    /**
     * Field name constants
     */
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '规则ID')]
    public const schema_fields_RULE_ID = 'rule_id';
    #[Col('varchar', 100, nullable: false, comment: '模块名称')]
    public const schema_fields_MODULE = 'module';
    #[Col('varchar', 500, nullable: false, comment: '完整类名')]
    public const schema_fields_CLASS = 'class';
    #[Col('varchar', 100, nullable: false, comment: '方法名')]
    public const schema_fields_METHOD = 'method';
    #[Col('varchar', 500, nullable: false, comment: '路由路径')]
    public const schema_fields_ROUTE = 'route';
    #[Col('varchar', 1000, nullable: false, comment: '规则表达式')]
    public const schema_fields_EXPRESSION = 'expression';
    #[Col('text', nullable: false, comment: '动作配置JSON')]
    public const schema_fields_ACTION = 'action';
    #[Col('varchar', 500, comment: '规则描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('tinyint', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_ENABLED = 'enabled';
    #[Col('varchar', 20, nullable: false, default: 'cron', comment: '触发方式')]
    public const schema_fields_TRIGGER = 'trigger';
    #[Col('int', comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    /**
     * 获取主键字段名
     * 
     * @return string
     */
    public function getIdFieldName(): string
    {
        return self::schema_fields_RULE_ID;
    }

    /**
     * 获取动作配置数组
     * 
     * @return array
     */
    public function getActionArray(): array
    {
        $action = $this->getData(self::schema_fields_ACTION);
        if (empty($action)) {
            return [];
        }
        
        $decoded = json_decode($action, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 设置动作配置数组
     * 
     * @param array $action
     * @return $this
     */
    public function setActionArray(array $action): self
    {
        $this->setData(self::schema_fields_ACTION, json_encode($action, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 转换为Cloudflare规则格式
     * 
     * @return array
     */
    public function toCloudflareRule(): array
    {
        return [
            'expression' => $this->getData(self::schema_fields_EXPRESSION),
            'action' => $this->getActionArray(),
            'description' => $this->getData(self::schema_fields_DESCRIPTION) ?? '',
            'enabled' => (bool)$this->getData(self::schema_fields_ENABLED)
        ];
    }
}

