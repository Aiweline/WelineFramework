<?php
declare(strict_types=1);

namespace Weline\Bot\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 技能注册表模型
 *
 * 管理所有可用技能的注册信息
 */
#[Table(comment: '技能注册表')]
#[Index(name: 'idx_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'idx_category', columns: ['category'])]
#[Index(name: 'idx_is_active', columns: ['is_active'])]
class BotSkill extends Model
{
    public const schema_table = 'weline_bot_skill';
    public const schema_primary_key = 'skill_id';

    public array $_unit_primary_keys = ['skill_id'];
    public array $_index_sort_keys = ['skill_id', 'code', 'category'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '技能ID')]
    public const schema_fields_SKILL_ID = 'skill_id';

    #[Col('varchar', 100, nullable: false, comment: '技能代码（唯一标识）')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 255, nullable: false, comment: '技能名称')]
    public const schema_fields_NAME = 'name';

    #[Col('text', comment: '技能描述')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col('varchar', 50, nullable: false, comment: '分类：filesystem/shell/browser/api/database')]
    public const schema_fields_CATEGORY = 'category';

    #[Col('varchar', 500, comment: '实现类名')]
    public const schema_fields_CLASS_NAME = 'class_name';

    #[Col('varchar', 500, comment: '文件路径')]
    public const schema_fields_FILE_PATH = 'file_path';

    #[Col('text', comment: '参数定义（JSON Schema）')]
    public const schema_fields_PARAMETERS = 'parameters';

    #[Col('text', comment: '所需权限（JSON数组）')]
    public const schema_fields_PERMISSION_REQUIRED = 'permission_required';

    #[Col('int', 1, default: 0, comment: '是否危险操作')]
    public const schema_fields_IS_DANGEROUS = 'is_dangerous';

    #[Col('int', 1, default: 0, comment: '需要确认')]
    public const schema_fields_REQUIRES_CONFIRMATION = 'requires_confirmation';

    #[Col('varchar', 255, comment: '来源模块')]
    public const schema_fields_MODULE = 'module';

    #[Col('int', 1, default: 1, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col('int', 1, default: 0, comment: '是否内置')]
    public const schema_fields_IS_BUILTIN = 'is_builtin';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('int', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    // 分类常量
    public const CATEGORY_FILESYSTEM = 'filesystem';
    public const CATEGORY_SHELL = 'shell';
    public const CATEGORY_BROWSER = 'browser';
    public const CATEGORY_API = 'api';
    public const CATEGORY_DATABASE = 'database';
    public const CATEGORY_CODE = 'code';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_SKILL_ID;
    }

    /**
     * 获取参数定义
     */
    public function getParameters(): array
    {
        $params = $this->getData(self::schema_fields_PARAMETERS);
        if (is_string($params)) {
            $decoded = json_decode($params, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($params) ? $params : [];
    }

    /**
     * 设置参数定义
     */
    public function setParameters(array $params): self
    {
        return $this->setData(self::schema_fields_PARAMETERS, json_encode($params, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取所需权限
     */
    public function getPermissionRequired(): array
    {
        $permissions = $this->getData(self::schema_fields_PERMISSION_REQUIRED);
        if (is_string($permissions)) {
            $decoded = json_decode($permissions, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($permissions) ? $permissions : [];
    }

    /**
     * 设置所需权限
     */
    public function setPermissionRequired(array $permissions): self
    {
        return $this->setData(self::schema_fields_PERMISSION_REQUIRED, json_encode($permissions, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 是否危险操作
     */
    public function isDangerous(): bool
    {
        return (bool) $this->getData(self::schema_fields_IS_DANGEROUS);
    }

    /**
     * 是否需要确认
     */
    public function requiresConfirmation(): bool
    {
        return (bool) $this->getData(self::schema_fields_REQUIRES_CONFIRMATION);
    }

    /**
     * 是否激活
     */
    public function isActive(): bool
    {
        return (bool) $this->getData(self::schema_fields_IS_ACTIVE);
    }

    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);

        if (is_array($this->getData(self::schema_fields_PARAMETERS))) {
            $this->setData(self::schema_fields_PARAMETERS, json_encode(
                $this->getData(self::schema_fields_PARAMETERS),
                JSON_UNESCAPED_UNICODE
            ));
        }
        if (is_array($this->getData(self::schema_fields_PERMISSION_REQUIRED))) {
            $this->setData(self::schema_fields_PERMISSION_REQUIRED, json_encode(
                $this->getData(self::schema_fields_PERMISSION_REQUIRED),
                JSON_UNESCAPED_UNICODE
            ));
        }

        return parent::beforeSave();
    }
}
