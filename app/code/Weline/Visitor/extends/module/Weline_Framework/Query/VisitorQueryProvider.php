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
        private ?PixelHotBufferService $hotBufferService = null,
        private ?\Weline\Visitor\Service\PixelMarkerAuditService $auditService = null,
        private ?\Weline\Visitor\Service\EventDictionaryService $dictionaryService = null,
        private ?\Weline\Visitor\Service\VisitorTrackingConfig $trackingConfig = null,
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
            'auditPixelMarkers' => $this->audit()->audit((int)($params['websiteId'] ?? $params['website_id'] ?? 0), (bool)($params['force'] ?? true)),
            'getPixelAuditReport' => $this->audit()->getLatestReport((int)($params['websiteId'] ?? $params['website_id'] ?? 0)),
            'getEventDictionary' => $this->dictionary()->listForPanel(),
            'getPixelChannelStatus' => $this->channelStatus((int)($params['websiteId'] ?? $params['website_id'] ?? 0)),
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
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Visitor::pixel_dashboard_realtime'],
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
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Visitor::pixel_dashboard'],
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
                [
                    'name' => 'auditPixelMarkers',
                    'description' => __('One-click audit of pixel markers on published pages.'),
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Visitor::pixel_dashboard'],
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 10,
                    'params' => [
                        'websiteId' => ['type' => 'int', 'required' => false],
                        'force' => ['type' => 'bool', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Audit pixel markers',
                ],
                [
                    'name' => 'getPixelAuditReport',
                    'description' => __('Load latest pixel marker audit report.'),
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Visitor::pixel_dashboard'],
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'params' => [
                        'websiteId' => ['type' => 'int', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get pixel audit report',
                ],
                [
                    'name' => 'getEventDictionary',
                    'description' => __('Load Weline↔Google event dictionary.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get event dictionary',
                ],
                [
                    'name' => 'getPixelChannelStatus',
                    'description' => __('Load GTM/GA4 channel and last audit status.'),
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Visitor::pixel_dashboard'],
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [
                        'websiteId' => ['type' => 'int', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get pixel channel status',
                ],
                $this->readOperation(
                    'analyticsBusinessValue',
                    'Load visitor business value analytics.',
                    'Weline_Visitor::pixel_dashboard_business_value'
                ),
                $this->readOperation(
                    'analyticsDashboard',
                    'Load visitor realtime dashboard analytics.',
                    'Weline_Visitor::pixel_dashboard_index'
                ),
                $this->readOperation(
                    'analyticsChangePercentage',
                    'Load visitor change percentage analytics.',
                    'Weline_Visitor::pixel_dashboard_daily_comparison'
                ),
                $this->readOperation(
                    'analyticsDailyComparison',
                    'Load visitor daily comparison analytics.',
                    'Weline_Visitor::pixel_dashboard_daily_comparison'
                ),
                $this->readOperation(
                    'analyticsAbTest',
                    'Load visitor A/B test analytics.',
                    'Weline_Visitor::pixel_dashboard'
                ),
                $this->readOperation(
                    'analyticsAbTestList',
                    'Load visitor A/B test list.',
                    'Weline_Visitor::pixel_dashboard'
                ),
                [
                    'name' => 'analyticsAbTestCreate',
                    'description' => __('Create visitor A/B test config.'),
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Visitor::pixel_dashboard'],
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
                $this->readOperation(
                    'analyticsReport',
                    'Load visitor analytics report.',
                    'Weline_Visitor::pixel_dashboard_event_stats'
                ),
                [
                    'name' => 'analyticsExport',
                    'description' => __('Export visitor analytics data.'),
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Visitor::pixel_dashboard_export'],
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
     * 解包 worker:visitor.trackPixel 入参（前端传 `{ payload: event }`）。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function payload(array $params): array
    {
        $payload = $params['payload'] ?? null;

        if (\is_string($payload) && $payload !== '') {
            $decoded = \json_decode($payload, true);
            $payload = \is_array($decoded) ? $decoded : null;
        }

        if (!\is_array($payload)) {
            return [];
        }

        // 兼容偶发双层：{ payload: { payload: event } }
        if (
            isset($payload['payload'])
            && \is_array($payload['payload'])
            && !\array_is_list($payload['payload'])
            && !isset($payload['url'])
            && !isset($payload['eventName'])
            && !isset($payload['event'])
            && !isset($payload['encrypted'])
        ) {
            $payload = $payload['payload'];
        }

        if ($payload === [] || \array_is_list($payload)) {
            return [];
        }

        if (!isset($payload['source']) || $payload['source'] === '' || $payload['source'] === null) {
            $payload['source'] = 'worker';
        }

        return $payload;
    }

    private function hotBuffer(): PixelHotBufferService
    {
        if (!$this->hotBufferService) {
            $this->hotBufferService = ObjectManager::getInstance(PixelHotBufferService::class);
        }

        return $this->hotBufferService;
    }

    private function audit(): \Weline\Visitor\Service\PixelMarkerAuditService
    {
        if (!$this->auditService) {
            $this->auditService = ObjectManager::getInstance(\Weline\Visitor\Service\PixelMarkerAuditService::class);
        }
        return $this->auditService;
    }

    private function dictionary(): \Weline\Visitor\Service\EventDictionaryService
    {
        if (!$this->dictionaryService) {
            $this->dictionaryService = ObjectManager::getInstance(\Weline\Visitor\Service\EventDictionaryService::class);
        }
        return $this->dictionaryService;
    }

    /**
     * @return array<string, mixed>
     */
    private function channelStatus(int $websiteId): array
    {
        if (!$this->trackingConfig) {
            $this->trackingConfig = ObjectManager::getInstance(\Weline\Visitor\Service\VisitorTrackingConfig::class);
        }
        $config = $this->trackingConfig->getRuntimeConfig();
        $report = $this->audit()->getLatestReport($websiteId);
        return [
            'website_id' => $websiteId,
            'dict_version' => (string)($config['dictVersion'] ?? ''),
            'gtm' => $config['gtm'] ?? [],
            'ga4' => $config['ga4'] ?? [],
            'forwarding' => [
                'gtm' => !empty($config['forwarders']['gtm']['enabled']),
                'ga4' => !empty($config['forwarders']['ga4']['enabled']),
                'exclude_local' => !empty($config['trafficRules']['excludeLocalForwarding']),
                'consent' => !empty($config['consent']['enabled']),
            ],
            'last_audit' => $report ? [
                'generated_at' => $report['generated_at'] ?? null,
                'expired_at' => $report['expired_at'] ?? null,
                'stale' => !empty($report['stale']),
                'summary' => $report['summary'] ?? null,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readOperation(string $name, string $summary, string $aclSource): array
    {
        return [
            'name' => $name,
            'description' => __($summary),
            'frontend' => true,
            'auth' => 'backend',
            'backend_acl' => ['kind' => 'source', 'source_id' => $aclSource],
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
