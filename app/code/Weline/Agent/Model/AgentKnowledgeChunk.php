<?php
declare(strict_types=1);

namespace Weline\Agent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 项目知识块模型（RAG 语料）
 *
 * kind: sop（可复用流程）/ trajectory（轨迹摘要）/ note（备注）/ task（任务记录）
 * project_id=0 表示全局知识。检索走 ProjectKnowledgeService（向量+BM25 混合）。
 */
#[Table(comment: '智能体项目知识块表')]
#[Index(name: 'idx_kchunk_project', columns: ['project_id', 'kind'])]
#[Index(name: 'idx_kchunk_status', columns: ['status'])]
class AgentKnowledgeChunk extends Model
{
    public const schema_table = 'weline_agent_knowledge_chunk';
    public const schema_primary_key = 'chunk_id';

    public array $_unit_primary_keys = ['chunk_id'];
    public array $_index_sort_keys = ['chunk_id', 'project_id'];

    public const KIND_SOP = 'sop';
    public const KIND_TRAJECTORY = 'trajectory';
    public const KIND_NOTE = 'note';
    public const KIND_TASK = 'task';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '知识块ID')]
    public const schema_fields_CHUNK_ID = 'chunk_id';

    #[Col('int', nullable: false, default: 0, comment: '所属项目ID（0=全局）')]
    public const schema_fields_PROJECT_ID = 'project_id';

    #[Col('varchar', 32, nullable: false, comment: '知识类型：sop/trajectory/note/task')]
    public const schema_fields_KIND = 'kind';

    #[Col('varchar', 64, comment: '业务引用类型+ID（如 trajectory/123）')]
    public const schema_fields_REF = 'ref';

    #[Col('varchar', 191, nullable: false, comment: '标题')]
    public const schema_fields_TITLE = 'title';

    #[Col('mediumtext', nullable: false, comment: '正文内容')]
    public const schema_fields_CONTENT = 'content';

    #[Col('varchar', 64, comment: 'SOP 去重键（同类型任务复用）')]
    public const schema_fields_SOP_KEY = 'sop_key';

    #[Col('text', comment: '关键词（空格分隔，BM25 侧）')]
    public const schema_fields_KEYWORDS = 'keywords';

    #[Col('varchar', 32, nullable: false, default: 'active', comment: 'active/archived')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', comment: '命中次数')]
    public const schema_fields_HIT_COUNT = 'hit_count';

    #[Col('int', comment: '最后命中时间')]
    public const schema_fields_LAST_HIT_AT = 'last_hit_at';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';

    #[Col('int', comment: '更新时间')]
    public const schema_fields_UPDATED_TIME = 'updated_time';

    public function save_before(): void
    {
        parent::save_before();
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_TIME, $now);
        }
        $this->setData(self::schema_fields_UPDATED_TIME, $now);
    }
}
