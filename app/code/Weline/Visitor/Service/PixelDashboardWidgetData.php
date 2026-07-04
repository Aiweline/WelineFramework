<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Http\Request;

class PixelDashboardWidgetData
{
    public function __construct(private readonly Request $request)
    {
    }

    public function getDashboard(array $config = []): array
    {
        $range = $this->normalizeRange((string)($config['range'] ?? '7d'));
        $filters = ['range' => $range];
        $websiteId = $this->resolveWebsiteId($config);
        if ($websiteId !== null && $websiteId > 0) {
            $filters['websiteId'] = (string)$websiteId;
        }

        try {
            return PixelStatisticsService::getEventListeningDashboard($filters);
        } catch (\Throwable $e) {
            return $this->emptyDashboard($filters, $e);
        }
    }

    public function rangeLabel(string $range): string
    {
        return match ($this->normalizeRange($range)) {
            'today' => (string)__('今日'),
            'yesterday' => (string)__('昨日'),
            '30d' => (string)__('近 30 天'),
            '90d' => (string)__('近 90 天'),
            default => (string)__('近 7 天'),
        };
    }

    public function formatNumber(int|float|string|null $value, int $decimals = 0): string
    {
        return number_format((float)($value ?? 0), $decimals);
    }

    public function formatPercent(int|float|string|null $value): string
    {
        return rtrim(rtrim(number_format((float)($value ?? 0), 2), '0'), '.') . '%';
    }

    private function normalizeRange(string $range): string
    {
        $range = trim($range);
        return in_array($range, ['today', 'yesterday', '7d', '30d', '90d'], true) ? $range : '7d';
    }

    private function resolveWebsiteId(array $config): ?int
    {
        foreach (['website_id', 'websiteId', 'dashboard_website_id'] as $key) {
            $value = $config[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== 'all' && is_numeric($value) && (int)$value > 0) {
                return (int)$value;
            }
        }

        foreach (['website_id', 'target_id', 'theme_layout_target_id', 'theme_layout_source_target_id'] as $key) {
            $value = $this->request->getParam($key, '');
            if ($value !== null && $value !== '' && $value !== 'all' && is_numeric($value) && (int)$value > 0) {
                return (int)$value;
            }
        }

        return null;
    }

    private function emptyDashboard(array $filters, ?\Throwable $error = null): array
    {
        return [
            'filters' => $filters,
            'summary' => [
                'total_events' => 0,
                'active_sites' => 0,
                'event_types' => 0,
                'active_users' => 0,
                'total_value' => 0,
                'avg_value' => 0,
                'un_deal_count' => 0,
                'dealed_count' => 0,
                'value_event_count' => 0,
                'event_change' => 0,
                'events_per_user' => 0,
                'value_event_rate' => 0,
                'processed_rate' => 0,
                'last_seen' => null,
            ],
            'trend' => [],
            'event_rows' => [],
            'site_rows' => [],
            'source_rows' => [],
            'realtime_rows' => [],
            'recent_events' => [],
            'widget_error' => $error ? $error->getMessage() : '',
        ];
    }
}
