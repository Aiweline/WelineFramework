<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Rest\V1;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\DeveloperWorkspace\Api\DevToolRestController;
use Weline\DeveloperWorkspace\Service\DevToolPayloadStore;
use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\WlsPerformanceTraceStore;

class Trace extends DevToolRestController
{
    private const TRACE_TTL_SECONDS = 60;

    private DevToolPayloadStore $payloadStore;
    private ?WlsPerformanceTraceStore $wlsTraceStore;

    public function __construct(?DevToolPayloadStore $payloadStore = null, ?WlsPerformanceTraceStore $wlsTraceStore = null)
    {
        parent::__construct();
        $this->payloadStore = $payloadStore ?? new DevToolPayloadStore();
        $this->wlsTraceStore = $wlsTraceStore;
    }

    public function getIndex()
    {
        if (!$this->isAllowed()) {
            return $this->error('dev tool trace is not allowed', [], 403);
        }

        $requestId = (string)$this->request->getGet('id', '');
        if ($requestId === '' || !\preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $requestId)) {
            return $this->error('invalid request id', [], 400);
        }

        $payload = $this->payloadStore->get('trace', 'trace:' . $requestId);
        if (!\is_array($payload)) {
            $payload = $this->wlsTracePayload($requestId);
        }
        if (!\is_array($payload)) {
            $payload = $this->payloadStore->getLatest('trace', $this->traceTtl());
        }
        if (!\is_array($payload)) {
            $payload = $this->wlsTracePayload('');
        }
        if (!\is_array($payload)) {
            return $this->error('请求链路不存在或已过期，请刷新页面重试', [
                'request_id' => $requestId,
                'ttl' => $this->traceTtl(),
                'wls_ttl' => $this->wlsTraceWindow(),
            ], 404);
        }

        return $this->success('success', $payload);
    }

    /**
     * @return array{request_id: string, format: string, trace: string, dict: array<string, mixed>, summary: array<string, mixed>}|null
     */
    private function wlsTracePayload(string $requestId): ?array
    {
        try {
            $store = $this->wlsTraceStore ?? new WlsPerformanceTraceStore();
            $record = $requestId !== '' ? $store->getDetail($requestId) : [];
            if ($record === []) {
                $rows = $store->requests(1, $this->wlsTraceWindow());
                $latestRequestId = (string)($rows[0]['request_id'] ?? '');
                $record = $latestRequestId !== '' ? $store->getDetail($latestRequestId) : [];
            }

            return $record !== [] ? $this->compactWlsTraceRecord($record) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $record
     * @return array{request_id: string, format: string, trace: string, dict: array<string, mixed>, summary: array<string, mixed>}|null
     */
    private function compactWlsTraceRecord(array $record): ?array
    {
        $spans = \is_array($record['trace']['spans'] ?? null) ? $record['trace']['spans'] : [];
        if ($spans === []) {
            return null;
        }

        $names = [];
        $nameIds = [];
        $categories = [];
        $categoryIds = [];
        $metas = [];
        $metaIds = [];
        $rows = [];
        $categoryCounts = [];
        $dbDurationMs = 0.0;
        $seq = 0;

        foreach ($spans as $span) {
            if (!\is_array($span)) {
                continue;
            }

            $name = (string)($span['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $parent = \is_scalar($span['parent'] ?? null) ? (string)$span['parent'] : '';
            $category = (string)($span['category'] ?? 'framework');
            $durationMs = \is_numeric($span['duration_ms'] ?? null) ? (float)$span['duration_ms'] : 0.0;
            $meta = \is_array($span['meta'] ?? null) ? $span['meta'] : [];

            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            if ($category === 'db') {
                $dbDurationMs += $durationMs;
            }

            $rows[] = \implode('|', [
                $seq++,
                $this->dictId($parent, $nameIds, $names),
                $this->dictId($category, $categoryIds, $categories),
                $this->dictId($name, $nameIds, $names),
                (int)\round($durationMs * 1000),
                $this->metaId($meta, $metaIds, $metas),
            ]);
        }

        if ($rows === []) {
            return null;
        }

        $request = \is_array($record['request'] ?? null) ? $record['request'] : [];
        $runtime = \is_array($record['runtime'] ?? null) ? $record['runtime'] : [];
        $timing = \is_array($record['timing'] ?? null) ? $record['timing'] : [];
        $fpc = \is_array($record['fpc'] ?? null) ? $record['fpc'] : [];
        $categoryTotals = \is_array($record['trace']['category_totals'] ?? null)
            ? $this->roundAssoc($record['trace']['category_totals'])
            : $this->categoryTotalsFromSpans($spans);
        if (!isset($categoryTotals['db']) && $dbDurationMs > 0.0) {
            $categoryTotals['db'] = \round($dbDurationMs, 2);
        }
        $totalMs = $this->firstNumeric([
            $record['total_ms'] ?? null,
            $timing['total_ms'] ?? null,
            $record['summary']['total_ms'] ?? null,
            $record['summary']['total_duration_ms'] ?? null,
        ]);
        $requestSummary = [
            'method' => (string)($request['method'] ?? $timing['method'] ?? ''),
            'uri' => (string)($request['uri'] ?? $timing['uri'] ?? ''),
            'host' => (string)($request['host'] ?? $timing['host'] ?? ''),
            'status' => (int)($request['status'] ?? $timing['status'] ?? 0),
        ];
        $runtimeSummary = [
            'mode' => (string)($runtime['mode'] ?? ''),
            'instance' => (string)($runtime['instance'] ?? $timing['instance'] ?? ''),
            'worker_id' => (string)($runtime['worker_id'] ?? $timing['worker_id'] ?? ''),
            'worker_port' => (string)($runtime['worker_port'] ?? $timing['worker_port'] ?? ''),
            'pid' => (int)($runtime['pid'] ?? $timing['pid'] ?? 0),
            'request_count' => (int)($runtime['request_count'] ?? $timing['request_count'] ?? 0),
        ];
        $fpcSummary = [
            'hit' => (bool)($fpc['hit'] ?? $timing['fpc_hit'] ?? false),
            'source' => (string)($fpc['source'] ?? $timing['fpc_source'] ?? ''),
        ];

        return [
            'request_id' => (string)($record['request_id'] ?? ''),
            'format' => 'compact-v1',
            'trace' => \implode("\n", $rows),
            'dict' => [
                'names' => $names,
                'categories' => $categories,
                'metas' => $metas,
            ],
            'summary' => [
                'span_count' => \count($rows),
                'total_ms' => \round($totalMs, 2),
                'db_duration_ms' => \round($dbDurationMs, 2),
                'category_counts' => $categoryCounts,
                'category_totals' => $categoryTotals,
                'request_id' => (string)($record['request_id'] ?? ''),
                'request' => $requestSummary,
                'method' => $requestSummary['method'],
                'uri' => $requestSummary['uri'],
                'host' => $requestSummary['host'],
                'status' => $requestSummary['status'],
                'runtime' => $runtimeSummary,
                'fpc' => $fpcSummary,
                'fpc_hit' => $fpcSummary['hit'],
                'fpc_source' => $fpcSummary['source'],
                'truncated' => false,
                'max_spans' => \count($rows),
                'source' => 'wls_performance_panel',
            ],
        ];
    }

    /**
     * @param array<string, int> $lookup
     * @param array<int, string> $dict
     */
    private function dictId(string $value, array &$lookup, array &$dict): int
    {
        if ($value === '') {
            return 0;
        }
        if (isset($lookup[$value])) {
            return $lookup[$value];
        }
        $id = \count($dict) + 1;
        $lookup[$value] = $id;
        $dict[$id] = $value;

        return $id;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, int> $lookup
     * @param array<int, array<string, mixed>> $dict
     */
    private function metaId(array $meta, array &$lookup, array &$dict): int
    {
        if ($meta === []) {
            return 0;
        }
        $json = \json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!\is_string($json) || $json === '') {
            return 0;
        }
        if (isset($lookup[$json])) {
            return $lookup[$json];
        }
        $id = \count($dict) + 1;
        $lookup[$json] = $id;
        $dict[$id] = $meta;

        return $id;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNumeric(array $values): float
    {
        foreach ($values as $value) {
            if (\is_numeric($value)) {
                return (float)$value;
            }
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, float>
     */
    private function roundAssoc(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (!\is_scalar($key) || !\is_numeric($value)) {
                continue;
            }
            $out[(string)$key] = \round((float)$value, 2);
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $spans
     * @return array<string, float>
     */
    private function categoryTotalsFromSpans(array $spans): array
    {
        $totals = [];
        foreach ($spans as $span) {
            if (!\is_array($span)) {
                continue;
            }
            $category = (string)($span['category'] ?? 'framework');
            $durationMs = \is_numeric($span['duration_ms'] ?? null) ? (float)$span['duration_ms'] : 0.0;
            $totals[$category] = ($totals[$category] ?? 0.0) + $durationMs;
        }

        return $this->roundAssoc($totals);
    }

    private function traceTtl(): int
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class)->ttl('dev.trace_ttl', self::TRACE_TTL_SECONDS);
    }

    private function wlsTraceWindow(): int
    {
        return \max($this->traceTtl(), 300);
    }

    private function isAllowed(): bool
    {
        return (new PanelAccessService())->canAccessApi($this->request);
    }
}
