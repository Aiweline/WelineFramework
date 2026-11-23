<?php
declare(strict_types=1);

namespace Weline\Agent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 智能体项目模型
 *
 * 项目 = 团队边界容器：会话/流程/任务必须归属其一；
 * project_key 注入 LLM 上下文，智能体间派单仅允许同项目。
 */
#[Table(comment: '智能体项目表')]
#[Index(name: 'idx_project_key', columns: ['project_key'], type: 'UNIQUE')]
#[Index(name: 'idx_project_owner', columns: ['owner_type', 'owner_id'])]
#[Index(name: 'idx_project_website', columns: ['website_id'])]
#[Index(name: 'idx_project_status', columns: ['status'])]
class AgentProject extends Model
{
    public const schema_table = 'weline_agent_project';
    public const schema_primary_key = 'project_id';

    public array $_unit_primary_keys = ['project_id'];
    public array $_index_sort_keys = ['project_id', 'project_key', 'status'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '项目ID')]
    public const schema_fields_PROJECT_ID = 'project_id';

    #[Col('varchar', 64, nullable: false, comment: '项目公开标记（uuid）')]
    public const schema_fields_PROJECT_KEY = 'project_key';

    #[Col('varchar', 191, nullable: false, comment: '项目名称')]
    public const schema_fields_NAME = 'name';

    #[Col('int', comment: '关联网站ID（可空，Ops 收敛预留）')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 16, nullable: false, default: 'admin', comment: 'owner 类型：admin/customer/system')]
    public const schema_fields_OWNER_TYPE = 'owner_type';

    #[Col('varchar', 64, nullable: false, comment: 'owner 标识（admin user id 或 customer context）')]
    public const schema_fields_OWNER_ID = 'owner_id';

    #[Col('varchar', 16, nullable: false, default: 'active', comment: 'active/archived')]
    public const schema_fields_STATUS = 'status';

    #[Col('mediumtext', comment: '扩展配置 JSON')]
    public const schema_fields_CONFIG_JSON = 'config_json';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';

    #[Col('int', comment: '更新时间')]
    public const schema_fields_UPDATED_TIME = 'updated_time';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const OWNER_ADMIN = 'admin';
    public const OWNER_CUSTOMER = 'customer';
    public const OWNER_SYSTEM = 'system';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_PROJECT_ID;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        $raw = $this->getData(self::schema_fields_CONFIG_JSON);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_TIME, $now);
            if (trim((string)$this->getData(self::schema_fields_PROJECT_KEY)) === '') {
                $this->setData(self::schema_fields_PROJECT_KEY, 'pj_' . bin2hex(random_bytes(8)));
            }
        }
        $this->setData(self::schema_fields_UPDATED_TIME, $now);

        $config = $this->getData(self::schema_fields_CONFIG_JSON);
        if (is_array($config)) {
            $this->setData(self::schema_fields_CONFIG_JSON, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

    }
}
