<?php
declare(strict_types=1);

namespace Weline\Agent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 智能体插件登记模型
 *
 * 插件 = 可安装能力包（skills + prompt 片段 + 可选任务智能体引用），
 * 由 PluginScanner 从 extends/module/Weline_Agent/Plugin 扫描登记，
 * 角色通过 plugins 字段绑定，形成有效技能并集。
 */
#[Table(comment: '智能体插件表')]
#[Index(name: 'idx_plugin_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'idx_plugin_status', columns: ['status'])]
class AgentPlugin extends Model
{
    public const schema_table = 'weline_agent_plugin';
    public const schema_primary_key = 'plugin_id';

    public array $_unit_primary_keys = ['plugin_id'];
    public array $_index_sort_keys = ['plugin_id', 'code', 'status'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '插件ID')]
    public const schema_fields_PLUGIN_ID = 'plugin_id';

    #[Col('varchar', 64, nullable: false, comment: '插件代码（唯一）')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 191, nullable: false, comment: '插件名称')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 32, nullable: false, default: '1.0.0', comment: '版本')]
    public const schema_fields_VERSION = 'version';

    #[Col('varchar', 64, nullable: false, comment: '提供模块')]
    public const schema_fields_MODULE = 'module';

    #[Col('varchar', 255, nullable: false, comment: '插件实现类')]
    public const schema_fields_CLASS_NAME = 'class_name';

    #[Col('mediumtext', comment: 'manifest JSON：skills/prompt_fragments/agents/permissions/ui_panels')]
    public const schema_fields_MANIFEST_JSON = 'manifest_json';

    #[Col('varchar', 16, nullable: false, default: 'installed', comment: 'installed/enabled/disabled/missing')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 1, default: 0, comment: '是否内置插件')]
    public const schema_fields_IS_BUILTIN = 'is_builtin';

    #[Col('int', comment: '安装时间')]
    public const schema_fields_INSTALLED_TIME = 'installed_time';

    #[Col('int', comment: '更新时间')]
    public const schema_fields_UPDATED_TIME = 'updated_time';

    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_MISSING = 'missing';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_PLUGIN_ID;
    }

    /** @return array<string, mixed> */
    public function getManifest(): array
    {
        $raw = $this->getData(self::schema_fields_MANIFEST_JSON);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    /** @return list<string> */
    public function getSkillCodes(): array
    {
        $skills = $this->getManifest()['skills'] ?? [];
        return is_array($skills) ? array_values(array_map('strval', $skills)) : [];
    }

    public function isEnabled(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_ENABLED;
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_INSTALLED_TIME, $now);
        }
        $this->setData(self::schema_fields_UPDATED_TIME, $now);

        $manifest = $this->getData(self::schema_fields_MANIFEST_JSON);
        if (is_array($manifest)) {
            $this->setData(self::schema_fields_MANIFEST_JSON, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

    }
}
