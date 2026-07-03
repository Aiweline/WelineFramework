<?php
declare(strict_types=1);

namespace Weline\Visitor\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\VisitorAnalyticsWorkerService;
use Weline\Visitor\Service\PixelEventService;
use Weline\Visitor\Service\PixelHotBufferService;

class VisitorQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly PixelEventService $pixelEventService,
        private readonly VisitorAnalyticsWorkerService $analyticsService,
        private ?PixelHotBufferService $hotBufferService = null
    ) {
    }

    public function getProviderName(): string
    {
        return 'visitor';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'trackPixel' => $this->pixelEventService->track($this->payload($params)),
            'pixelBufferStats' => $this->hotBuffer()->stats(),
            'pixelBufferFlush' => $this->hotBuffer()->flushDue((bool)($params['force'] ?? false), (int)($params['limit'] ?? 0)),
            'analyticsBusinessValue' => $this->analyticsService->businessValue($params),
            'analyticsDashboard' => $this->analyticsService->dashboard($params),
            'analyticsChangePercentage' => $this->analyticsService->changePercentage($params),
            'analyticsDailyComparison' => $this->analyticsService->dailyComparison($params),
            'analyticsAbTest' => $this->analyticsService->abTest($params),
            'analyticsAbTestList' => $this->analyticsService->abTestList($params),
            'analyticsAbTestCreate' => $this->analyticsService->abTestCreate($params),
            'analyticsReport' => $this->analyticsService->report($params),
            'analyticsExport' => $this->analyticsService->export($params),
            default => throw new \InvalidArgumentException('Visitor query provider does not support operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'visitor',
            'name' => __('Visitor Query'),
            'description' => __('Provides frontend visitor tracking operations through the worker channel.'),
            'module' => 'Weline_Visitor',
            'operations' => [
                [
                    'name' => 'trackPixel',
                    'description' => __('Track storefront visitor pixel events.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'payload' => ['type' => 'map', 'required' => true],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Track visitor pixel event',
                ],
                [
                    'name' => 'pixelBufferStats',
                    'description' => __('Load visitor pixel hot buffer status.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Load visitor pixel hot buffer status',
                ],
                [
                    'name' => 'pixelBufferFlush',
                    'description' => __('Flush visitor pixel hot buffer.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'force' => ['type' => 'bool', 'required' => false],
                        'limit' => ['type' => 'int', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Flush visitor pixel hot buffer',
                ],
                $this->readOperation('analyticsBusinessValue', 'Load visitor business value analytics.'),
                $this->readOperation('analyticsDashboard', 'Load visitor realtime dashboard analytics.'),
                $this->readOperation('analyticsChangePercentage', 'Load visitor change percentage analytics.'),
                $this->readOperation('analyticsDailyComparison', 'Load visitor daily comparison analytics.'),
                $this->readOperation('analyticsAbTest', 'Load visitor A/B test analytics.'),
                $this->readOperation('analyticsAbTestList', 'Load visitor A/B test list.'),
                [
                    'name' => 'analyticsAbTestCreate',
                    'description' => __('Create visitor A/B test config.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'testId' => ['type' => 'string', 'required' => true],
                        'name' => ['type' => 'string', 'required' => true],
                        'websiteId' => ['type' => 'int', 'required' => false],
                        'description' => ['type' => 'string', 'required' => false],
                        'status' => ['type' => 'string', 'required' => false],
                        'startDate' => ['type' => 'string', 'required' => false],
                        'endDate' => ['type' => 'string', 'required' => false],
                        'variantA' => ['type' => 'map', 'required' => false],
                        'variantB' => ['type' => 'map', 'required' => false],
                        'trafficSplit' => ['type' => 'string', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Create visitor A/B test config',
                ],
                $this->readOperation('analyticsReport', 'Load visitor analytics report.'),
                [
                    'name' => 'analyticsExport',
                    'description' => __('Export visitor analytics data.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 8,
                    'params' => [
                        'websiteId' => ['type' => 'int', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Export visitor analytics data',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function payload(array $params): array
    {
        $payload = $params['payload'] ?? [];
        return \is_array($payload) && !\array_is_list($payload) ? $payload : [];
    }

    private function hotBuffer(): PixelHotBufferService
    {
        if (!$this->hotBufferService) {
            $this->hotBufferService = ObjectManager::getInstance(PixelHotBufferService::class);
        }

        return $this->hotBufferService;
    }

    /**
     * @return array<string, mixed>
     */
    private function readOperation(string $name, string $summary): array
    {
        return [
            'name' => $name,
            'description' => __($summary),
            'frontend' => true,
            'mode' => 'read',
            'graph' => true,
            'cost' => 2,
            'params' => [
                'websiteId' => ['type' => 'int', 'required' => false],
                'period' => ['type' => 'string', 'required' => false],
                'startDate' => ['type' => 'string', 'required' => false],
                'endDate' => ['type' => 'string', 'required' => false],
                'interval' => ['type' => 'int', 'required' => false],
                'hours' => ['type' => 'int', 'required' => false],
                'days' => ['type' => 'int', 'required' => false],
                'testId' => ['type' => 'string', 'required' => false],
                'variant' => ['type' => 'string', 'required' => false],
                'status' => ['type' => 'string', 'required' => false],
            ],
            'returns' => ['type' => 'array'],
            'summary' => $summary,
        ];
    }
}
