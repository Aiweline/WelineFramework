<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Tool;

use Weline\Ai\Api\ToolInterface;
use Weline\AiAcquisition\Service\Scan\RunScope;
use Weline\AiAcquisition\Service\Scan\ScanRegistry;
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

        // 页面去重：该站点已扫描过的页面、以及已入库线索的域名直接剔除，避免反复抓同一批结果。
        $skipped = 0;
        if (RunScope::hasWebsite()) {
            $registry = ObjectManager::getInstance(ScanRegistry::class);
            $seenUrls = $registry->recentUrls(RunScope::websiteId());
            $seenDomains = array_values(array_unique(array_merge(
                $registry->recentDomains(RunScope::websiteId()),
                $registry->leadDomains(RunScope::websiteId()),
            )));
            $filtered = ScanRegistry::filterBySeen(
                array_map(static fn(array $row): string => (string)($row['url'] ?? ''), $results),
                $seenUrls,
                $seenDomains
            );
            $allowedKeys = [];
            foreach ($filtered['allowed'] as $url) {
                $allowedKeys[ScanRegistry::normalize($url)] = true;
            }
            $kept = [];
            foreach ($results as $row) {
                $key = ScanRegistry::normalize((string)($row['url'] ?? ''));
                if (isset($allowedKeys[$key])) {
                    $kept[] = $row;
                }
            }
            $skipped = count($results) - count($kept);
            $results = $kept;

            if ($results === []) {
                return [
                    'query' => $query,
                    'count' => 0,
                    'skipped' => $skipped,
                    'results' => [],
                    'hint' => (string)__('这些结果都已扫描过（或已入库线索）。请更换关键词、换目标国家/渠道，不要重复抓同一批页面。'),
                ];
            }
        }

        return [
            'query' => $query,
            'count' => count($results),
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
