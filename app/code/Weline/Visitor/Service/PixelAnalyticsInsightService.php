<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Visitor\Model\Pixel;

/**
 * GA4 风格洞察：从像素事件派生会话、停留、跳出、页面、设备、屏幕等报表。
 */
class PixelAnalyticsInsightService
{
    private const ENGAGED_EVENTS = [
        'cta_click', 'contact_click', 'lead_submit', 'hero_cta_click',
        'add_to_cart', 'begin_checkout', 'purchase', 'checkout_success',
        'search_submit', 'login', 'register', 'route_click',
    ];

    private const BOUNCE_DWELL_MS = 10000;

    public function __construct(
        private ?PixelAttributionRowResolver $attributionRowResolver = null
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function buildReport(array $filters = []): array
    {
        $normalized = PixelStatisticsService::normalizeDashboardFilters($filters);
        $rows = $this->loadPixels($normalized, 8000);
        $sessions = [];
        $pages = [];
        $devices = [];
        $screens = [];
        $sources = [];
        $browsers = [];
        $recent = [];

        foreach ($rows as $row) {
            $parsed = $this->parseRow($row);
            $sessionKey = $parsed['session_id'] !== ''
                ? $parsed['session_id']
                : ('ip:' . ($parsed['ip'] !== '' ? $parsed['ip'] : 'unknown') . ':' . \substr($parsed['created_at'], 0, 10));

            if (!isset($sessions[$sessionKey])) {
                $sessions[$sessionKey] = [
                    'session_id' => $sessionKey,
                    'events' => 0,
                    'page_views' => 0,
                    'engaged' => false,
                    'paths' => [],
                    'first_at' => $parsed['created_at'],
                    'last_at' => $parsed['created_at'],
                    'dwell_ms' => 0,
                    'ip' => $parsed['ip'],
                ];
            }

            $session = &$sessions[$sessionKey];
            $session['events']++;
            $session['last_at'] = $parsed['created_at'] ?: $session['last_at'];
            if ($session['first_at'] === '' || ($parsed['created_at'] !== '' && $parsed['created_at'] < $session['first_at'])) {
                $session['first_at'] = $parsed['created_at'];
            }
            if ($parsed['event'] === 'page_view' || $parsed['event'] === 'page_enter') {
                $session['page_views']++;
            }
            if ($parsed['path'] !== '') {
                $session['paths'][$parsed['path']] = true;
            }
            if ($parsed['dwell_ms'] > $session['dwell_ms']) {
                $session['dwell_ms'] = $parsed['dwell_ms'];
            }
            if ($parsed['engaged'] || \in_array($parsed['event'], self::ENGAGED_EVENTS, true)) {
                $session['engaged'] = true;
            }
            unset($session);

            if ($parsed['path'] !== '') {
                if (!isset($pages[$parsed['path']])) {
                    $pages[$parsed['path']] = [
                        'path' => $parsed['path'],
                        'views' => 0,
                        'events' => 0,
                        'users' => [],
                        'avg_dwell_ms' => 0,
                        'dwell_samples' => 0,
                        'dwell_total' => 0,
                    ];
                }
                $pages[$parsed['path']]['events']++;
                if ($parsed['event'] === 'page_view' || $parsed['event'] === 'page_enter') {
                    $pages[$parsed['path']]['views']++;
                }
                if ($parsed['ip'] !== '') {
                    $pages[$parsed['path']]['users'][$parsed['ip']] = true;
                }
                if ($parsed['dwell_ms'] > 0) {
                    $pages[$parsed['path']]['dwell_samples']++;
                    $pages[$parsed['path']]['dwell_total'] += $parsed['dwell_ms'];
                }
            }

            $device = $parsed['device_category'] !== '' ? $parsed['device_category'] : 'unknown';
            $devices[$device] = ($devices[$device] ?? 0) + 1;

            $screenKey = $parsed['screen_label'];
            if ($screenKey !== '') {
                $screens[$screenKey] = ($screens[$screenKey] ?? 0) + 1;
            }

            $sourceKey = $parsed['source_label'];
            $sources[$sourceKey] = ($sources[$sourceKey] ?? 0) + 1;

            $browser = $parsed['browser'];
            if ($browser !== '') {
                $browsers[$browser] = ($browsers[$browser] ?? 0) + 1;
            }

            if (\count($recent) < 40) {
                $recent[] = [
                    'pixel_id' => $parsed['pixel_id'],
                    'event' => $parsed['event'],
                    'path' => $parsed['path'],
                    'url' => $parsed['url'],
                    'ip' => $parsed['ip'],
                    'created_at' => $parsed['created_at'],
                    'session_id' => $parsed['session_id'],
                    'device' => $device,
                    'screen' => $screenKey,
                    'dwell_ms' => $parsed['dwell_ms'],
                    'source' => $sourceKey,
                    'channel_code' => $parsed['channel_code'] ?? '',
                    'utm_source' => (string)($parsed['detail']['utm']['source'] ?? ''),
                    'utm_medium' => (string)($parsed['detail']['utm']['medium'] ?? ''),
                    'utm_campaign' => (string)($parsed['detail']['utm']['campaign'] ?? ''),
                    'traffic_type' => $parsed['traffic_type'] ?? '',
                    'detail' => $parsed['detail'],
                ];
            }
        }

        $sessionCount = \count($sessions);
        $bounced = 0;
        $engagedSessions = 0;
        $durationTotal = 0;
        $durationSamples = 0;
        $pagesPerSessionTotal = 0;

        foreach ($sessions as $session) {
            $pathCount = \count($session['paths']);
            $pagesPerSessionTotal += max(1, $pathCount > 0 ? $pathCount : ($session['page_views'] > 0 ? $session['page_views'] : 1));
            $duration = $this->resolveSessionDurationMs($session);
            if ($duration > 0) {
                $durationTotal += $duration;
                $durationSamples++;
            }
            if ($session['engaged']) {
                $engagedSessions++;
            }
            $isBounce = !$session['engaged']
                && $session['page_views'] <= 1
                && $session['events'] <= 2
                && $duration < self::BOUNCE_DWELL_MS;
            if ($isBounce) {
                $bounced++;
            }
        }

        $pageRows = [];
        foreach ($pages as $page) {
            $pageRows[] = [
                'path' => $page['path'],
                'views' => $page['views'],
                'events' => $page['events'],
                'users' => \count($page['users']),
                'avg_dwell_sec' => $page['dwell_samples'] > 0
                    ? round(($page['dwell_total'] / $page['dwell_samples']) / 1000, 1)
                    : 0,
            ];
        }
        \usort($pageRows, static fn(array $a, array $b): int => $b['views'] <=> $a['views'] ?: $b['events'] <=> $a['events']);

        return [
            'filters' => $normalized,
            'engagement' => [
                'sessions' => $sessionCount,
                'engaged_sessions' => $engagedSessions,
                'engagement_rate' => $sessionCount > 0 ? round(($engagedSessions / $sessionCount) * 100, 2) : 0.0,
                'bounce_sessions' => $bounced,
                'bounce_rate' => $sessionCount > 0 ? round(($bounced / $sessionCount) * 100, 2) : 0.0,
                'avg_session_duration_sec' => $durationSamples > 0 ? round(($durationTotal / $durationSamples) / 1000, 1) : 0.0,
                'pages_per_session' => $sessionCount > 0 ? round($pagesPerSessionTotal / $sessionCount, 2) : 0.0,
                'events' => \count($rows),
                'users' => $this->countDistinct($rows, 'ip'),
            ],
            'pages' => \array_slice($pageRows, 0, 20),
            'devices' => $this->toShareRows($devices, 8),
            'screens' => $this->toShareRows($screens, 8),
            'sources' => $this->toShareRows($sources, 10),
            'browsers' => $this->toShareRows($browsers, 8),
            'recent_events' => $recent,
            'sample_size' => \count($rows),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function loadPixels(array $filters, int $limit): array
    {
        $model = w_obj(Pixel::class)->reset();
        if ($filters['website_id'] !== null) {
            $model->where(Pixel::schema_fields_WEBSITE_ID, (int)$filters['website_id']);
        }
        if ($filters['event'] !== null) {
            $model->where(Pixel::schema_fields_EVENT, (string)$filters['event']);
        }

        // created_at 可能为空，回退 create_time 过滤在 PHP 侧再收紧；先取窗口内候选
        $start = (string)$filters['start_date'];
        $end = (string)$filters['end_date'];
        $rows = $model
            ->order(Pixel::schema_fields_ID, 'DESC')
            ->limit(max(100, min(20000, $limit * 2)))
            ->select()
            ->fetchArray();

        $filtered = [];
        foreach ($rows ?: [] as $row) {
            $created = (string)($row[Pixel::schema_fields_CREATED_AT] ?? $row['create_time'] ?? '');
            if ($created === '') {
                continue;
            }
            if ($created < $start || $created > $end) {
                continue;
            }
            $filtered[] = $row;
            if (\count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function parseRow(array $row): array
    {
        $info = [];
        $raw = $row[Pixel::schema_fields_BROWSER_INFO] ?? '';
        if (\is_string($raw) && $raw !== '') {
            $decoded = \json_decode($raw, true);
            if (\is_array($decoded)) {
                $info = $decoded;
            }
        }
        $additional = \is_array($info['additionalInfo'] ?? null) ? $info['additionalInfo'] : [];
        $environment = \is_array($additional['environment'] ?? null) ? $additional['environment'] : [];
        $navigation = \is_array($additional['navigation'] ?? null) ? $additional['navigation'] : [];
        $device = \is_array($additional['device'] ?? null) ? $additional['device'] : [];
        $utm = \is_array($additional['utm'] ?? null) ? $additional['utm'] : [];
        $engagement = \is_array($additional['engagement'] ?? null) ? $additional['engagement'] : [];
        $meta = \is_array($additional['meta'] ?? null) ? $additional['meta'] : [];
        $screen = \is_array($info['screen'] ?? null) ? $info['screen'] : [];
        $funnel = \is_array($additional['funnel'] ?? null) ? $additional['funnel'] : [];

        $path = (string)($info['page_path']
            ?? $environment['page_path']
            ?? $navigation['current_path']
            ?? '');
        if ($path === '') {
            $path = (string)(\parse_url((string)($row[Pixel::schema_fields_URL] ?? ''), PHP_URL_PATH) ?: '/');
        }

        $width = (int)($screen['width'] ?? $device['screen_width'] ?? 0);
        $height = (int)($screen['height'] ?? $device['screen_height'] ?? 0);
        $screenLabel = ($width > 0 && $height > 0) ? ($width . '×' . $height) : '';

        $utmSource = \trim((string)($utm['source'] ?? ''));
        $utmMedium = \trim((string)($utm['medium'] ?? ''));
        $attribution = $this->attributionResolver()->resolve($row);
        if ($attribution['utm_source'] !== '') {
            $utmSource = $attribution['utm_source'];
            $utm['source'] = $utmSource;
        }
        if ($attribution['utm_medium'] !== '') {
            $utmMedium = $attribution['utm_medium'];
            $utm['medium'] = $utmMedium;
        }
        if ($attribution['utm_campaign'] !== '') {
            $utm['campaign'] = $attribution['utm_campaign'];
        }
        $sourceLabel = $attribution['source_label'];
        $sessionId = $attribution['session_id'] !== ''
            ? $attribution['session_id']
            : (string)($info['session_id'] ?? $environment['session_id'] ?? $funnel['session_id'] ?? '');

        $ua = (string)($row[Pixel::schema_fields_USER_AGENT] ?? '');
        $deviceCategory = (string)($info['device_category'] ?? $device['category'] ?? '');
        if ($deviceCategory === '' && $ua !== '') {
            $deviceCategory = $this->guessDeviceFromUa($ua);
        }

        return [
            'pixel_id' => (int)($row[Pixel::schema_fields_ID] ?? 0),
            'event' => (string)($row[Pixel::schema_fields_EVENT] ?? ''),
            'url' => (string)($row[Pixel::schema_fields_URL] ?? ''),
            'path' => $path,
            'ip' => (string)($row[Pixel::schema_fields_IP] ?? ''),
            'created_at' => (string)($row[Pixel::schema_fields_CREATED_AT] ?? $row['create_time'] ?? ''),
            'session_id' => $sessionId,
            'device_category' => $deviceCategory,
            'screen_label' => $screenLabel,
            'source_label' => $sourceLabel,
            'channel_code' => $attribution['channel_code'],
            'traffic_type' => $attribution['traffic_type'],
            'attribution_origin' => $attribution['origin'],
            'browser' => $this->guessBrowser($ua),
            'dwell_ms' => (int)($info['dwell_ms'] ?? $engagement['dwell_ms'] ?? $meta['duration_ms'] ?? 0),
            'engaged' => !empty($engagement['engaged']),
            'detail' => [
                'environment' => $environment,
                'device' => $device,
                'utm' => $utm,
                'viewport' => $additional['viewport'] ?? [],
                'performance' => $additional['performance'] ?? [],
                'funnel' => $funnel,
                'engagement' => $engagement,
                'screen' => $screen,
            ],
        ];
    }

    private function attributionResolver(): PixelAttributionRowResolver
    {
        if (!$this->attributionRowResolver) {
            $this->attributionRowResolver = \Weline\Framework\Manager\ObjectManager::getInstance(
                PixelAttributionRowResolver::class
            );
        }

        return $this->attributionRowResolver;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function resolveSessionDurationMs(array $session): int
    {
        if ((int)$session['dwell_ms'] > 0) {
            return (int)$session['dwell_ms'];
        }
        $first = \strtotime((string)$session['first_at']);
        $last = \strtotime((string)$session['last_at']);
        if ($first && $last && $last >= $first) {
            return (int)(($last - $first) * 1000);
        }
        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function countDistinct(array $rows, string $field): int
    {
        $set = [];
        foreach ($rows as $row) {
            $value = \trim((string)($row[$field] ?? $row[Pixel::schema_fields_IP] ?? ''));
            if ($value !== '') {
                $set[$value] = true;
            }
        }
        return \count($set);
    }

    /**
     * @param array<string, int> $counts
     * @return array<int, array{label: string, count: int, share: float}>
     */
    private function toShareRows(array $counts, int $limit): array
    {
        \arsort($counts);
        $total = \array_sum($counts) ?: 1;
        $rows = [];
        foreach (\array_slice($counts, 0, $limit, true) as $label => $count) {
            $rows[] = [
                'label' => (string)$label,
                'count' => (int)$count,
                'share' => round(((int)$count / $total) * 100, 2),
            ];
        }
        return $rows;
    }

    private function hostFromUrl(string $url): string
    {
        $host = (string)(\parse_url($url, PHP_URL_HOST) ?: '');
        return \strtolower($host);
    }

    private function guessDeviceFromUa(string $ua): string
    {
        $uaLower = \strtolower($ua);
        if (\preg_match('/mobile|iphone|android(?!.*tablet)|windows phone/', $uaLower)) {
            return 'mobile';
        }
        if (\preg_match('/ipad|tablet|kindle|silk/', $uaLower)) {
            return 'tablet';
        }
        return 'desktop';
    }

    private function guessBrowser(string $ua): string
    {
        $uaLower = \strtolower($ua);
        return match (true) {
            str_contains($uaLower, 'edg/') => 'Edge',
            str_contains($uaLower, 'chrome/') && !str_contains($uaLower, 'edg/') => 'Chrome',
            str_contains($uaLower, 'safari/') && !str_contains($uaLower, 'chrome/') => 'Safari',
            str_contains($uaLower, 'firefox/') => 'Firefox',
            default => $ua !== '' ? 'Other' : '',
        };
    }
}
