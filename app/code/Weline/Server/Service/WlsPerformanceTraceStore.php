<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;

class WlsPerformanceTraceStore
{
    private const NAMESPACE = 'wls_performance_panel';
    private const KEY_RECENT = 'recent';
    private const DEFAULT_TTL = 300;
    private const DEFAULT_MAX_RECENT = 200;
    private const DEFAULT_MAX_SPANS = 600;
    private const DEFAULT_MAX_META_BYTES = 1024;

    private ?MemoryStateFacade $memory = null;
    private bool $memoryResolved = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * @param array<string, mixed> $telemetry
     * @param array<string, mixed> $timing
     */
    public function record(array $telemetry = [], array $timing = []): bool
    {
        $record = $this->buildRecord($telemetry, $timing);
        $requestId = (string)($record['request_id'] ?? '');
        if ($requestId === '') {
            return false;
        }

        $existing = $this->getDetail($requestId);
        if (\is_array($existing) && $existing !== []) {
            $record = $this->mergeRecords($existing, $record);
        }

        $ttl = $this->ttl();
        $stored = $this->setPayload('request:' . $requestId, $record, $ttl);
        $recent = $this->recent($this->maxRecent() * 2, 0, false);
        $recent = $this->upsertRecent($recent, $this->summaryRow($record));
        $recent = \array_slice($recent, 0, $this->maxRecent());
        $this->setPayload(self::KEY_RECENT, $recent, $ttl);

        return $stored;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $windowSeconds = 300, string $instance = '', string $host = ''): array
    {
        $rows = $this->filterRows($this->recent($this->maxRecent(), $windowSeconds, false), $instance, $host, false);
        $count = \count($rows);
        $totalValues = [];
        $errors = 0;
        $fpcHits = 0;
        $slowest = null;
        $categoryTotals = [];

        foreach ($rows as $row) {
            $total = (float)($row['total_ms'] ?? 0.0);
            $totalValues[] = $total;
            if ((int)($row['status'] ?? 0) >= 500) {
                $errors++;
            }
            if (!empty($row['fpc_hit'])) {
                $fpcHits++;
            }
            if ($slowest === null || $total > (float)($slowest['total_ms'] ?? 0.0)) {
                $slowest = $row;
            }
            foreach (($row['category_totals'] ?? []) as $category => $value) {
                if (!\is_scalar($category) || !\is_numeric($value)) {
                    continue;
                }
                $categoryTotals[(string)$category] = ($categoryTotals[(string)$category] ?? 0.0) + (float)$value;
            }
        }

        \sort($totalValues);

        return [
            'success' => true,
            'window_sec' => $windowSeconds,
            'request_count' => $count,
            'error_count' => $errors,
            'fpc_hit_count' => $fpcHits,
            'avg_ms' => $count > 0 ? \round(\array_sum($totalValues) / $count, 2) : 0.0,
            'p50_ms' => $this->percentile($totalValues, 50),
            'p95_ms' => $this->percentile($totalValues, 95),
            'p99_ms' => $this->percentile($totalValues, 99),
            'slowest' => $slowest ?? [],
            'category_totals' => $this->roundAssoc($categoryTotals),
            'generated_at' => \time(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requests(int $limit = 50, int $since = 0, bool $slowOnly = false, string $instance = '', string $host = ''): array
    {
        $limit = \max(1, \min($limit, $this->maxRecent()));
        $rows = $this->recent($this->maxRecent(), 0, false);
        $rows = $this->filterRows($rows, $instance, $host, $slowOnly);
        if ($since > 0) {
            $rows = \array_values(\array_filter($rows, static fn(array $row): bool => (int)($row['ts'] ?? 0) >= $since));
        }

        return \array_slice($rows, 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetail(string $requestId): array
    {
        if (!\preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $requestId)) {
            return [];
        }
        $payload = $this->getPayload('request:' . $requestId);

        return \is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function services(string $instance = ''): array
    {
        $rows = $this->filterRows($this->recent($this->maxRecent(), 600, false), $instance, '', false);
        $services = [
            'session' => $this->emptyServiceSnapshot('session'),
            'memory' => $this->emptyServiceSnapshot('memory'),
        ];

        foreach ($rows as $row) {
            foreach (($row['service_spans'] ?? []) as $span) {
                if (!\is_array($span)) {
                    continue;
                }
                $name = (string)($span['name'] ?? '');
                $service = \str_contains($name, 'session') ? 'session' : (\str_contains($name, 'memory') ? 'memory' : '');
                if ($service === '') {
                    continue;
                }
                $duration = (float)($span['duration_ms'] ?? 0.0);
                $services[$service]['sample_count']++;
                $services[$service]['last_ms'] = \round($duration, 2);
                $services[$service]['max_ms'] = \max((float)$services[$service]['max_ms'], $duration);
                $services[$service]['last_span'] = $name;
                $services[$service]['last_request_id'] = (string)($row['request_id'] ?? '');
                $services[$service]['last_seen_at'] = (int)($row['ts'] ?? 0);
                if (!empty($span['meta']) && \is_array($span['meta'])) {
                    $services[$service]['last_meta'] = $span['meta'];
                }
            }
        }

        foreach ($services as &$service) {
            $service['max_ms'] = \round((float)$service['max_ms'], 2);
        }
        unset($service);

        return [
            'success' => true,
            'instance' => $instance,
            'services' => $services,
            'generated_at' => \time(),
        ];
    }

    public function clear(): array
    {
        $recent = $this->recent($this->maxRecent(), 0, false);
        foreach ($recent as $row) {
            $requestId = (string)($row['request_id'] ?? '');
            if ($requestId !== '') {
                $this->deletePayload('request:' . $requestId);
            }
        }
        $this->deletePayload(self::KEY_RECENT);
        $this->clearFileStore();

        return ['success' => true, 'message' => (string)__('WLS performance panel traces were cleared')];
    }

    /**
     * @param array<string, mixed> $telemetry
     * @param array<string, mixed> $timing
     * @return array<string, mixed>
     */
    private function buildRecord(array $telemetry, array $timing): array
    {
        $request = \is_array($telemetry['request'] ?? null) ? $telemetry['request'] : [];
        $runtime = \is_array($telemetry['runtime'] ?? null) ? $telemetry['runtime'] : [];
        $summary = \is_array($telemetry['summary'] ?? null) ? $telemetry['summary'] : [];
        $trace = \is_array($telemetry['trace'] ?? null) ? $telemetry['trace'] : [];
        $spans = \is_array($trace['spans'] ?? null) ? $trace['spans'] : [];

        $requestId = (string)($timing['request_id'] ?? $request['request_id'] ?? $summary['request_id'] ?? '');
        if ($requestId === '' && \class_exists(\Weline\Framework\Runtime\RequestLifecycleTrace::class, false)) {
            try {
                $requestId = \Weline\Framework\Runtime\RequestLifecycleTrace::ensureRequestId();
            } catch (\Throwable) {
                $requestId = '';
            }
        }

        $now = \time();
        $method = (string)($timing['method'] ?? $request['method'] ?? '');
        $uri = (string)($timing['uri'] ?? $request['uri'] ?? '');
        $host = \strtolower((string)($timing['host'] ?? $request['host'] ?? $request['hostname'] ?? ''));
        $status = (int)($timing['status'] ?? $request['status'] ?? 0);
        $totalMs = (float)($timing['total_ms'] ?? $summary['total_ms'] ?? $summary['total_duration_ms'] ?? 0.0);
        $spans = $this->sanitizeTraceSpans($this->appendTimingSpans($this->normalizeSpans($spans), $timing));

        return [
            'request_id' => $requestId,
            'captured_at' => $now,
            'expires_at' => $now + $this->ttl(),
            'request' => [
                'method' => $method,
                'uri' => $uri,
                'host' => $host,
                'status' => $status,
                'ip' => (string)($timing['ip'] ?? $request['ip'] ?? ''),
            ],
            'runtime' => [
                'mode' => (string)($runtime['mode'] ?? ''),
                'instance' => (string)($timing['instance'] ?? $runtime['instance'] ?? $this->wEnv('wls.instance', '')),
                'worker_id' => (string)($timing['worker_id'] ?? $runtime['worker_id'] ?? $this->wEnv('wls.worker_id', '')),
                'worker_port' => (string)($timing['worker_port'] ?? $runtime['worker_port'] ?? $this->wEnv('wls.worker_port', '')),
                'pid' => (int)($timing['pid'] ?? $runtime['pid'] ?? (\function_exists('getmypid') ? (int)\getmypid() : 0)),
                'request_count' => (int)($timing['request_count'] ?? 0),
            ],
            'timing' => $this->normalizeTiming($timing),
            'summary' => $summary,
            'trace' => [
                'spans' => $spans,
                'category_totals' => $this->categoryTotals($spans, $timing),
            ],
            'service_spans' => $this->serviceSpans($spans),
            'fpc' => [
                'hit' => (bool)($timing['fpc_hit'] ?? false),
                'source' => (string)($timing['fpc_source'] ?? ''),
            ],
            'total_ms' => \round($totalMs, 2),
        ];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function mergeRecords(array $left, array $right): array
    {
        $merged = \array_replace_recursive($left, $right);
        $leftSpans = \is_array($left['trace']['spans'] ?? null) ? $left['trace']['spans'] : [];
        $rightSpans = \is_array($right['trace']['spans'] ?? null) ? $right['trace']['spans'] : [];
        if ($rightSpans === [] && $leftSpans !== []) {
                $merged['trace']['spans'] = $this->sanitizeTraceSpans($leftSpans);
                $merged['service_spans'] = $this->serviceSpans($merged['trace']['spans']);
        }
        if ((float)($right['total_ms'] ?? 0.0) <= 0.0 && (float)($left['total_ms'] ?? 0.0) > 0.0) {
            $merged['total_ms'] = $left['total_ms'];
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function summaryRow(array $record): array
    {
        $request = \is_array($record['request'] ?? null) ? $record['request'] : [];
        $runtime = \is_array($record['runtime'] ?? null) ? $record['runtime'] : [];
        $timing = \is_array($record['timing'] ?? null) ? $record['timing'] : [];

        return [
            'request_id' => (string)($record['request_id'] ?? ''),
            'ts' => (int)($record['captured_at'] ?? \time()),
            'method' => (string)($request['method'] ?? ''),
            'uri' => (string)($request['uri'] ?? ''),
            'host' => (string)($request['host'] ?? ''),
            'status' => (int)($request['status'] ?? 0),
            'total_ms' => (float)($record['total_ms'] ?? $timing['total_ms'] ?? 0.0),
            'session_ms' => (float)($timing['session_start_ms'] ?? 0.0),
            'router_ms' => (float)($timing['router_start_ms'] ?? $timing['router_start_call_ms'] ?? 0.0),
            'db_ms' => (float)($record['trace']['category_totals']['db'] ?? 0.0),
            'template_ms' => (float)($record['trace']['category_totals']['view'] ?? 0.0),
            'fpc_hit' => (bool)($record['fpc']['hit'] ?? false),
            'fpc_source' => (string)($record['fpc']['source'] ?? ''),
            'instance' => (string)($runtime['instance'] ?? ''),
            'worker_id' => (string)($runtime['worker_id'] ?? ''),
            'worker_port' => (string)($runtime['worker_port'] ?? ''),
            'pid' => (int)($runtime['pid'] ?? 0),
            'category_totals' => \is_array($record['trace']['category_totals'] ?? null) ? $record['trace']['category_totals'] : [],
            'service_spans' => \array_slice((array)($record['service_spans'] ?? []), 0, 8),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function upsertRecent(array $rows, array $row): array
    {
        $requestId = (string)($row['request_id'] ?? '');
        $out = [];
        foreach ($rows as $existing) {
            if ((string)($existing['request_id'] ?? '') === $requestId) {
                continue;
            }
            $out[] = $existing;
        }
        \array_unshift($out, $row);
        \usort($out, static fn(array $a, array $b): int => (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0));

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recent(int $limit, int $withinSeconds, bool $gc): array
    {
        $payload = $this->getPayload(self::KEY_RECENT);
        if (!\is_array($payload)) {
            $payload = $this->readRecentFromFiles();
        }
        $now = \time();
        $cutoff = $withinSeconds > 0 ? $now - $withinSeconds : 0;
        $rows = [];
        foreach ($payload as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ($cutoff > 0 && (int)($row['ts'] ?? 0) < $cutoff) {
                continue;
            }
            $rows[] = $row;
        }
        if ($gc) {
            $this->setPayload(self::KEY_RECENT, \array_slice($rows, 0, $this->maxRecent()), $this->ttl());
        }

        return \array_slice($rows, 0, \max(1, $limit));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readRecentFromFiles(): array
    {
        $rows = [];
        foreach ((array)\glob($this->baseDir() . 'request' . \DIRECTORY_SEPARATOR . '*' . \DIRECTORY_SEPARATOR . '*.json') as $file) {
            $payload = $this->readPayloadFile($file);
            if (!\is_array($payload)) {
                continue;
            }
            $rows[] = $this->summaryRow($payload);
        }
        \usort($rows, static fn(array $a, array $b): int => (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0));

        return \array_slice($rows, 0, $this->maxRecent());
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function filterRows(array $rows, string $instance, string $host, bool $slowOnly): array
    {
        $instance = \trim($instance);
        $host = \strtolower(\trim($host));
        $slowThreshold = (float)($this->config['slow_request_threshold_ms'] ?? $this->envValue('wls.performance.slow_request_threshold_ms', 500));

        return \array_values(\array_filter($rows, static function (array $row) use ($instance, $host, $slowOnly, $slowThreshold): bool {
            if ($instance !== '' && (string)($row['instance'] ?? '') !== $instance) {
                return false;
            }
            if ($host !== '' && \strtolower((string)($row['host'] ?? '')) !== $host) {
                return false;
            }
            if ($slowOnly && (float)($row['total_ms'] ?? 0.0) < $slowThreshold) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param array<int, mixed> $spans
     * @return list<array<string, mixed>>
     */
    private function normalizeSpans(array $spans): array
    {
        $limit = (int)($this->config['max_spans'] ?? $this->envValue('dev_tool.panel.wls_performance.max_spans', self::DEFAULT_MAX_SPANS));
        $limit = \max(1, \min($limit, 5000));
        $spans = \array_slice($spans, -$limit);
        $out = [];
        foreach ($spans as $span) {
            if (!\is_array($span)) {
                continue;
            }
            $row = [
                'name' => (string)($span['name'] ?? ''),
                'duration_ms' => \round((float)($span['duration_ms'] ?? 0.0), 2),
                'category' => (string)($span['category'] ?? 'framework'),
            ];
            if (isset($span['parent']) && \is_scalar($span['parent'])) {
                $row['parent'] = (string)$span['parent'];
            }
            if (isset($span['db_duration_ms']) && \is_numeric($span['db_duration_ms'])) {
                $row['db_duration_ms'] = \round((float)$span['db_duration_ms'], 2);
            }
            if (isset($span['meta']) && \is_array($span['meta'])) {
                $row['meta'] = $this->sanitizeMeta($span['meta'], $row['category']);
            }
            if ($row['name'] !== '') {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $spans
     * @param array<string, mixed> $timing
     * @return list<array<string, mixed>>
     */
    private function appendTimingSpans(array $spans, array $timing): array
    {
        $map = [
            'run_before_ms' => ['wls.worker.run_before', 'wls', 'run_before'],
            'url_parser_call_ms' => ['wls.worker.url_parse', 'wls', 'url_parse'],
            'process_url_parse_ms' => ['wls.worker.process_url_parse', 'wls', 'process_url_parse'],
            'router_init_ms' => ['wls.worker.router_init', 'wls', 'router_init'],
            'router_start_call_ms' => ['wls.worker.router_start', 'wls', 'router_start'],
            'session_start_ms' => ['wls.session.start', 'wls', 'session_start'],
            'run_after_ms' => ['wls.worker.run_after', 'wls', 'run_after'],
            'telemetry_ms' => ['wls.worker.telemetry', 'wls', 'telemetry'],
            'dev_tool_ms' => ['wls.worker.dev_tool', 'wls', 'dev_tool'],
            'reset_ms' => ['wls.worker.reset', 'wls', 'reset'],
        ];

        foreach ($map as $key => [$name, $category, $operation]) {
            if (!isset($timing[$key]) || !\is_numeric($timing[$key])) {
                continue;
            }
            $duration = (float)$timing[$key];
            if ($duration <= 0.0) {
                continue;
            }
            $spans[] = [
                'name' => $name,
                'duration_ms' => \round($duration, 2),
                'category' => $category,
                'meta' => $this->sanitizeMeta(['operation' => $operation], $category),
            ];
        }

        if (isset($timing['total_ms']) && \is_numeric($timing['total_ms']) && (float)$timing['total_ms'] > 0.0) {
            $spans[] = [
                'name' => 'wls.worker.handle',
                'duration_ms' => \round((float)$timing['total_ms'], 2),
                'category' => 'wls',
                'meta' => $this->sanitizeMeta(['operation' => 'handle'], 'wls'),
            ];
        }

        if (\array_key_exists('fpc_hit', $timing)) {
            $hit = (bool)$timing['fpc_hit'];
            $spans[] = [
                'name' => $hit ? 'wls.fpc.hit' : 'wls.fpc.miss',
                'duration_ms' => 0.01,
                'category' => 'wls',
                'meta' => $this->sanitizeMeta([
                    'operation' => 'fpc',
                    'hit' => $hit,
                    'source' => (string)($timing['fpc_source'] ?? ''),
                ], 'wls'),
            ];
        }

        return $spans;
    }

    /**
     * @param list<array<string, mixed>> $spans
     * @return list<array<string, mixed>>
     */
    private function sanitizeTraceSpans(array $spans): array
    {
        $out = [];
        foreach ($spans as $span) {
            if (!\is_array($span)) {
                continue;
            }
            $category = (string)($span['category'] ?? 'framework');
            if (isset($span['meta']) && \is_array($span['meta'])) {
                $span['meta'] = $this->sanitizeMeta($span['meta'], $category);
            }
            $out[] = $span;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function sanitizeMeta(array $meta, string $category = ''): array
    {
        $allowed = [
            'operation' => true,
            'ns' => true,
            'namespace' => true,
            'pool' => true,
            'host' => true,
            'port' => true,
            'service' => true,
            'service_type' => true,
            'worker_id' => true,
            'worker_port' => true,
            'pid' => true,
            'hit' => true,
            'source' => true,
            'status' => true,
            'table' => true,
            'query_type' => true,
            'rows' => true,
            'bytes' => true,
            'idle' => true,
            'busy' => true,
            'total' => true,
            'direct_connect' => true,
        ];
        if ($category === 'wls') {
            $allowed = [
                'operation' => true,
                'ns' => true,
                'namespace' => true,
                'pool' => true,
                'host' => true,
                'port' => true,
                'service' => true,
                'service_type' => true,
                'worker_id' => true,
                'worker_port' => true,
                'pid' => true,
                'hit' => true,
                'source' => true,
                'status' => true,
                'idle' => true,
                'busy' => true,
                'total' => true,
                'direct_connect' => true,
            ];
        }
        $max = (int)($this->config['max_meta_bytes'] ?? $this->envValue('dev_tool.panel.wls_performance.max_meta_bytes', self::DEFAULT_MAX_META_BYTES));
        $out = [];
        foreach ($meta as $key => $value) {
            $key = (string)$key;
            if (!isset($allowed[$key])) {
                continue;
            }
            if (\is_bool($value) || \is_int($value) || \is_float($value) || $value === null) {
                $out[$key] = $value;
                continue;
            }
            if (\is_scalar($value)) {
                $text = (string)$value;
                $out[$key] = $max > 0 && \strlen($text) > $max ? \substr($text, 0, $max) . '...(truncated)' : $text;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $timing
     * @return array<string, mixed>
     */
    private function normalizeTiming(array $timing): array
    {
        $allowed = [
            'total_ms',
            'pre_telemetry_total_ms',
            'run_before_ms',
            'url_parser_call_ms',
            'process_url_parse_ms',
            'url_parser_ms',
            'router_init_ms',
            'session_start_ms',
            'router_start_call_ms',
            'router_start_ms',
            'run_after_ms',
            'telemetry_ms',
            'dev_tool_ms',
            'reset_ms',
            'fpc_hit',
            'fpc_source',
            'fpc_process_items',
            'fpc_process_bytes',
        ];
        $out = [];
        foreach ($allowed as $key) {
            if (!\array_key_exists($key, $timing)) {
                continue;
            }
            $value = $timing[$key];
            $out[$key] = \is_float($value) || \is_int($value) ? \round((float)$value, 2) : $value;
        }

        foreach (['trace_top', 'trace_db_top'] as $key) {
            if (isset($timing[$key]) && \is_array($timing[$key])) {
                $out[$key] = $this->sanitizeTraceSpans($this->normalizeSpans($timing[$key]));
            }
        }

        foreach (['trace_category_totals', 'template_profile', 'router_profile'] as $key) {
            if (isset($timing[$key]) && \is_array($timing[$key])) {
                $out[$key] = $timing[$key];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $spans
     * @param array<string, mixed> $timing
     * @return array<string, float>
     */
    private function categoryTotals(array $spans, array $timing): array
    {
        if (isset($timing['trace_category_totals']) && \is_array($timing['trace_category_totals'])) {
            return $this->roundAssoc($timing['trace_category_totals']);
        }

        $totals = [];
        foreach ($spans as $span) {
            $category = (string)($span['category'] ?? 'framework');
            $totals[$category] = ($totals[$category] ?? 0.0) + (float)($span['duration_ms'] ?? 0.0);
        }

        return $this->roundAssoc($totals);
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
     * @param list<array<string, mixed>> $spans
     * @return list<array<string, mixed>>
     */
    private function serviceSpans(array $spans): array
    {
        $out = [];
        foreach ($spans as $span) {
            $name = (string)($span['name'] ?? '');
            if (!\str_starts_with($name, 'wls.session') && !\str_starts_with($name, 'wls.memory')) {
                continue;
            }
            $out[] = $span;
        }

        return \array_slice($out, 0, 30);
    }

    /**
     * @param list<float> $values
     */
    private function percentile(array $values, int $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }
        $index = (int)\ceil(($percentile / 100) * \count($values)) - 1;
        $index = \max(0, \min($index, \count($values) - 1));

        return \round((float)$values[$index], 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyServiceSnapshot(string $service): array
    {
        return [
            'service' => $service,
            'sample_count' => 0,
            'last_ms' => 0.0,
            'max_ms' => 0.0,
            'last_span' => '',
            'last_request_id' => '',
            'last_seen_at' => 0,
            'last_meta' => [],
        ];
    }

    private function setPayload(string $key, mixed $value, int $ttl): bool
    {
        $memory = $this->memory();
        if ($memory !== null) {
            try {
                if ($memory->set(self::NAMESPACE, $key, $value, $ttl)) {
                    return true;
                }
            } catch (\Throwable) {
                $this->memory = null;
            }
        }

        return $this->writePayloadFile($key, $value, $ttl);
    }

    private function getPayload(string $key): mixed
    {
        $memory = $this->memory();
        if ($memory !== null) {
            try {
                $value = $memory->get(self::NAMESPACE, $key);
                if ($value !== null) {
                    return $value;
                }
            } catch (\Throwable) {
                $this->memory = null;
            }
        }

        return $this->readPayloadFile($this->filePath($key));
    }

    private function deletePayload(string $key): void
    {
        $memory = $this->memory();
        if ($memory !== null) {
            try {
                $memory->delete(self::NAMESPACE, $key);
            } catch (\Throwable) {
                $this->memory = null;
            }
        }
        $path = $this->filePath($key);
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    private function memory(): ?MemoryStateFacade
    {
        if ($this->memoryResolved) {
            return $this->memory;
        }
        $this->memoryResolved = true;

        if (($this->config['force_file'] ?? false) === true) {
            return null;
        }
        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent()) {
            return null;
        }

        try {
            $policy = ObjectManager::getInstance(RuntimeCachePolicy::class);
            $this->memory = new MemoryStateFacade($policy->memoryOptions([
                'consumer_code' => self::NAMESPACE,
                'prefer_direct_connect' => true,
                'fail_fast_on_unhealthy' => true,
                'pool_size' => 1,
                'auto_start' => false,
            ]));
        } catch (\Throwable) {
            $this->memory = null;
        }

        return $this->memory;
    }

    private function writePayloadFile(string $key, mixed $value, int $ttl): bool
    {
        $path = $this->filePath($key);
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }
        $payload = [
            'expires_at' => \time() + $ttl,
            'value' => $value,
        ];
        $json = \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!\is_string($json)) {
            return false;
        }
        $tmp = $path . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        if (@\file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            return false;
        }
        $this->gcFiles();

        return true;
    }

    private function readPayloadFile(string $path): mixed
    {
        if (!\is_file($path)) {
            return null;
        }
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        $payload = \json_decode($raw, true);
        if (!\is_array($payload)) {
            return null;
        }
        $expiresAt = (int)($payload['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < \time()) {
            @\unlink($path);
            return null;
        }

        return $payload['value'] ?? null;
    }

    private function filePath(string $key): string
    {
        $type = \str_starts_with($key, 'request:') ? 'request' : 'index';
        $hash = \sha1($key);

        return $this->baseDir() . $type . \DIRECTORY_SEPARATOR . \substr($hash, 0, 2) . \DIRECTORY_SEPARATOR . $hash . '.json';
    }

    private function baseDir(): string
    {
        $configured = \is_scalar($this->config['base_dir'] ?? null) ? (string)$this->config['base_dir'] : '';
        if ($configured !== '') {
            return \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $configured), \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
        }

        return Env::VAR_DIR . 'wls' . \DIRECTORY_SEPARATOR . 'panel-trace' . \DIRECTORY_SEPARATOR;
    }

    private function clearFileStore(): void
    {
        foreach ((array)\glob($this->baseDir() . '*' . \DIRECTORY_SEPARATOR . '*' . \DIRECTORY_SEPARATOR . '*.json') as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
    }

    private function gcFiles(): void
    {
        if (\random_int(1, 100) !== 1) {
            return;
        }
        $now = \time();
        $checked = 0;
        foreach ((array)\glob($this->baseDir() . '*' . \DIRECTORY_SEPARATOR . '*' . \DIRECTORY_SEPARATOR . '*.json') as $file) {
            if (++$checked > 200) {
                break;
            }
            if (!\is_file($file)) {
                continue;
            }
            $payload = \json_decode((string)@\file_get_contents($file), true);
            $expiresAt = \is_array($payload) ? (int)($payload['expires_at'] ?? 0) : 0;
            if ($expiresAt > 0 && $expiresAt < $now) {
                @\unlink($file);
            }
        }
    }

    private function ttl(): int
    {
        return \max(1, \min((int)($this->config['ttl'] ?? $this->envValue('dev_tool.panel.wls_performance.ttl', self::DEFAULT_TTL)), 3600));
    }

    private function maxRecent(): int
    {
        return \max(1, \min((int)($this->config['max_recent'] ?? $this->envValue('dev_tool.panel.wls_performance.max_recent', self::DEFAULT_MAX_RECENT)), 1000));
    }

    private function envValue(string $key, mixed $default): mixed
    {
        if (!\defined('BP')) {
            return $default;
        }

        try {
            return Env::get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function wEnv(string $key, mixed $default): mixed
    {
        if (!\function_exists('w_env')) {
            return $default;
        }

        return \w_env($key, $default);
    }
}
