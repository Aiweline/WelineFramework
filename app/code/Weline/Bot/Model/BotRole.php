<?php
declare(strict_types=1);

namespace Weline\Bot\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 机器人角色模型
 *
 * 每个角色有独立的权限、技能集、提示词和工作空间配置
 * 实现显式角色系统和沙盒化隔离
 * 
 * 复用 Weline_Ai：
 * - model_id 关联 AiModel 表
 * - scenario_adapter_code 关联场景适配器
 */
#[Table(comment: '机器人角色表')]
#[Index(name: 'idx_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_model_id', columns: ['model_id'])]
class BotRole extends Model
{
    public const schema_table = 'weline_bot_role';
    public const schema_primary_key = 'role_id';

    public array $_unit_primary_keys = ['role_id'];
    public array $_index_sort_keys = ['role_id', 'code', 'status'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '角色ID')]
    public const schema_fields_ROLE_ID = 'role_id';

    #[Col('varchar', 100, nullable: false, comment: '角色代码（唯一标识）')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 255, nullable: false, comment: '角色名称')]
    public const schema_fields_NAME = 'name';

    #[Col('text', comment: '系统提示词')]
    public const schema_fields_SYSTEM_PROMPT = 'system_prompt';

    #[Col('text', comment: '权限配置（JSON）')]
    public const schema_fields_PERMISSIONS = 'permissions';

    #[Col('text', comment: '可用技能列表（JSON）')]
    public const schema_fields_SKILLS = 'skills';

    #[Col('int', comment: '关联 AI 模型 ID（Weline_Ai）')]
    public const schema_fields_MODEL_ID = 'model_id';

    #[Col('varchar', 100, comment: '场景适配器代码（Weline_Ai）')]
    public const schema_fields_SCENARIO_ADAPTER_CODE = 'scenario_adapter_code';

    #[Col('text', comment: '模型配置（JSON，覆盖默认配置）')]
    public const schema_fields_MODEL_CONFIG = 'model_config';

    #[Col('varchar', 50, default: 'enabled', comment: '状态：enabled/disabled')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 1, default: 1, comment: '是否默认角色')]
    public const schema_fields_IS_DEFAULT = 'is_default';

    #[Col('text', comment: '角色描述')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col('varchar', 255, comment: '头像图标')]
    public const schema_fields_ICON = 'icon';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('int', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ROLE_ID;
    }

    /**
     * 获取权限配置
     */
    public function getPermissions(): array
    {
        $permissions = $this->getData(self::schema_fields_PERMISSIONS);
        if (is_string($permissions)) {
            $decoded = json_decode($permissions, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($permissions) ? $permissions : [];
    }

    /**
     * 设置权限配置
     */
    public function setPermissions(array $permissions): self
    {
        return $this->setData(self::schema_fields_PERMISSIONS, json_encode($permissions, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取可用技能列表
     */
    public function getSkills(): array
    {
        $skills = $this->getData(self::schema_fields_SKILLS);
        if (is_string($skills)) {
            $decoded = json_decode($skills, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($skills) ? $skills : [];
    }

    /**
     * 设置可用技能列表
     */
    public function setSkills(array $skills): self
    {
        return $this->setData(self::schema_fields_SKILLS, json_encode($skills, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取模型配置（覆盖默认配置）
     */
    public function getModelConfig(): array
    {
        $config = $this->getData(self::schema_fields_MODEL_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    /**
     * 设置模型配置
     */
    public function setModelConfig(array $config): self
    {
        return $this->setData(self::schema_fields_MODEL_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取关联的 AI 模型信息（通过 w_query）
     */
    public function getAiModel(): ?array
    {
        $modelId = $this->getData(self::schema_fields_MODEL_ID);
        if (!$modelId) {
            return null;
        }

        // 使用 w_query 获取模型信息
        try {
            $model = w_query('ai', 'getModel', ['id' => $modelId]);
            return $model;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 获取场景适配器信息（通过 w_query）
     */
    public function getScenarioAdapter(): ?array
    {
        $adapterCode = $this->getData(self::schema_fields_SCENARIO_ADAPTER_CODE);
        if (!$adapterCode) {
            return null;
        }

        // 使用 w_query 获取适配器信息
        try {
            $adapter = w_query('ai', 'getAdapter', ['code' => $adapterCode]);
            return $adapter;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 检查是否有某个技能
     */
    public function hasSkill(string $skillCode): bool
    {
        return in_array($skillCode, $this->getSkills(), true);
    }

    /**
     * 检查是否有某个权限
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->getPermissions();
        // 支持 * 通配符
        foreach ($permissions as $granted) {
            if (fnmatch($permission, $granted) || $granted === '*') {
                return true;
            }
        }
        return false;
    }

    /**
     * 是否启用
     */
    public function isEnabled(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_ENABLED;
    }

    /**
     * 是否默认角色
     */
    public function isDefault(): bool
    {
        return (bool) $this->getData(self::schema_fields_IS_DEFAULT);
    }

    /**
     * 获取可用的 AI 模型列表（通过 w_query）
     */
    public static function getAvailableModels(): array
    {
        try {
            return w_query('ai', 'getActiveModels', []) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 获取可用的场景适配器列表（通过 w_query）
     */
    public static function getAvailableAdapters(): array
    {
        try {
            return w_query('ai', 'getActiveAdapters', []) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);

        // JSON 字段序列化
        if (is_array($this->getData(self::schema_fields_PERMISSIONS))) {
            $this->setData(self::schema_fields_PERMISSIONS, json_encode(
                $this->getData(self::schema_fields_PERMISSIONS),
                JSON_UNESCAPED_UNICODE
            ));
        }
        if (is_array($this->getData(self::schema_fields_SKILLS))) {
            $this->setData(self::schema_fields_SKILLS, json_encode(
                $this->getData(self::schema_fields_SKILLS),
                JSON_UNESCAPED_UNICODE
            ));
        }
        if (is_array($this->getData(self::schema_fields_MODEL_CONFIG))) {
            $this->setData(self::schema_fields_MODEL_CONFIG, json_encode(
                $this->getData(self::schema_fields_MODEL_CONFIG),
                JSON_UNESCAPED_UNICODE
            ));
        }

        return parent::beforeSave();
    }
}
