<?php
declare(strict_types=1);

namespace Weline\Bot\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 记忆节点模型（知识图谱节点）
 *
 * 存储长期记忆，支持事实、偏好、实体、事件等类型
 * 实现持久化记忆引擎的核心存储
 */
#[Table(comment: '记忆节点表（知识图谱）')]
#[Index(name: 'idx_node_key', columns: ['node_key'], type: 'UNIQUE')]
#[Index(name: 'idx_type', columns: ['node_type'])]
#[Index(name: 'idx_importance', columns: ['importance'])]
#[Index(name: 'idx_session_id', columns: ['session_id'])]
class BotMemoryNode extends Model
{
    public const schema_table = 'weline_bot_memory_node';
    public const schema_primary_key = 'node_id';

    public array $_unit_primary_keys = ['node_id'];
    public array $_index_sort_keys = ['node_id', 'node_key', 'importance', 'last_accessed'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '节点ID')]
    public const schema_fields_NODE_ID = 'node_id';

    #[Col('int', 11, comment: '关联会话ID（可选）')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col('varchar', 100, nullable: false, comment: '节点类型：fact/preference/entity/event')]
    public const schema_fields_NODE_TYPE = 'node_type';

    #[Col('varchar', 255, nullable: false, comment: '节点唯一标识')]
    public const schema_fields_NODE_KEY = 'node_key';

    #[Col('text', comment: '节点内容')]
    public const schema_fields_NODE_VALUE = 'node_value';

    #[Col('text', comment: '向量嵌入（用于语义搜索）')]
    public const schema_fields_EMBEDDING = 'embedding';

    #[Col('float', default: 0.5, comment: '重要度 0-1')]
    public const schema_fields_IMPORTANCE = 'importance';

    #[Col('int', 11, default: 0, comment: '访问次数')]
    public const schema_fields_ACCESS_COUNT = 'access_count';

    #[Col('int', comment: '最后访问时间')]
    public const schema_fields_LAST_ACCESSED = 'last_accessed';

    #[Col('int', comment: '过期时间（0表示永不过期）')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    #[Col('varchar', 50, default: 'active', comment: '状态：active/archived/forgetting')]
    public const schema_fields_STATUS = 'status';

    #[Col('text', comment: '元数据（JSON）')]
    public const schema_fields_METADATA = 'metadata';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('int', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const TYPE_FACT = 'fact';           // 事实
    public const TYPE_PREFERENCE = 'preference'; // 偏好
    public const TYPE_ENTITY = 'entity';       // 实体
    public const TYPE_EVENT = 'event';         // 事件

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_FORGETTING = 'forgetting';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_NODE_ID;
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

    /**
     * 增加访问次数
     */
    public function incrementAccess(): self
    {
        $count = (int) $this->getData(self::schema_fields_ACCESS_COUNT) + 1;
        $this->setData(self::schema_fields_ACCESS_COUNT, $count);
        $this->setData(self::schema_fields_LAST_ACCESSED, time());
        return $this;
    }

    /**
     * 计算记忆衰减分数
     * 基于时间衰减和访问频率
     */
    public function getDecayScore(): float
    {
        $importance = (float) $this->getData(self::schema_fields_IMPORTANCE);
        $accessCount = (int) $this->getData(self::schema_fields_ACCESS_COUNT);
        $lastAccessed = (int) $this->getData(self::schema_fields_LAST_ACCESSED);

        // 时间衰减：距离上次访问越久，衰减越多
        $daysSinceAccess = (time() - $lastAccessed) / 86400;
        $timeDecay = exp(-$daysSinceAccess / 30); // 30天半衰期

        // 访问频率加成
        $frequencyBonus = min(0.5, log($accessCount + 1) / 10);

        return $importance * $timeDecay + $frequencyBonus;
    }

    /**
     * 是否过期
     */
    public function isExpired(): bool
    {
        $expiresAt = (int) $this->getData(self::schema_fields_EXPIRES_AT);
        return $expiresAt > 0 && time() > $expiresAt;
    }

    /**
     * 是否应该遗忘
     */
    public function shouldForget(float $threshold = 0.1): bool
    {
        return $this->isExpired() || $this->getDecayScore() < $threshold;
    }

    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
            $this->setData(self::schema_fields_ACCESS_COUNT, 0);
            if (!$this->getData(self::schema_fields_LAST_ACCESSED)) {
                $this->setData(self::schema_fields_LAST_ACCESSED, $now);
            }
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);

        if (is_array($this->getData(self::schema_fields_METADATA))) {
            $this->setData(self::schema_fields_METADATA, json_encode(
                $this->getData(self::schema_fields_METADATA),
                JSON_UNESCAPED_UNICODE
            ));
        }

        return parent::beforeSave();
    }
}
