<?php

declare(strict_types=1);

namespace Weline\Visitor\Service\Report;

/**
 * D02：报表指标注册表（纯内存，不查库）。
 *
 * §2.5：
 * - 小时：events, value_sum, valued_events, session_starts, purchases, add_to_carts
 * - 日：上列 + sessions, engaged_sessions, bounce_sessions, conversions, funnel_json
 * 会话口径：日表 sessions 权威；小时仅 session_starts，禁止 SUM 小时当会话数。
 */
class PixelMetricRegistry
{
    public const GRAIN_HOURLY = 'hourly';
    public const GRAIN_DAILY = 'daily';

    public const AGG_SUM = 'sum';
    public const AGG_COUNT = 'count';
    public const AGG_DISTINCT = 'distinct';
    public const AGG_JSON = 'json';

    /**
     * §2.5 小时指标顺序。
     *
     * @var list<string>
     */
    public const HOURLY_METRIC_IDS = [
        'events',
        'value_sum',
        'valued_events',
        'session_starts',
        'purchases',
        'add_to_carts',
    ];

    /**
     * §2.5 日表相对小时新增指标。
     *
     * @var list<string>
     */
    public const DAILY_EXTRA_METRIC_IDS = [
        'sessions',
        'engaged_sessions',
        'bounce_sessions',
        'conversions',
        'funnel_json',
    ];

    /**
     * @var array<string, array{
     *   id: string,
     *   label: string,
     *   aggregation: string,
     *   value_type: string,
     *   on_hourly: bool,
     *   on_daily: bool,
     *   summable_across_hours: bool
     * }>
     */
    private array $metrics = [];

    public function __construct(bool $withDefaults = true)
    {
        if ($withDefaults) {
            $this->registerDefaults();
        }
    }

    /**
     * @param array{
     *   label?: string,
     *   aggregation?: string,
     *   value_type?: string,
     *   on_hourly?: bool,
     *   on_daily?: bool,
     *   summable_across_hours?: bool
     * } $meta
     */
    public function register(string $id, array $meta = []): void
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('metric id must not be empty');
        }

        $aggregation = (string)($meta['aggregation'] ?? self::AGG_SUM);
        if (!\in_array($aggregation, [self::AGG_SUM, self::AGG_COUNT, self::AGG_DISTINCT, self::AGG_JSON], true)) {
            throw new \InvalidArgumentException('invalid metric aggregation: ' . $aggregation);
        }

        $onHourly = (bool)($meta['on_hourly'] ?? false);
        $onDaily = (bool)($meta['on_daily'] ?? true);
        if (!$onHourly && !$onDaily) {
            throw new \InvalidArgumentException('metric must be available on hourly or daily: ' . $id);
        }

        $this->metrics[$id] = [
            'id' => $id,
            'label' => trim((string)($meta['label'] ?? $id)) ?: $id,
            'aggregation' => $aggregation,
            'value_type' => trim((string)($meta['value_type'] ?? 'int')) ?: 'int',
            'on_hourly' => $onHourly,
            'on_daily' => $onDaily,
            'summable_across_hours' => (bool)($meta['summable_across_hours'] ?? ($aggregation === self::AGG_SUM && $onHourly)),
        ];
    }

    public function has(string $id): bool
    {
        return isset($this->metrics[trim($id)]);
    }

    /**
     * @return array{
     *   id: string,
     *   label: string,
     *   aggregation: string,
     *   value_type: string,
     *   on_hourly: bool,
     *   on_daily: bool,
     *   summable_across_hours: bool
     * }|null
     */
    public function get(string $id): ?array
    {
        $id = trim($id);

        return $this->metrics[$id] ?? null;
    }

    /**
     * @return array<string, array{
     *   id: string,
     *   label: string,
     *   aggregation: string,
     *   value_type: string,
     *   on_hourly: bool,
     *   on_daily: bool,
     *   summable_across_hours: bool
     * }>
     */
    public function all(): array
    {
        return $this->metrics;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->metrics);
    }

    /**
     * @return list<string>
     */
    public function hourlyIds(): array
    {
        $ids = [];
        foreach (self::HOURLY_METRIC_IDS as $id) {
            if ($this->has($id) && ($this->metrics[$id]['on_hourly'] ?? false)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * 日表全部指标（小时指标 ∪ 日新增），顺序：先小时序，再 DAILY_EXTRA。
     *
     * @return list<string>
     */
    public function dailyIds(): array
    {
        $ids = [];
        foreach (array_merge(self::HOURLY_METRIC_IDS, self::DAILY_EXTRA_METRIC_IDS) as $id) {
            if ($this->has($id) && ($this->metrics[$id]['on_daily'] ?? false)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    public function dailyExtraIds(): array
    {
        $ids = [];
        foreach (self::DAILY_EXTRA_METRIC_IDS as $id) {
            if ($this->has($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $ids
     * @throws \InvalidArgumentException
     */
    public function assertKnown(array $ids): void
    {
        foreach ($ids as $id) {
            if (!$this->has((string)$id)) {
                throw new \InvalidArgumentException('unknown metric: ' . $id);
            }
        }
    }

    /**
     * @param list<string> $ids
     * @throws \InvalidArgumentException 含不可跨小时相加的指标时抛错
     */
    public function assertSummableAcrossHours(array $ids): void
    {
        $this->assertKnown($ids);
        foreach ($ids as $id) {
            $meta = $this->metrics[(string)$id];
            if (!$meta['summable_across_hours']) {
                throw new \InvalidArgumentException('metric is not summable across hours: ' . $id);
            }
        }
    }

    /**
     * 是否允许在指定粒度查询。
     */
    public function supportsGrain(string $id, string $grain): bool
    {
        $meta = $this->get($id);
        if ($meta === null) {
            return false;
        }
        if ($grain === self::GRAIN_HOURLY) {
            return $meta['on_hourly'];
        }
        if ($grain === self::GRAIN_DAILY) {
            return $meta['on_daily'];
        }

        return false;
    }

    private function registerDefaults(): void
    {
        $this->register('events', [
            'label' => 'Events',
            'aggregation' => self::AGG_COUNT,
            'value_type' => 'int',
            'on_hourly' => true,
            'on_daily' => true,
            'summable_across_hours' => true,
        ]);
        $this->register('value_sum', [
            'label' => 'Value sum',
            'aggregation' => self::AGG_SUM,
            'value_type' => 'float',
            'on_hourly' => true,
            'on_daily' => true,
            'summable_across_hours' => true,
        ]);
        $this->register('valued_events', [
            'label' => 'Valued events',
            'aggregation' => self::AGG_COUNT,
            'value_type' => 'int',
            'on_hourly' => true,
            'on_daily' => true,
            'summable_across_hours' => true,
        ]);
        $this->register('session_starts', [
            'label' => 'Session starts',
            'aggregation' => self::AGG_COUNT,
            'value_type' => 'int',
            'on_hourly' => true,
            'on_daily' => true,
            'summable_across_hours' => true,
        ]);
        $this->register('purchases', [
            'label' => 'Purchases',
            'aggregation' => self::AGG_COUNT,
            'value_type' => 'int',
            'on_hourly' => true,
            'on_daily' => true,
            'summable_across_hours' => true,
        ]);
        $this->register('add_to_carts', [
            'label' => 'Add to carts',
            'aggregation' => self::AGG_COUNT,
            'value_type' => 'int',
            'on_hourly' => true,
            'on_daily' => true,
            'summable_across_hours' => true,
        ]);

        // 日表权威会话：禁止 SUM 小时 session_starts 当 sessions
        $this->register('sessions', [
            'label' => 'Sessions',
            'aggregation' => self::AGG_DISTINCT,
            'value_type' => 'int',
            'on_hourly' => false,
            'on_daily' => true,
            'summable_across_hours' => false,
        ]);
        $this->register('engaged_sessions', [
            'label' => 'Engaged sessions',
            'aggregation' => self::AGG_DISTINCT,
            'value_type' => 'int',
            'on_hourly' => false,
            'on_daily' => true,
            'summable_across_hours' => false,
        ]);
        $this->register('bounce_sessions', [
            'label' => 'Bounce sessions',
            'aggregation' => self::AGG_DISTINCT,
            'value_type' => 'int',
            'on_hourly' => false,
            'on_daily' => true,
            'summable_across_hours' => false,
        ]);
        $this->register('conversions', [
            'label' => 'Conversions',
            'aggregation' => self::AGG_COUNT,
            'value_type' => 'int',
            'on_hourly' => false,
            'on_daily' => true,
            'summable_across_hours' => false,
        ]);
        $this->register('funnel_json', [
            'label' => 'Funnel JSON',
            'aggregation' => self::AGG_JSON,
            'value_type' => 'json',
            'on_hourly' => false,
            'on_daily' => true,
            'summable_across_hours' => false,
        ]);
    }
}
