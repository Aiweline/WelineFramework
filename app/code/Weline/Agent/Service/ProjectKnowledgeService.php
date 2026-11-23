<?php

declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentKnowledgeChunk;
use Weline\Ai\Api\Provider\ProviderRuntimeInterface;
use Weline\Ai\Model\AiModel;
use Weline\Framework\Manager\ObjectManager;

/**
 * 项目知识库（P4）：AgentKnowledgeChunk 项目级混合检索
 *
 * - 写入：index() 写入/更新 chunk（sop_key 去重 → 同类任务复用既有 SOP）。
 * - 检索：search() = 稠密（embedding 余弦，若有 embedding 模型）+ 稀疏
 *   （BM25 风格词频×逆文档频率），项目内 chunks 规模小，PHP 侧打分足够。
 * - embedding 由 Weline_Ai 的 embedding 模态模型经 ProviderRuntime 生成；
 *   无可用 embedding 模型时稠密腿自动缺席，退化为纯稀疏+文本排序（如实降级，
 *   不伪造向量）。
 */
class ProjectKnowledgeService
{
    private const SCORE_SPARSE_WEIGHT = 0.4;
    private const SCORE_DENSE_WEIGHT = 0.6;
    private const DEFAULT_LIMIT = 5;

    /** 索引一段项目知识；sop_key 非空时同项目同 key 复用更新（SOP 演进） */
    public function index(int $projectId, string $kind, string $title, string $content, string $ref = '', string $sopKey = ''): int
    {
        $content = trim($content);
        if ($content === '') {
            return 0;
        }
        $kind = in_array($kind, ['sop', 'trajectory', 'note', 'task'], true) ? $kind : 'note';

        // SOP 去重：同项目同 kind 同 sop_key 已有 active 记录 → 更新正文（流程被优化）
        if ($sopKey !== '') {
            $existing = $this->findBySopKey($projectId, $kind, $sopKey);
            if ($existing > 0) {
                return $this->updateChunk($existing, $title, $content, $ref);
            }
        }

        try {
            $chunk = ObjectManager::getInstance(AgentKnowledgeChunk::class);
            $chunk->setData(AgentKnowledgeChunk::schema_fields_PROJECT_ID, $projectId);
            $chunk->setData(AgentKnowledgeChunk::schema_fields_KIND, $kind);
            $chunk->setData(AgentKnowledgeChunk::schema_fields_REF, $ref !== '' ? mb_substr($ref, 0, 64) : null);
            $chunk->setData(AgentKnowledgeChunk::schema_fields_TITLE, mb_substr($title !== '' ? $title : mb_substr($content, 0, 60), 0, 191));
            $chunk->setData(AgentKnowledgeChunk::schema_fields_CONTENT, $content);
            $chunk->setData(AgentKnowledgeChunk::schema_fields_SOP_KEY, $sopKey !== '' ? mb_substr($sopKey, 0, 64) : null);
            $chunk->setData(AgentKnowledgeChunk::schema_fields_KEYWORDS, $this->extractKeywords($title . ' ' . $content));
            $chunk->setData(AgentKnowledgeChunk::schema_fields_EMBEDDING, $this->encodeEmbedding($this->embedText($title . "\n" . $content)));
            $chunk->setData(AgentKnowledgeChunk::schema_fields_STATUS, 'active');
            $chunk->save();
            return (int)$chunk->getId();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 混合检索：返回 [{chunk_id,title,content,kind,score}]
     * projectId>0 时同时命中 project_id=0 的全局 SOP（跨项目通用做法）
     */
    public function search(int $projectId, string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = trim($query);
        if ($query === '' || $limit <= 0) {
            return [];
        }

        $chunks = $this->loadActiveChunks($projectId);
        if ($chunks === []) {
            return [];
        }

        $queryTerms = $this->tokenize($query);
        $idf = $this->buildIdf($chunks, $queryTerms);
        $queryEmbedding = $this->embedText($query);

        $scored = [];
        foreach ($chunks as $chunk) {
            $sparse = $this->sparseScore($chunk, $queryTerms, $idf);
            $dense = ($queryEmbedding !== null && !empty($chunk['_embedding']))
                ? $this->cosine($queryEmbedding, $chunk['_embedding'])
                : null;
            // 无稠密信号时 sparse 独大；有稠密时按权重融合
            $score = $dense === null
                ? $sparse
                : (self::SCORE_DENSE_WEIGHT * max(0.0, $dense) + self::SCORE_SPARSE_WEIGHT * $sparse);
            if ($score <= 0.0) {
                continue;
            }
            $scored[] = [
                'chunk_id' => (int)$chunk['chunk_id'],
                'title' => (string)$chunk['title'],
                'content' => (string)$chunk['content'],
                'kind' => (string)$chunk['kind'],
                'sop_key' => (string)($chunk['sop_key'] ?? ''),
                'score' => round($score, 4),
            ];
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, $limit);

        // 命中计数回写（供「常用 SOP」排序/复用统计）
        foreach ($top as $hit) {
            $this->touchHit((int)$hit['chunk_id']);
        }

        return $top;
    }

    /** 检索结果拼成注入上下文的文本块；无结果返回空串 */
    public function buildContext(int $projectId, string $query, int $limit = self::DEFAULT_LIMIT): string
    {
        $hits = $this->search($projectId, $query, $limit);
        if ($hits === []) {
            return '';
        }
        $lines = ["【项目知识库命中（先复用已有做法，勿重复造轮子）】"];
        foreach ($hits as $i => $hit) {
            $tag = $hit['kind'] === 'sop' ? 'SOP' : strtoupper((string)$hit['kind']);
            $lines[] = ($i + 1) . ". [{$tag}] {$hit['title']}";
            $lines[] = '   ' . mb_substr(trim((string)$hit['content']), 0, 800);
        }
        return implode("\n", $lines);
    }

    /* ---------- 内部 ---------- */

    private function loadActiveChunks(int $projectId): array
    {
        $rows = [];
        try {
            $prototype = ObjectManager::getInstance(AgentKnowledgeChunk::class);
            $all = clone $prototype;
            $all = $all->reset()
                ->where(AgentKnowledgeChunk::schema_fields_STATUS, 'active')
                ->limit(2000)
                ->select()->fetchArray();
            if (!is_array($all)) {
                return [];
            }
            foreach ($all as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $pid = (int)($row[AgentKnowledgeChunk::schema_fields_PROJECT_ID] ?? 0);
                if ($pid !== $projectId && !($projectId > 0 && $pid === 0)) {
                    continue;
                }
                $row['_embedding'] = $this->decodeEmbedding($row[AgentKnowledgeChunk::schema_fields_EMBEDDING] ?? null);
                $rows[] = $row;
            }
        } catch (\Throwable) {
        }
        return $rows;
    }

    private function findBySopKey(int $projectId, string $kind, string $sopKey): int
    {
        try {
            $prototype = ObjectManager::getInstance(AgentKnowledgeChunk::class);
            $rows = clone $prototype;
            $rows = $rows->reset()
                ->where(AgentKnowledgeChunk::schema_fields_PROJECT_ID, $projectId)
                ->where(AgentKnowledgeChunk::schema_fields_KIND, $kind)
                ->where(AgentKnowledgeChunk::schema_fields_SOP_KEY, $sopKey)
                ->where(AgentKnowledgeChunk::schema_fields_STATUS, 'active')
                ->limit(1)
                ->select()->fetchArray();
            if (!is_array($rows)) {
                return 0;
            }
            foreach ($rows as $row) {
                if (is_array($row) && (int)($row[AgentKnowledgeChunk::schema_fields_CHUNK_ID] ?? 0) > 0) {
                    return (int)$row[AgentKnowledgeChunk::schema_fields_CHUNK_ID];
                }
            }
        } catch (\Throwable) {
        }
        return 0;
    }

    private function updateChunk(int $chunkId, string $title, string $content, string $ref): int
    {
        try {
            $chunk = ObjectManager::getInstance(AgentKnowledgeChunk::class);
            $chunk->load($chunkId);
            if (!$chunk->getId()) {
                return 0;
            }
            $chunk->setData(AgentKnowledgeChunk::schema_fields_TITLE, mb_substr($title !== '' ? $title : mb_substr($content, 0, 60), 0, 191));
            $chunk->setData(AgentKnowledgeChunk::schema_fields_CONTENT, $content);
            if ($ref !== '') {
                $chunk->setData(AgentKnowledgeChunk::schema_fields_REF, mb_substr($ref, 0, 64));
            }
            $chunk->setData(AgentKnowledgeChunk::schema_fields_KEYWORDS, $this->extractKeywords($title . ' ' . $content));
            $chunk->setData(AgentKnowledgeChunk::schema_fields_EMBEDDING, $this->encodeEmbedding($this->embedText($title . "\n" . $content)));
            $chunk->save();
            return $chunkId;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function touchHit(int $chunkId): void
    {
        try {
            $chunk = ObjectManager::getInstance(AgentKnowledgeChunk::class);
            $chunk->load($chunkId);
            if (!$chunk->getId()) {
                return;
            }
            $chunk->setData(AgentKnowledgeChunk::schema_fields_HIT_COUNT, (int)$chunk->getData(AgentKnowledgeChunk::schema_fields_HIT_COUNT) + 1);
            $chunk->setData(AgentKnowledgeChunk::schema_fields_LAST_HIT_AT, time());
            $chunk->save();
        } catch (\Throwable) {
        }
    }

    /** BM25 风格稀疏分：Σ tf(q,t)·idf(t) / (1 + 词数归一化) */
    private function sparseScore(array $chunk, array $queryTerms, array $idf): float
    {
        $content = mb_strtolower((string)$chunk['content'] . ' ' . (string)$chunk['title']);
        $len = max(1, mb_strlen($content));
        $score = 0.0;
        foreach ($queryTerms as $term => $qtf) {
            $tf = mb_substr_count($content, $term);
            if ($tf <= 0) {
                continue;
            }
            $score += $qtf * ($idf[$term] ?? 1.0) * (1.0 + log(1.0 + $tf)) / (1.0 + $len / 500.0);
        }
        return $score;
    }

    private function buildIdf(array $chunks, array $queryTerms): array
    {
        $n = max(1, count($chunks));
        $idf = [];
        foreach ($queryTerms as $term => $_) {
            $df = 0;
            foreach ($chunks as $chunk) {
                if (mb_stripos((string)$chunk['content'] . ' ' . (string)$chunk['title'], $term) !== false) {
                    $df++;
                }
            }
            $idf[$term] = log(1.0 + ($n - $df + 0.5) / ($df + 0.5));
        }
        return $idf;
    }

    private function tokenize(string $text): array
    {
        $terms = [];
        // CJK/词：连续中日韩字符按 2-gram 切；英文/数字按词
        if (preg_match_all('/[a-zA-Z0-9_]{2,}|[\x{4e00}-\x{9fff}]{2,}/u', mb_strtolower($text), $m)) {
            foreach ($m[0] as $tok) {
                if (preg_match('/[\x{4e00}-\x{9fff}]/u', $tok)) {
                    $chars = preg_split('//u', $tok, -1, PREG_SPLIT_NO_EMPTY);
                    $len = count($chars);
                    for ($i = 0; $i < $len - 1; $i++) {
                        $gram = $chars[$i] . $chars[$i + 1];
                        $terms[$gram] = ($terms[$gram] ?? 0) + 1;
                    }
                    if ($len === 2) {
                        $terms[$tok] = ($terms[$tok] ?? 0) + 1;
                    }
                } else {
                    $terms[$tok] = ($terms[$tok] ?? 0) + 1;
                }
            }
        }
        return $terms;
    }

    private function extractKeywords(string $text): string
    {
        $terms = array_keys($this->tokenize($text));
        // 补充：驼峰/命名空间类名整体保留（专有名词召回关键）
        if (preg_match_all('/[A-Z][A-Za-z0-9]*(?:\\\\[A-Z][A-Za-z0-9]*)*/', $text, $m)) {
            foreach ($m[0] as $name) {
                $terms[] = mb_strtolower($name);
            }
        }
        $terms = array_values(array_unique(array_filter($terms, static fn($t) => mb_strlen($t) >= 2)));
        return mb_substr(implode(' ', array_slice($terms, 0, 200)), 0, 60000);
    }

    private function cosine(array $a, array $b): float
    {
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }

    /** embedding 模型缺失/调用失败返回 null（如实降级） */
    private function embedText(string $text): ?array
    {
        try {
            $model = $this->resolveEmbeddingModel();
            if (!$model) {
                return null;
            }
            $provider = ObjectManager::getInstance(ProviderRuntimeInterface::class)->getProvider($model);
            $resp = $provider->generate($model, '', ['input' => mb_substr($text, 0, 8000)]);
            $vec = $resp['data'][0]['embedding'] ?? $resp['embedding'] ?? null;
            if (!is_array($vec) || $vec === []) {
                return null;
            }
            return array_map('floatval', $vec);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveEmbeddingModel(): ?AiModel
    {
        try {
            $prototype = ObjectManager::getInstance(AiModel::class);
            $rows = clone $prototype;
            $rows = $rows->reset()
                ->where(AiModel::schema_fields_PRIMARY_MODALITY, AiModel::PRIMARY_MODALITY_EMBEDDING)
                ->where(AiModel::schema_fields_STATUS, AiModel::STATUS_ACTIVE)
                ->limit(1)
                ->select()->fetchArray();
            if (!is_array($rows)) {
                return null;
            }
            foreach ($rows as $row) {
                if (is_array($row) && (int)($row[AiModel::schema_fields_ID] ?? $row['id'] ?? 0) > 0) {
                    $model = clone $prototype;
                    $model->load((int)($row[AiModel::schema_fields_ID] ?? $row['id']));
                    return $model->getId() ? $model : null;
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }

    private function decodeEmbedding($raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_map('floatval', $decoded) : null;
    }

    private function encodeEmbedding(?array $vec): ?string
    {
        return $vec === null ? null : json_encode($vec);
    }
}
