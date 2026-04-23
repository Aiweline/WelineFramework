<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrameworkQueryService;

/**
 * Compatibility adapter for PageBuilder workspace domain account lookup.
 *
 * The old QuickBuild navigation/aggregation feature has been removed. This
 * class intentionally keeps only the registrar-account query still referenced
 * by generated factories and the AI-site workspace.
 */
class QuickBuildAggregator
{
    public function __construct(
        private readonly ?FrameworkQueryService $queryService = null
    ) {
    }

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function queryRegistrarAccounts(array $filter = []): array
    {
        $queryService = $this->queryService ?? ObjectManager::getInstance(FrameworkQueryService::class);
        $rows = $queryService->execute('websites', 'getRegistrarAccounts', $filter);

        return \is_array($rows) ? \array_values(\array_filter($rows, 'is_array')) : [];
    }
}
