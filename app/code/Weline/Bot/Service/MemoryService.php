<?php
declare(strict_types=1);

namespace Weline\Bot\Service;

use Weline\Ai\Agent\AgentResult;
use Weline\Bot\Model\BotMemoryNode;
use Weline\Bot\Model\BotMemoryEdge;
use Weline\Bot\Model\BotChatSession;
use Weline\Ai\Service\AiService;

/**
 * 记忆服务
 *
 * 管理 AI 的长期记忆，支持：
 * - 记忆提取（从对话中提取关键信息）
 * - 记忆存储（知识图谱节点和边）
 * - 记忆检索（语义搜索 + 关键词匹配）
 * - 记忆遗忘（时间衰减 + 低重要性清理）
 */
class MemoryService
{
    // 记忆类型
    public const TYPE_FACT = 'fact';
    public const TYPE_PREFERENCE = 'preference';
    public const TYPE_ENTITY = 'entity';
    public const TYPE_EVENT = 'event';

    public function __construct(
        private readonly BotMemoryNode $memoryNode,
        private readonly BotMemoryEdge $memoryEdge,
        private readonly AiService $aiService,
    ) {}

    /**
     * 从对话结果中提取并保存记忆
     */
    public function extractAndSave(AgentResult $result, BotChatSession $session): void
    {
        if (empty($result->content) || !$result->success) {
            return;
        }

        // 获取对话内容
        $conversationText = $this->buildConversationText($result);

        // 使用 AI 提取关键信息
        $extractionPrompt = $this->buildExtractionPrompt($conversationText);

        try {
            $extractionResult = $this->aiService->generate($extractionPrompt);

            // 解析提取结果
            $extractions = $this->parseExtractionResult($extractionResult);

            // 保存记忆节点
            foreach ($extractions as $extraction) {
                $this->saveMemoryNode(
                    $extraction['type'],
                    $extraction['key'],
                    $extraction['value'],
                    $extraction['importance'] ?? 0.5,
                    $session
                );
            }

        } catch (\Throwable $e) {
            // 提取失败不影响主流程
        }
    }

    /**
     * 获取相关记忆
     *
     * @param string $contextId 上下文 ID
     * @param int $limit 最大数量
     * @return array
     */
    public function getRelevantMemories(string $contextId, int $limit = 10): array
    {
        // 按重要性和访问时间排序
        $memories = $this->memoryNode->reset()
            ->where(BotMemoryNode::schema_fields_STATUS, BotMemoryNode::STATUS_ACTIVE)
            ->order(BotMemoryNode::schema_fields_IMPORTANCE, 'DESC')
            ->order(BotMemoryNode::schema_fields_LAST_ACCESSED, 'DESC')
            ->limit($limit * 2) // 多取一些，后面过滤
            ->select()
            ->fetch();

        $items = $memories->getItems();

        // 过滤过期记忆
        $validMemories = [];
        foreach ($items as $memory) {
            if (!$memory->isExpired() && !$memory->shouldForget()) {
                // 更新访问计数
                $memory->incrementAccess();
                $memory->save();
                $validMemories[] = $memory;
            }

            if (count($validMemories) >= $limit) {
                break;
            }
        }

        return $validMemories;
    }

    /**
     * 搜索记忆
     */
    public function search(string $query, int $limit = 10): array
    {
        // 简单的关键词搜索
        $memories = $this->memoryNode->reset()
            ->where(BotMemoryNode::schema_fields_STATUS, BotMemoryNode::STATUS_ACTIVE)
            ->whereLike(BotMemoryNode::schema_fields_NODE_VALUE, "%{$query}%")
            ->order(BotMemoryNode::schema_fields_IMPORTANCE, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch();

        $items = $memories->getItems();

        // 更新访问计数
        foreach ($items as $memory) {
            $memory->incrementAccess();
            $memory->save();
        }

        return $items;
    }

    /**
     * 保存记忆节点
     */
    public function saveMemoryNode(
        string $type,
        string $key,
        string $value,
        float $importance = 0.5,
        ?BotChatSession $session = null
    ): BotMemoryNode {
        // 检查是否已存在相同 key 的记忆
        $existing = $this->memoryNode->reset()
            ->where(BotMemoryNode::schema_fields_NODE_KEY, $key)
            ->find()
            ->fetch();

        if ($existing->getId()) {
            // 更新现有记忆
            $existing->setData(BotMemoryNode::schema_fields_NODE_VALUE, $value);
            // 更新重要性（取最大值）
            $currentImportance = (float) $existing->getData(BotMemoryNode::schema_fields_IMPORTANCE);
            $existing->setData(BotMemoryNode::schema_fields_IMPORTANCE, max($currentImportance, $importance));
            $existing->incrementAccess();
            $existing->save();
            return $existing;
        }

        // 创建新记忆
        $node = $this->memoryNode;
        $node->setData(BotMemoryNode::schema_fields_NODE_TYPE, $type);
        $node->setData(BotMemoryNode::schema_fields_NODE_KEY, $key);
        $node->setData(BotMemoryNode::schema_fields_NODE_VALUE, $value);
        $node->setData(BotMemoryNode::schema_fields_IMPORTANCE, $importance);
        $node->setData(BotMemoryNode::schema_fields_STATUS, BotMemoryNode::STATUS_ACTIVE);

        if ($session) {
            $node->setData(BotMemoryNode::schema_fields_SESSION_ID, $session->getId());
        }

        $node->save();
        return $node;
    }

    /**
     * 创建记忆关系
     */
    public function createEdge(
        int $sourceNodeId,
        int $targetNodeId,
        string $relationType,
        float $weight = 1.0
    ): BotMemoryEdge {
        $edge = $this->memoryEdge;
        $edge->setData(BotMemoryEdge::schema_fields_SOURCE_NODE_ID, $sourceNodeId);
        $edge->setData(BotMemoryEdge::schema_fields_TARGET_NODE_ID, $targetNodeId);
        $edge->setData(BotMemoryEdge::schema_fields_RELATION_TYPE, $relationType);
        $edge->setData(BotMemoryEdge::schema_fields_WEIGHT, $weight);
        $edge->save();

        return $edge;
    }

    /**
     * 清理过期和低价值记忆
     */
    public function cleanup(int $batchSize = 100): int
    {
        $cleaned = 0;

        // 查找需要遗忘的记忆
        $memories = $this->memoryNode->reset()
            ->where(BotMemoryNode::schema_fields_STATUS, BotMemoryNode::STATUS_ACTIVE)
            ->limit($batchSize)
            ->select()
            ->fetch();

        foreach ($memories->getItems() as $memory) {
            if ($memory->shouldForget()) {
                $memory->setData(BotMemoryNode::schema_fields_STATUS, BotMemoryNode::STATUS_FORGETTING);
                $memory->save();
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * 获取用户偏好
     */
    public function getUserPreferences(string $contextId): array
    {
        $preferences = $this->memoryNode->reset()
            ->where(BotMemoryNode::schema_fields_NODE_TYPE, self::TYPE_PREFERENCE)
            ->where(BotMemoryNode::schema_fields_STATUS, BotMemoryNode::STATUS_ACTIVE)
            ->order(BotMemoryNode::schema_fields_IMPORTANCE, 'DESC')
            ->limit(20)
            ->select()
            ->fetch();

        $result = [];
        foreach ($preferences->getItems() as $pref) {
            $key = $pref->getData(BotMemoryNode::schema_fields_NODE_KEY);
            $value = $pref->getData(BotMemoryNode::schema_fields_NODE_VALUE);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * 构建对话文本
     */
    private function buildConversationText(AgentResult $result): string
    {
        $text = '';
        foreach ($result->messages as $msg) {
            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? '';
            $text .= "[{$role}]: {$content}\n";
        }
        return $text;
    }

    /**
     * 构建提取提示词
     */
    private function buildExtractionPrompt(string $conversationText): string
    {
        return <<<PROMPT
分析以下对话，提取关键信息。请以 JSON 格式返回，包含以下字段：
- type: 信息类型（fact/preference/entity/event）
- key: 唯一标识键
- value: 具体内容
- importance: 重要程度（0-1）

只提取真正有价值的信息，忽略普通的寒暄和无关内容。

对话内容：
{$conversationText}

请返回 JSON 数组格式，例如：
[
  {"type": "preference", "key": "language", "value": "用户偏好使用中文交流", "importance": 0.7},
  {"type": "fact", "key": "project_name", "value": "项目名称是 Weline", "importance": 0.8}
]

如果没有有价值的信息，返回空数组 []。
PROMPT;
    }

    /**
     * 解析提取结果
     */
    private function parseExtractionResult(string $result): array
    {
        // 尝试从结果中提取 JSON
        if (preg_match('/\[[\s\S]*\]/m', $result, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
