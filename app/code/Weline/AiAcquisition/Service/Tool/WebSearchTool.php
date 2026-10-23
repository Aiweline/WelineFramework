<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Tool;

use Weline\Ai\Api\ToolInterface;
use Weline\AiAcquisition\Service\Search\SearchService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 网页搜索工具：让获客智能体用公开搜索引擎找买家线索。
 *
 * 只负责检索与结果裁剪，线索入库由 save_lead 完成；空结果明确提示换关键词，避免模型编造。
 */
class WebSearchTool implements ToolInterface
{
    public function getName(): string
    {
        return 'web_search';
    }

    public function getDescription(): string
    {
        return (string)__('Search the public web for buyer-side leads. Returns title, url, snippet and rank. Empty results mean you must change keywords instead of inventing leads.');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query with buyer intent keywords, e.g. "looking for supplier" wholesale lighting. site: and quotes are passed through as-is.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Number of results to return, 1-20. Default 10.',
                    'minimum' => 1,
                    'maximum' => SearchService::MAX_RESULTS,
                ],
                'site' => [
                    'type' => 'string',
                    'description' => 'Optional domain restriction, e.g. reddit.com. Appended as site: only when query does not already contain site:.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $args): mixed
    {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['error' => (string)__('query 不能为空，请给出体现买家意向的检索词。')];
        }

        $limit = (int)($args['limit'] ?? 10);
        if ($limit <= 0) {
            $limit = 10;
        }
        $limit = min($limit, SearchService::MAX_RESULTS);
        $site = trim((string)($args['site'] ?? ''));

        try {
            $results = ObjectManager::getInstance(SearchService::class)->search($query, $limit, $site !== '' ? $site : null);
        } catch (\Throwable $e) {
            return ['error' => (string)__('搜索失败：%{1}', [$e->getMessage()])];
        }

        if ($results === []) {
            return [
                'query' => $query,
                'count' => 0,
                'results' => [],
                'hint' => (string)__('没有检索到结果。请更换同义词、买家意向词或来源后重试，不要据此编造线索。'),
            ];
        }

        return [
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
