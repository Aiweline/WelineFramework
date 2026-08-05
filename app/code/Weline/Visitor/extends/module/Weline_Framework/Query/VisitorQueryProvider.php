<?php
declare(strict_types=1);

namespace Weline\Visitor\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\VisitorAnalyticsWorkerService;
use Weline\Visitor\Service\PixelEventService;
use Weline\Visitor\Service\PixelHotBufferService;
use Weline\Visitor\Service\OptimizationSnapshotService;
use Weline\Visitor\Service\PageBuilderOptimizationAttributionService;

class VisitorQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly PixelEventService $pixelEventService,
        private readonly VisitorAnalyticsWorkerService $analyticsService,
        private ?PixelHotBufferService $hotBufferService = null,
        private ?\Weline\Visitor\Service\PixelMarkerAuditService $auditService = null,
        private ?\Weline\Visitor\Service\EventDictionaryService $dictionaryService = null,
        private ?\Weline\Visitor\Service\VisitorTrackingConfig $trackingConfig = null,
        private ?OptimizationSnapshotService $optimizationSnapshotService = null,
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
            'analyticsOptimizationSnapshot' => $this->optimizationSnapshot()->snapshot($params),
            'analyticsAcceptanceFixture' => $this->acceptanceFixture($params),
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
                [
                    'name' => 'analyticsOptimizationSnapshot',
                    'description' => __('读取优化闭环的只读访客证据。'),
                    'frontend' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'website_id' => ['type' => 'int', 'required' => true],
                        'start_date' => ['type' => 'string', 'required' => true],
                        'end_date' => ['type' => 'string', 'required' => true],
                        'page_type' => ['type' => 'string', 'required' => false],
                        'block_key' => ['type' => 'string', 'required' => false],
                        'plan_revision' => ['type' => 'int', 'required' => false],
                        'content_fingerprint' => ['type' => 'string', 'required' => false],
                        'experiment_id' => ['type' => 'string', 'required' => false],
                        'variant' => ['type' => 'string', 'required' => false],
                        'target_event' => ['type' => 'string', 'required' => false],
                    ],
                    'returns' => ['type' => 'array', 'contract' => 'visitor.optimization_snapshot.v1'],
                    'summary' => 'Load read-only visitor optimization evidence.',
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
                    'name' => 'analyticsAcceptanceFixture',
                    'description' => __('Seed or clean auditable AI/SEO acceptance pixel events.'),
                    'frontend' => false,
                    'server_only' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 20,
                    'params' => [
                        'action' => ['type' => 'string', 'required' => true],
                        'context' => ['type' => 'string', 'required' => true],
                        'case_id' => ['type' => 'string', 'required' => true],
                        'request_key' => ['type' => 'string', 'required' => true],
                        'website_id' => ['type' => 'int', 'required' => true],
                        'manifest' => ['type' => 'list', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Seed or clean AI SEO acceptance pixel events',
                ],
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

    /** @return array<string, mixed> */
    private function acceptanceFixture(array $params): array
    {
        $this->assertAcceptanceFixtureEnabled();
        $this->assertAcceptanceFixtureServerContext($params);
        $this->assertNoAcceptanceOverrides($params);

        $action = (string)($params['action'] ?? '');
        if (!\in_array($action, ['seed', 'cleanup'], true)) {
            throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_ACTION_INVALID');
        }
        $caseId = (string)($params['case_id'] ?? '');
        if ($caseId !== 'ai-seo-v2-closed-loop') {
            throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_CASE_NOT_ALLOWED');
        }
        $requestKey = (string)($params['request_key'] ?? '');
        if (!\preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $requestKey)) {
            throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_REQUEST_KEY_INVALID');
        }
        $websiteId = $this->acceptanceWebsiteId($params['website_id'] ?? null);
        $manifest = $action === 'seed'
            ? $this->normalizeAcceptanceManifest($params['manifest'] ?? null)
            : [];
        $fingerprint = $action === 'seed'
            ? \hash('sha256', (string)\json_encode([
                'case_id' => $caseId,
                'website_id' => $websiteId,
                'manifest' => $manifest,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            : '';
        $receiptPath = $this->acceptanceReceiptPath($requestKey);
        $lock = \fopen($receiptPath . '.lock', 'c+');
        if (!\is_resource($lock) || !\flock($lock, LOCK_EX)) {
            if (\is_resource($lock)) {
                \fclose($lock);
            }
            throw new \RuntimeException('VISITOR_ACCEPTANCE_RECEIPT_LOCK_FAILED');
        }

        try {
            $existing = $this->readAcceptanceReceipt($receiptPath);
            if ($action === 'cleanup') {
                if ($existing === []) {
                    throw new \RuntimeException('VISITOR_ACCEPTANCE_RECEIPT_NOT_FOUND');
                }
                if ((string)($existing['case_id'] ?? '') !== $caseId
                    || (int)($existing['website_id'] ?? -1) !== $websiteId
                    || (string)($existing['request_key'] ?? '') !== $requestKey) {
                    throw new \RuntimeException('VISITOR_ACCEPTANCE_RECEIPT_CONFLICT');
                }
                if ((string)($existing['status'] ?? '') === 'cleaned') {
                    $existing['replayed'] = true;
                    return $existing;
                }
                $ownedEvents = \is_array($existing['created_events'] ?? null)
                    ? $existing['created_events']
                    : [];
                $cleanup = $this->pixelEventService->cleanupAcceptanceFixtureEvents(
                    $websiteId,
                    $ownedEvents,
                    $requestKey
                );
                $existing['status'] = !empty($cleanup['complete']) ? 'cleaned' : 'cleanup_partial';
                $existing['replayed'] = false;
                $existing['cleanup'] = [
                    'capable' => true,
                    'status' => $existing['status'],
                    'result' => $cleanup,
                ];
                $this->writeAcceptanceReceipt($receiptPath, $existing);
                return $existing;
            }

            $ownership = $this->buildAcceptanceOwnership($manifest, $requestKey);
            if ($existing !== []) {
                if ((string)($existing['fingerprint'] ?? '') !== $fingerprint) {
                    throw new \RuntimeException('VISITOR_ACCEPTANCE_REQUEST_KEY_CONFLICT');
                }
                if (\in_array((string)($existing['status'] ?? ''), ['seeded', 'cleaned', 'cleanup_partial'], true)) {
                    $existing['replayed'] = true;
                    return $existing;
                }
                try {
                    $this->pixelEventService->cleanupAcceptanceFixtureEvents(
                        $websiteId,
                        $ownership,
                        $requestKey
                    );
                } catch (\Throwable) {
                    // A failed/stale journal remains auditable; the new attempt owns the same exact markers.
                }
            }

            $receipt = [
                'contract' => 'visitor.acceptance_fixture_receipt.v1',
                'status' => 'seeding',
                'request_key' => $requestKey,
                'case_id' => $caseId,
                'website_id' => $websiteId,
                'fingerprint' => $fingerprint,
                'created_event_count' => 0,
                'created_event_ids' => [],
                'created_events' => [],
                'replayed' => false,
                'cleanup' => [
                    'capable' => true,
                    'status' => 'pending',
                    'result' => null,
                ],
            ];
            $this->writeAcceptanceReceipt($receiptPath, $receipt);

            try {
                foreach ($manifest as $ordinal => $event) {
                    $ownedEvent = $ownership[$ordinal];
                    $persisted = $this->pixelEventService->persistAcceptanceFixtureEvent(
                        $this->acceptanceFixturePixelPayload(
                            $event,
                            $ownedEvent,
                            $websiteId,
                            $caseId,
                            $requestKey,
                            $fingerprint
                        )
                    );
                    $persistedData = \is_array($persisted['data'] ?? null)
                        ? $persisted['data']
                        : [];
                    $pixelId = (int)($persistedData['pixel_id'] ?? 0);
                    if (!empty($persistedData['buffered']) || $pixelId <= 0) {
                        throw new \RuntimeException('VISITOR_ACCEPTANCE_PERSISTENCE_UNCONFIRMED');
                    }
                    $ownedEvent['pixel_id'] = $pixelId;
                    $receipt['created_events'][] = $ownedEvent;
                    $receipt['created_event_ids'][] = $pixelId;
                    $receipt['created_event_count'] = \count($receipt['created_events']);
                    $this->writeAcceptanceReceipt($receiptPath, $receipt);
                }
            } catch (\Throwable $error) {
                $cleanup = null;
                try {
                    $cleanup = $this->pixelEventService->cleanupAcceptanceFixtureEvents(
                        $websiteId,
                        $ownership,
                        $requestKey
                    );
                } catch (\Throwable) {
                    $cleanup = ['complete' => false, 'error' => 'cleanup_failed'];
                }
                $receipt['status'] = 'failed';
                $receipt['cleanup'] = [
                    'capable' => true,
                    'status' => !empty($cleanup['complete']) ? 'cleaned_after_failure' : 'cleanup_partial',
                    'result' => $cleanup,
                ];
                $this->writeAcceptanceReceipt($receiptPath, $receipt);
                throw new \RuntimeException('VISITOR_ACCEPTANCE_TRACK_FAILED', 0, $error);
            }

            $receipt['status'] = 'seeded';
            $this->writeAcceptanceReceipt($receiptPath, $receipt);
            return $receipt;
        } finally {
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }
    }

    private function assertAcceptanceFixtureEnabled(): void
    {
        if ((string)\getenv('WELINE_ACCEPTANCE_FIXTURES') !== '1'
            || (string)\getenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE') !== '1') {
            throw new \RuntimeException('VISITOR_ACCEPTANCE_FIXTURE_DISABLED');
        }
    }

    /** @param array<string, mixed> $params */
    private function assertAcceptanceFixtureServerContext(array $params): void
    {
        $context = (string)($params['context'] ?? '');
        $origin = \strtolower((string)($params['origin'] ?? ''));
        if ($context !== 'server'
            || !empty($params['browser'])
            || !empty($params['frontend'])
            || \in_array($origin, ['browser', 'frontend', 'javascript'], true)) {
            throw new \RuntimeException('VISITOR_ACCEPTANCE_SERVER_ONLY');
        }
    }

    private function assertNoAcceptanceOverrides(mixed $value): void
    {
        if (!\is_array($value)) {
            return;
        }
        $forbidden = [
            'metrics', 'metric', 'summary', 'comparison', 'aggregates', 'aggregate',
            'raw_sql', 'sql', 'query', 'override', 'page_views', 'conversions',
            'impressions', 'ctr', 'conversion_rate', 'rows',
        ];
        foreach ($value as $key => $item) {
            if (\is_string($key) && \in_array(\strtolower($key), $forbidden, true)) {
                throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_OVERRIDE_FORBIDDEN');
            }
            $this->assertNoAcceptanceOverrides($item);
        }
    }

    private function acceptanceWebsiteId(mixed $rawWebsiteId): int
    {
        return PixelEventService::acceptanceWebsiteId($rawWebsiteId);
    }

    /** @return list<array<string, mixed>> */
    private function normalizeAcceptanceManifest(mixed $manifest): array
    {
        if (!\is_array($manifest)
            || !\array_is_list($manifest)
            || $manifest === []
            || \count($manifest) > 2000) {
            throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_MANIFEST_INVALID');
        }
        $allowedKeys = [
            'event', 'created_at', 'session_id', 'page_type', 'block_key',
            'plan_revision', 'content_fingerprint', 'experiment_id', 'variant',
            'value', 'canonical_path', 'target_event',
        ];
        $normalized = [];
        foreach ($manifest as $row) {
            if (!\is_array($row) || \array_diff(\array_keys($row), $allowedKeys) !== []) {
                throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_MANIFEST_FIELD_FORBIDDEN');
            }
            $event = (string)($row['event'] ?? '');
            if ($event === 'block_impression') {
                $event = 'ai_block_impression';
            }
            if (!PageBuilderOptimizationAttributionService::isAllowedEvent($event)) {
                throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_EVENT_INVALID');
            }
            $createdAt = (string)($row['created_at'] ?? '');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $createdAt);
            if (!$date || $date->format('Y-m-d H:i:s') !== $createdAt) {
                throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_CREATED_AT_INVALID');
            }
            $sessionId = (string)($row['session_id'] ?? '');
            if (!\preg_match('/^[A-Za-z0-9._:-]{1,96}$/', $sessionId)) {
                throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_SESSION_INVALID');
            }
            $planRevision = $row['plan_revision'] ?? null;
            if (!\is_int($planRevision) || $planRevision < 0) {
                throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_REVISION_INVALID');
            }
            $fingerprint = \strtolower(\trim((string)($row['content_fingerprint'] ?? '')));
            if (!\preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
                throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_FINGERPRINT_INVALID');
            }
            $canonicalPath = (string)($row['canonical_path'] ?? '/');
            if ($canonicalPath === '' || $canonicalPath[0] !== '/' || \strlen($canonicalPath) > 255) {
                throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_PATH_INVALID');
            }
            $value = $row['value'] ?? 0;
            if (!\is_int($value) && !\is_float($value)) {
                throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_VALUE_INVALID');
            }
            $value = (float)$value;
            if (!\is_finite($value) || $value < 0) {
                throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_VALUE_INVALID');
            }
            $pageType = $this->acceptanceIdentifier(
                $row['page_type'] ?? null,
                64,
                true,
                'VISITOR_ACCEPTANCE_PAGE_TYPE_INVALID'
            );
            $blockKey = $this->acceptanceIdentifier(
                $row['block_key'] ?? '',
                128,
                $event !== 'page_view',
                'VISITOR_ACCEPTANCE_BLOCK_KEY_INVALID'
            );
            $experimentId = $this->acceptanceIdentifier(
                $row['experiment_id'] ?? '',
                96,
                false,
                'VISITOR_ACCEPTANCE_EXPERIMENT_INVALID'
            );
            $variant = $this->acceptanceIdentifier(
                $row['variant'] ?? '',
                32,
                false,
                'VISITOR_ACCEPTANCE_VARIANT_INVALID'
            );
            $canonicalPath = $this->acceptanceCanonicalPath($canonicalPath);
            $targetEvent = (string)($row['target_event'] ?? $event);
            if ($targetEvent === 'block_impression') {
                $targetEvent = 'ai_block_impression';
            }
            if (!PageBuilderOptimizationAttributionService::isAllowedEvent($targetEvent)) {
                throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_TARGET_EVENT_INVALID');
            }
            $normalized[] = [
                'event' => $event,
                'created_at' => $createdAt,
                'session_id' => $sessionId,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'plan_revision' => $planRevision,
                'content_fingerprint' => $fingerprint,
                'experiment_id' => $experimentId,
                'variant' => $variant,
                'value' => $value,
                'canonical_path' => $canonicalPath,
                'target_event' => $targetEvent,
            ];
        }
        return $normalized;
    }

    private function acceptanceIdentifier(
        mixed $value,
        int $maximumLength,
        bool $required,
        string $errorCode
    ): string {
        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException($errorCode);
        }
        $value = \trim((string)$value);
        if ($value === '') {
            if ($required) {
                throw new \InvalidArgumentException($errorCode);
            }
            return '';
        }
        if (\strlen($value) > $maximumLength
            || !\preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new \InvalidArgumentException($errorCode);
        }
        return $value;
    }

    private function acceptanceCanonicalPath(string $canonicalPath): string
    {
        if ($canonicalPath === ''
            || $canonicalPath[0] !== '/'
            || \strlen($canonicalPath) > 255
            || \str_contains($canonicalPath, '?')
            || \str_contains($canonicalPath, '#')
            || \preg_match('/[\x00-\x1F\x7F]/', $canonicalPath)) {
            throw new \InvalidArgumentException('VISITOR_ACCEPTANCE_PATH_INVALID');
        }
        return $canonicalPath;
    }

    /**
     * @param list<array<string, mixed>> $manifest
     * @return list<array<string, mixed>>
     */
    private function buildAcceptanceOwnership(array $manifest, string $requestKey): array
    {
        $sessions = [];
        $ownership = [];
        foreach ($manifest as $ordinal => $event) {
            $logicalSession = (string)$event['session_id'];
            $sessions[$logicalSession] ??= 'ai-seo-' . \substr(
                \hash('sha256', $requestKey . "\0" . $logicalSession),
                0,
                40
            );
            $ownership[] = [
                'fixture_event_id' => 'af_evt_' . \substr(
                    \hash('sha256', $requestKey . "\0" . $ordinal . "\0" . $event['event']),
                    0,
                    32
                ),
                'ordinal' => $ordinal,
                'event' => (string)$event['event'],
                'session_id' => $sessions[$logicalSession],
                'created_at' => (string)$event['created_at'],
            ];
        }
        return $ownership;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $ownedEvent
     * @return array<string, mixed>
     */
    private function acceptanceFixturePixelPayload(
        array $event,
        array $ownedEvent,
        int $websiteId,
        string $caseId,
        string $requestKey,
        string $fingerprint
    ): array {
        $marker = [
            'contract' => 'visitor.acceptance_fixture_marker.v1',
            'case_id' => $caseId,
            'request_key' => $requestKey,
            'manifest_fingerprint' => $fingerprint,
            'fixture_event_id' => $ownedEvent['fixture_event_id'],
            'ordinal' => $ownedEvent['ordinal'],
        ];
        return [
            'module' => 'pagebuilder_ai_acceptance',
            'name' => $event['event'],
            'event' => $event['event'],
            'eventName' => $event['event'],
            'url' => 'https://visitor-acceptance.invalid' . $event['canonical_path'],
            'websiteId' => $websiteId,
            'website_id' => $websiteId,
            'sessionId' => $ownedEvent['session_id'],
            'session_id' => $ownedEvent['session_id'],
            'createdAt' => $event['created_at'],
            'created_at' => $event['created_at'],
            'value' => $event['value'],
            'userAgent' => 'Weline-Visitor-Acceptance-Fixture/1.0',
            'additionalInfo' => [
                'schema' => 'weline_behavior_timing_v2',
                'environment' => [
                    'page_location' => 'https://visitor-acceptance.invalid' . $event['canonical_path'],
                    'page_path' => $event['canonical_path'],
                    'website_id' => (string)$websiteId,
                ],
                'pagebuilder_attribution' => [
                    'attribution_version' => 'pagebuilder_ai_v1',
                    'source' => 'pagebuilder_rendered_dom',
                    'surface' => 'published',
                    'analytics_consent' => 'granted',
                    'preview' => false,
                    'website_id' => (string)$websiteId,
                    'page_type' => $event['page_type'],
                    'block_key' => $event['block_key'],
                    'plan_revision' => $event['plan_revision'],
                    'content_fingerprint' => $event['content_fingerprint'],
                    'experiment_id' => $event['experiment_id'],
                    'variant' => $event['variant'],
                    'canonical_path' => $event['canonical_path'],
                ],
                'meta' => [
                    'acceptance_fixture' => $marker,
                    'target_event' => $event['target_event'],
                ],
            ],
        ];
    }

    private function acceptanceReceiptPath(string $requestKey): string
    {
        $directory = BP . '/var/visitor/acceptance-fixtures';
        if (!\is_dir($directory)
            && !@\mkdir($directory, 0700, true)
            && !\is_dir($directory)) {
            throw new \RuntimeException('VISITOR_ACCEPTANCE_RECEIPT_DIRECTORY_FAILED');
        }
        return $directory . '/' . \hash('sha256', $requestKey) . '.json';
    }

    /** @return array<string, mixed> */
    private function readAcceptanceReceipt(string $path): array
    {
        if (!\is_file($path)) {
            return [];
        }
        $decoded = \json_decode((string)\file_get_contents($path), true);
        if (!\is_array($decoded)
            || (string)($decoded['contract'] ?? '') !== 'visitor.acceptance_fixture_receipt.v1') {
            throw new \RuntimeException('VISITOR_ACCEPTANCE_RECEIPT_CORRUPT');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $receipt */
    private function writeAcceptanceReceipt(string $path, array $receipt): void
    {
        $json = \json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!\is_string($json)) {
            throw new \RuntimeException('VISITOR_ACCEPTANCE_RECEIPT_ENCODE_FAILED');
        }
        $temporary = $path . '.' . \getmypid() . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        try {
            if (\file_put_contents($temporary, $json, LOCK_EX) === false
                || !@\chmod($temporary, 0600)
                || !@\rename($temporary, $path)) {
                throw new \RuntimeException('VISITOR_ACCEPTANCE_RECEIPT_WRITE_FAILED');
            }
        } finally {
            if (\is_file($temporary)) {
                @\unlink($temporary);
            }
        }
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

    private function optimizationSnapshot(): OptimizationSnapshotService
    {
        return $this->optimizationSnapshotService ??= ObjectManager::getInstance(OptimizationSnapshotService::class);
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
