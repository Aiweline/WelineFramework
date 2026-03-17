<?php
declare(strict_types=1);

namespace Weline\Bot\Service\CodingAgent\Tool;

use Weline\Ai\Interface\ToolInterface;

/**
 * 代码库语义搜索工具
 *
 * 通过 w_query 跨模块调用：若 Weline_AiKnowledge 存在则使用其向量库检索；
 * 否则回退到 grep 文本搜索。Bot 不强制依赖 AiKnowledge。
 */
class CodebaseSearchTool implements ToolInterface
{
    public function getName(): string
    {
        return 'codebase_search';
    }

    public function getDescription(): string
    {
        return __('Semantic search over codebase: finds relevant code, classes, APIs by meaning. Use for exploring structure, finding similar implementations, or understanding how something works.');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => __('Natural language or keywords (e.g. "where is session stored", "class that handles ACL")'),
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['all', 'docs', 'api', 'code', 'config'],
                    'description' => __('Filter: all|docs|api|code|config. Default all'),
                ],
                'module' => [
                    'type' => 'string',
                    'description' => __('Filter by module, e.g. Weline_Framework, Weline_Ai'),
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 15,
                    'description' => __('Max results (1-30)'),
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $args): mixed
    {
        $query = trim($args['query'] ?? '');
        if (empty($query)) {
            return ['error' => __('query is required')];
        }

        $type = $args['type'] ?? 'all';
        $module = $args['module'] ?? null;
        $limit = min(30, max(1, (int) ($args['limit'] ?? 15)));

        $vectorResult = $this->vectorSearchViaQuery($query, $type, $module, $limit);
        if ($vectorResult !== null) {
            return $vectorResult;
        }

        return $this->fallbackGrepSearch($query, $limit);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * 通过 w_query 调用 AiKnowledge 向量库搜索（若模块存在）。
     *
     * @return array|null 成功时返回结果数组，provider 不存在或失败时返回 null（调用方将回退 grep）
     */
    private function vectorSearchViaQuery(string $query, string $type, ?string $module, int $limit): ?array
    {
        try {
            $result = w_query('ai_knowledge', 'search', [
                'query' => $query,
                'type' => $type,
                'module' => $module,
                'limit' => $limit,
            ]);

            if (!is_array($result) || empty($result['success'])) {
                return null;
            }

            return [
                'search_type' => $result['search_type'] ?? 'vector_hybrid',
                'query' => $query,
                'total' => $result['total'] ?? 0,
                'results' => $result['results'] ?? [],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function fallbackGrepSearch(string $query, int $limit): array
    {
        $grep = new GrepTool();
        $args = [
            'pattern' => preg_quote($query, '/'),
            'path' => 'app/code',
            'glob' => '*',
            'max_results' => $limit,
        ];

        $grepResult = $grep->execute($args);
        if (isset($grepResult['error'])) {
            return $grepResult;
        }

        $formatted = array_map(static fn($r) => [
            'path' => $r['path'],
            'line' => $r['line'],
            'content' => $r['content'],
            'score' => 1.0,
        ], $grepResult['results'] ?? []);

        return [
            'search_type' => 'grep_fallback',
            'query' => $query,
            'total' => count($formatted),
            'results' => $formatted,
        ];
    }
}
