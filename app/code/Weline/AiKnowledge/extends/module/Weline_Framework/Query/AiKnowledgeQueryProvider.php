<?php
declare(strict_types=1);

namespace Weline\AiKnowledge\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * AiKnowledge 统一查询器
 *
 * 提供 search 能力，供其他模块通过 w_query('ai_knowledge', 'search', [...]) 调用。
 * 使用混合向量检索（语义 + 关键词）搜索文档与代码。
 */
class AiKnowledgeQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'ai_knowledge';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'search' => $this->search($params),
            default => throw new \InvalidArgumentException(
                __('AiKnowledge 查询器不支持的操作：%{1}', [$operation])
            ),
        };
    }

    private function search(array $params): array
    {
        $query = trim((string) ($params['query'] ?? ''));
        if (empty($query)) {
            return ['success' => false, 'error' => __('query is required'), 'results' => []];
        }

        $type = (string) ($params['type'] ?? 'all');
        $module = isset($params['module']) ? (string) $params['module'] : null;
        $limit = min(30, max(1, (int) ($params['limit'] ?? 15)));

        try {
            /** @var \Weline\AiKnowledge\Service\SearchService $searchService */
            $searchService = ObjectManager::getInstance(\Weline\AiKnowledge\Service\SearchService::class);
            $results = $searchService->search($query, [
                'type' => $type,
                'module' => $module,
                'limit' => $limit,
            ]);

            return [
                'success' => true,
                'search_type' => 'vector_hybrid',
                'query' => $query,
                'total' => count($results),
                'results' => $results,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => [],
            ];
        }
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'ai_knowledge',
            'name' => __('AI 知识库'),
            'description' => __('提供混合向量检索（语义+关键词）搜索文档、API、代码'),
            'module' => 'Weline_AiKnowledge',
            'operations' => [
                [
                    'name' => 'search',
                    'description' => __('语义/关键词混合搜索'),
                    'params' => [
                        ['name' => 'query', 'type' => 'string', 'required' => true, 'description' => __('搜索词或自然语言')],
                        ['name' => 'type', 'type' => 'string', 'required' => false, 'description' => __('all|docs|api|code|config')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块过滤')],
                        ['name' => 'limit', 'type' => 'integer', 'required' => false, 'description' => __('结果数量')],
                    ],
                ],
            ],
        ];
    }
}
