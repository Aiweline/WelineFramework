<?php

declare(strict_types=1);

namespace WeShop\Analytics\Extends\Module\Weline_Framework\Query;

use WeShop\Analytics\Service\AnalyticsConfigService;
use WeShop\Analytics\Service\AnalyticsSnippetService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class AnalyticsQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly AnalyticsSnippetService $analyticsSnippetService,
        private readonly AnalyticsConfigService $analyticsConfigService
    ) {
    }

    public function getProviderName(): string
    {
        return 'analytics';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getFrontendPixelSnippets' => $this->analyticsSnippetService->getFrontendPixelSnippets(),
            'getFrontendPixelSnippetsBySlot' => $this->analyticsSnippetService->getFrontendPixelSnippetsBySlot(
                (string) ($params['slot'] ?? AnalyticsSnippetService::SLOT_HEAD)
            ),
            'getProviderStatuses' => $this->analyticsConfigService->getProviderStatuses(),
            default => throw new \InvalidArgumentException(
                (string) __('Analytics query provider does not support operation: %{1}', [$operation])
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'analytics',
            'name' => __('Analytics Query'),
            'description' => __('Provides storefront analytics snippets and provider readiness data.'),
            'module' => 'WeShop_Analytics',
            'operations' => [
                ['name' => 'getFrontendPixelSnippets', 'description' => __('Get enabled storefront analytics snippets.')],
                ['name' => 'getFrontendPixelSnippetsBySlot', 'description' => __('Get enabled storefront analytics snippets by hook slot.')],
                ['name' => 'getProviderStatuses', 'description' => __('Get analytics provider readiness and enablement states.')],
            ],
        ];
    }
}
