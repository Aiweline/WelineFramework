<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Mcp\Tool;

use Weline\AiKnowledge\Service\SearchService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Search Documents Tool
 * 
 * Allows AI to search the Weline Framework documentation and codebase
 * using hybrid semantic and keyword search.
 */
class SearchDocsTool implements ToolInterface
{
    private SearchService $searchService;
    
    public function __construct()
    {
        $this->searchService = ObjectManager::getInstance(SearchService::class);
    }
    
    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search WelineFramework documentation, API references, and code examples. ' .
               'Use this to find information about modules, classes, configurations, and best practices.';
    }
    
    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query. Can be a natural language question or keywords.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['all', 'docs', 'api', 'code', 'config'],
                    'description' => 'Filter by content type. Default is "all".',
                ],
                'module' => [
                    'type' => 'string',
                    'description' => 'Filter by module name (e.g., "Weline_Framework", "Weline_Ai").',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return. Default is 10.',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
            'required' => ['query'],
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function execute(array $arguments): mixed
    {
        $query = $arguments['query'] ?? '';
        $type = $arguments['type'] ?? 'all';
        $module = $arguments['module'] ?? null;
        $limit = $arguments['limit'] ?? 10;
        
        if (empty($query)) {
            return [
                'success' => false,
                'error' => 'Query is required',
            ];
        }
        
        $results = $this->searchService->search($query, [
            'type' => $type,
            'module' => $module,
            'limit' => $limit,
        ]);
        
        return [
            'success' => true,
            'query' => $query,
            'total' => count($results),
            'results' => $results,
        ];
    }
}
