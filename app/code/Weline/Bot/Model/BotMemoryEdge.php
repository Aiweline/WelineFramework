<?php
declare(strict_types=1);

namespace Weline\Bot\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 记忆关系模型（知识图谱边）
 *
 * 存储记忆节点之间的关系，构建知识图谱
 */
#[Table(comment: '记忆关系表（知识图谱边）')]
#[Index(name: 'idx_source_node', columns: ['source_node_id'])]
#[Index(name: 'idx_target_node', columns: ['target_node_id'])]
#[Index(name: 'idx_relation_type', columns: ['relation_type'])]
class BotMemoryEdge extends Model
{
    public const schema_table = 'weline_bot_memory_edge';
    public const schema_primary_key = 'edge_id';

    public array $_unit_primary_keys = ['edge_id'];
    public array $_index_sort_keys = ['edge_id', 'source_node_id', 'target_node_id'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '边ID')]
    public const schema_fields_EDGE_ID = 'edge_id';

    #[Col('int', 11, nullable: false, comment: '源节点ID')]
    public const schema_fields_SOURCE_NODE_ID = 'source_node_id';

    #[Col('int', 11, nullable: false, comment: '目标节点ID')]
    public const schema_fields_TARGET_NODE_ID = 'target_node_id';

    #[Col('varchar', 100, nullable: false, comment: '关系类型')]
    public const schema_fields_RELATION_TYPE = 'relation_type';

    #[Col('float', default: 1.0, comment: '关系强度 0-1')]
    public const schema_fields_WEIGHT = 'weight';

    #[Col('text', comment: '关系元数据（JSON）')]
    public const schema_fields_METADATA = 'metadata';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    // 常见关系类型
    public const RELATION_IS_A = 'is_a';           // 是一种
    public const RELATION_HAS_A = 'has_a';         // 有一个
    public const RELATION_RELATED_TO = 'related_to'; // 相关
    public const RELATION_CAUSES = 'causes';       // 导致
    public const RELATION_OCCURS_AFTER = 'occurs_after'; // 发生在...之后
    public const RELATION_LOCATED_AT = 'located_at'; // 位于
    public const RELATION_BELONGS_TO = 'belongs_to'; // 属于
    public const RELATION_PREFERS = 'prefers';     // 偏好

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_EDGE_ID;
    }

    /**
     * 获取元数据
     */
    public function getMetadata(): array
    {
        $metadata = $this->getData(self::schema_fields_METADATA);
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($metadata) ? $metadata : [];
    }

    /**
     * 设置元数据
     */
    public function setMetadata(array $metadata): self
    {
        return $this->setData(self::schema_fields_METADATA, json_encode($metadata, JSON_UNESCAPED_UNICODE));
    }

    public function beforeSave(): self
    {
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, time());
        }

        if (is_array($this->getData(self::schema_fields_METADATA))) {
            $this->setData(self::schema_fields_METADATA, json_encode(
                $this->getData(self::schema_fields_METADATA),
                JSON_UNESCAPED_UNICODE
            ));
        }

        return parent::beforeSave();
    }
}
