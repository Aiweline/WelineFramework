<?php

declare(strict_types=1);

namespace Weline\Visitor\Service\Report;

use InvalidArgumentException;
use RuntimeException;

/**
 * D05：预设报表目录（etc/report_catalog.json）。
 *
 * 仅负责加载与校验；不执行查询、不挂 UI/部件。
 */
class PixelReportCatalog
{
    private const CATALOG_RELATIVE = 'etc/report_catalog.json';

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    public function __construct(
        private ?PixelDimensionRegistry $dimensions = null,
        private ?PixelMetricRegistry $metrics = null,
        private ?string $catalogPath = null,
    ) {
    }

    public function getVersion(): string
    {
        return (string)($this->load()['version'] ?? '0.0.0');
    }

    /**
     * @return list<array{
     *   code: string,
     *   label: string,
     *   description: string,
     *   dimension: string,
     *   metrics: list<string>,
     *   filters: array<string, string>,
     *   widget_code: string,
     *   enabled: bool
     * }>
     */
    public function all(): array
    {
        $reports = $this->load()['reports'] ?? [];
        if (!\is_array($reports)) {
            return [];
        }

        $out = [];
        foreach ($reports as $report) {
            if (!\is_array($report)) {
                continue;
            }
            $normalized = $this->normalizeReport($report);
            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @return list<array{
     *   code: string,
     *   label: string,
     *   description: string,
     *   dimension: string,
     *   metrics: list<string>,
     *   filters: array<string, string>,
     *   widget_code: string,
     *   enabled: bool
     * }>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(array $report): bool => (bool)$report['enabled']
        ));
    }

    /**
     * @return list<string>
     */
    public function codes(bool $enabledOnly = true): array
    {
        $reports = $enabledOnly ? $this->enabled() : $this->all();
        $codes = [];
        foreach ($reports as $report) {
            $codes[] = $report['code'];
        }

        return $codes;
    }

    public function has(string $code, bool $enabledOnly = true): bool
    {
        return $this->get($code, $enabledOnly) !== null;
    }

    /**
     * @return array{
     *   code: string,
     *   label: string,
     *   description: string,
     *   dimension: string,
     *   metrics: list<string>,
     *   filters: array<string, string>,
     *   widget_code: string,
     *   enabled: bool
     * }|null
     */
    public function get(string $code, bool $enabledOnly = true): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        foreach ($this->all() as $report) {
            if ($report['code'] !== $code) {
                continue;
            }
            if ($enabledOnly && !$report['enabled']) {
                return null;
            }

            return $report;
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException
     * @return array{
     *   code: string,
     *   label: string,
     *   description: string,
     *   dimension: string,
     *   metrics: list<string>,
     *   filters: array<string, string>,
     *   widget_code: string,
     *   enabled: bool
     * }
     */
    public function require(string $code, bool $enabledOnly = true): array
    {
        $report = $this->get($code, $enabledOnly);
        if ($report === null) {
            throw new InvalidArgumentException('unknown report catalog code: ' . $code);
        }

        return $report;
    }

    /**
     * 校验目录内维度/指标均已在 Registry 注册，且指标可走 D04 热事件行聚合。
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public function assertConsistent(): void
    {
        $seen = [];
        foreach ($this->all() as $report) {
            $code = $report['code'];
            if (isset($seen[$code])) {
                throw new RuntimeException('duplicate report catalog code: ' . $code);
            }
            $seen[$code] = true;

            $this->dimensions()->assertKnown([$report['dimension']]);
            $this->metrics()->assertKnown($report['metrics']);
            foreach ($report['metrics'] as $metricId) {
                if (!\in_array($metricId, PixelReportQueryService::EVENT_ROW_METRIC_IDS, true)) {
                    throw new InvalidArgumentException(
                        'report ' . $code . ' uses unsupported hot metric: ' . $metricId
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $report
     * @return array{
     *   code: string,
     *   label: string,
     *   description: string,
     *   dimension: string,
     *   metrics: list<string>,
     *   filters: array<string, string>,
     *   widget_code: string,
     *   enabled: bool
     * }
     */
    private function normalizeReport(array $report): array
    {
        $code = trim((string)($report['code'] ?? ''));
        if ($code === '') {
            throw new RuntimeException('report catalog entry missing code');
        }

        $dimension = trim((string)($report['dimension'] ?? ''));
        if ($dimension === '') {
            throw new RuntimeException('report catalog entry missing dimension: ' . $code);
        }

        $metrics = [];
        foreach (($report['metrics'] ?? []) as $metricId) {
            $metricId = trim((string)$metricId);
            if ($metricId === '' || isset($metrics[$metricId])) {
                continue;
            }
            $metrics[$metricId] = $metricId;
        }
        $metricList = array_values($metrics);
        if ($metricList === []) {
            throw new RuntimeException('report catalog entry missing metrics: ' . $code);
        }

        $filters = [];
        $rawFilters = $report['filters'] ?? [];
        if (\is_array($rawFilters)) {
            foreach ($rawFilters as $key => $value) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }
                $filters[$key] = trim((string)$value);
            }
        }

        $widgetCode = trim((string)($report['widget_code'] ?? $code)) ?: $code;

        return [
            'code' => $code,
            'label' => trim((string)($report['label'] ?? $code)) ?: $code,
            'description' => trim((string)($report['description'] ?? '')),
            'dimension' => $dimension,
            'metrics' => $metricList,
            'filters' => $filters,
            'widget_code' => $widgetCode,
            'enabled' => (bool)($report['enabled'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->payload !== null) {
            return $this->payload;
        }

        $path = $this->catalogPath
            ?? (\dirname(__DIR__, 2) . \DIRECTORY_SEPARATOR . self::CATALOG_RELATIVE);
        if (!\is_file($path)) {
            $this->payload = ['version' => '0.0.0', 'reports' => []];

            return $this->payload;
        }

        $raw = \file_get_contents($path);
        $decoded = \is_string($raw) ? \json_decode($raw, true) : null;
        if (!\is_array($decoded)) {
            throw new RuntimeException('invalid report catalog json: ' . $path);
        }

        $this->payload = $decoded;

        return $this->payload;
    }

    private function dimensions(): PixelDimensionRegistry
    {
        return $this->dimensions ??= new PixelDimensionRegistry();
    }

    private function metrics(): PixelMetricRegistry
    {
        return $this->metrics ??= new PixelMetricRegistry();
    }
}
