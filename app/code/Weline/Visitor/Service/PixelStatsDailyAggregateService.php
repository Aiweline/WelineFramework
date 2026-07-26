<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelStatsDaily;
use Weline\Visitor\Model\PixelStatsJobLog;

/**
 * G05：日聚合（热表 → pixel_stats_daily + job_log + §2.5 校验）。
 *
 * - 日桶按站点时区；job_log 记 tz；
 * - 覆盖式重跑（先清站日再写入）；
 * - 权威 sessions / engaged_sessions / bounce_sessions / conversions / funnel_json；
 * - 校验：日表 events 合计 vs 热表同日 COUNT，相对误差 ≤2% 或绝对差 ≤5，否则 job_log=failed；
 * - 不删热、不写小时表。
 */
class PixelStatsDailyAggregateService
{
    public const CHECK_REL_TOLERANCE = 0.02;
    public const CHECK_ABS_TOLERANCE = 5;

    /** @var list<string> */
    public const PURCHASE_EVENTS = PixelStatsHourlyAggregateService::PURCHASE_EVENTS;

    /** @var list<string> */
    public const ADD_TO_CART_EVENTS = PixelStatsHourlyAggregateService::ADD_TO_CART_EVENTS;

    /** @var list<string> */
    public const CONVERSION_EVENTS = PixelChannelHotTotalsService::CONVERSION_EVENTS;

    /** @var list<string> */
    public const ENGAGED_EVENTS = [
        'cta_click', 'contact_click', 'lead_submit', 'hero_cta_click',
        'add_to_cart', 'begin_checkout', 'purchase', 'checkout_success',
        'search_submit', 'login', 'register', 'route_click',
    ];

    /** @var list<string> */
    public const PAGE_VIEW_EVENTS = PixelChannelHotTotalsService::PAGE_VIEW_EVENTS;

    public const BOUNCE_DWELL_MS = 10000;

    public function __construct(
        private ?PixelStatsHourlyAggregateService $hourly = null,
        private ?PixelChannelFunnelService $funnel = null,
    ) {
    }

    /**
     * @return array{day_bucket: string, start_utc: string, end_utc: string, tz: string}
     */
    public function resolvePreviousDayBucket(?DateTimeInterface $now = null, string $tz = 'UTC'): array
    {
        $tzName = $this->hourly()->normalizeTimezone($tz);
        $zone = new DateTimeZone($tzName);
        $localNow = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable('now'))
            ->setTimezone($zone);
        $dayStart = $localNow->setTime(0, 0, 0)->modify('-1 day');

        return $this->resolveBucketWindow($dayStart->format('Y-m-d'), $tzName);
    }

    /**
     * @return array{day_bucket: string, start_utc: string, end_utc: string, tz: string}
     */
    public function resolveBucketWindow(string $dayBucket, string $tz = 'UTC'): array
    {
        $tzName = $this->hourly()->normalizeTimezone($tz);
        $zone = new DateTimeZone($tzName);
        $day = substr(trim($dayBucket), 0, 10);
        $dayStart = new DateTimeImmutable($day . ' 00:00:00', $zone);
        $dayEnd = $dayStart->modify('+1 day');

        return [
            'day_bucket' => $dayStart->format('Y-m-d'),
            'start_utc' => $dayStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end_utc' => $dayEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'tz' => $tzName,
        ];
    }

    /**
     * §2.5 校验：相对误差 ≤ 2% 或绝对差 ≤ 5。
     *
     * @return array{
     *   ok: bool,
     *   expected: int,
     *   actual: int,
     *   abs_diff: int,
     *   rel_error: float
     * }
     */
    public function validateEvents(int $expectedHotCount, int $actualDailyEvents): array
    {
        $expected = max(0, $expectedHotCount);
        $actual = max(0, $actualDailyEvents);
        $absDiff = abs($actual - $expected);
        $relError = $expected > 0 ? $absDiff / $expected : ($actual > 0 ? 1.0 : 0.0);
        $ok = $absDiff <= self::CHECK_ABS_TOLERANCE
            || $relError <= self::CHECK_REL_TOLERANCE;

        return [
            'ok' => $ok,
            'expected' => $expected,
            'actual' => $actual,
            'abs_diff' => $absDiff,
            'rel_error' => round($relError, 6),
        ];
    }

    /**
     * 纯聚合（不查库）。
     *
     * @param list<array<string, mixed>> $dayRows
     * @param list<array<string, mixed>>|null $sessionFirstRows 全局 first_at 落在本日的首事件
     * @return array{rows: list<array<string, mixed>>, funnel: array<string, mixed>, events_total: int}
     */
    public function aggregateFromRows(
        array $dayRows,
        int $websiteId,
        string $dayBucket,
        string $tz,
        ?array $sessionFirstRows = null
    ): array {
        $window = $this->resolveBucketWindow($dayBucket, $tz);
        $bucket = $window['day_bucket'];
        $tzName = $window['tz'];

        $sessionProfiles = $this->buildSessionProfiles($dayRows);
        $funnel = $this->funnel()->computeFromEvents($dayRows);
        $funnelJson = json_encode([
            'mode' => 'marketing',
            'steps' => $funnel['steps'] ?? [],
            'step1_sessions' => (int)($funnel['step1_sessions'] ?? 0),
            'scored_sessions' => (int)($funnel['scored_sessions'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($funnelJson === false) {
            $funnelJson = '{}';
        }

        $groups = [];
        $eventsTotal = 0;

        foreach ($dayRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $eventsTotal++;
            $dims = $this->hourly()->dimsFromRow($row);
            $hash = PixelStatsDaily::dimHash($dims);
            if (!isset($groups[$hash])) {
                $groups[$hash] = $this->emptyMetricRow($websiteId, $bucket, $tzName, $dims, $hash, $funnelJson);
            }
            $g = &$groups[$hash];
            $g['events']++;
            $value = (float)($row[Pixel::schema_fields_VALUE] ?? $row['value'] ?? 0);
            $g['value_sum'] += $value;
            if ($value > 0) {
                $g['valued_events']++;
            }
            $event = strtolower(trim((string)($row[Pixel::schema_fields_EVENT] ?? $row['event'] ?? '')));
            if (\in_array($event, self::PURCHASE_EVENTS, true)) {
                $g['purchases']++;
            }
            if (\in_array($event, self::ADD_TO_CART_EVENTS, true)) {
                $g['add_to_carts']++;
            }
            if (\in_array($event, self::CONVERSION_EVENTS, true)) {
                $g['conversions']++;
            }
            $sessionId = trim((string)($row[Pixel::schema_fields_SESSION_ID] ?? $row['session_id'] ?? ''));
            if ($sessionId !== '') {
                $g['_session_ids'][$sessionId] = true;
            }
            unset($g);
        }

        $startRows = $sessionFirstRows;
        if ($startRows === null) {
            $startRows = $this->approximateSessionFirstRows($dayRows);
        }
        foreach ($startRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $dims = $this->hourly()->dimsFromRow($row);
            $hash = PixelStatsDaily::dimHash($dims);
            if (!isset($groups[$hash])) {
                $groups[$hash] = $this->emptyMetricRow($websiteId, $bucket, $tzName, $dims, $hash, $funnelJson);
            }
            $groups[$hash]['session_starts']++;
        }

        foreach ($groups as &$group) {
            $sessionIds = array_keys($group['_session_ids'] ?? []);
            unset($group['_session_ids']);
            $sessions = 0;
            $engaged = 0;
            $bounce = 0;
            foreach ($sessionIds as $sessionId) {
                $profile = $sessionProfiles[$sessionId] ?? null;
                if ($profile === null) {
                    continue;
                }
                $sessions++;
                if ($profile['engaged']) {
                    $engaged++;
                }
                if ($profile['bounce']) {
                    $bounce++;
                }
            }
            $group['sessions'] = $sessions;
            $group['engaged_sessions'] = $engaged;
            $group['bounce_sessions'] = $bounce;
            $group['value_sum'] = round((float)$group['value_sum'], 4);
            $group['funnel_json'] = $funnelJson;
        }
        unset($group);

        return [
            'rows' => array_values($groups),
            'funnel' => $funnel,
            'events_total' => $eventsTotal,
        ];
    }

    /**
     * @param callable|null $dayRowLoader fn(int,string,string): list<array>
     * @param callable|null $sessionFirstLoader fn(int,string,string): list<array>
     * @param callable|null $hotCountLoader fn(int,string,string): int
     * @param callable|null $writer fn(int,string,list<array>): int
     * @param callable|null $jobLogWriter fn(array): void
     * @return array{
     *   website_id: int,
     *   day_bucket: string,
     *   tz: string,
     *   status: string,
     *   rows: int,
     *   events: int,
     *   check: array<string, mixed>,
     *   message: string
     * }
     */
    public function runForWebsite(
        int $websiteId,
        string $dayBucket,
        string $tz,
        ?callable $dayRowLoader = null,
        ?callable $sessionFirstLoader = null,
        ?callable $hotCountLoader = null,
        ?callable $writer = null,
        ?callable $jobLogWriter = null
    ): array {
        $window = $this->resolveBucketWindow($dayBucket, $tz);
        $startedAt = date('Y-m-d H:i:s');
        $jobBucket = $window['day_bucket'] . ' 00:00:00';

        $this->writeJobLog($jobLogWriter, [
            'job_type' => PixelStatsJobLog::JOB_DAILY,
            'bucket' => $jobBucket,
            'website_id' => $websiteId,
            'tz' => $window['tz'],
            'status' => PixelStatsJobLog::STATUS_RUNNING,
            'started_at' => $startedAt,
            'finished_at' => null,
            'check_json' => null,
            'message' => '',
        ]);

        try {
            $dayRows = $dayRowLoader !== null
                ? $dayRowLoader($websiteId, $window['start_utc'], $window['end_utc'])
                : $this->loadDayRows($websiteId, $window['start_utc'], $window['end_utc']);
            if (!\is_array($dayRows)) {
                $dayRows = [];
            }

            $sessionFirstRows = $sessionFirstLoader !== null
                ? $sessionFirstLoader($websiteId, $window['start_utc'], $window['end_utc'])
                : $this->loadSessionFirstRows($websiteId, $window['start_utc'], $window['end_utc']);
            if (!\is_array($sessionFirstRows)) {
                $sessionFirstRows = [];
            }

            $aggregated = $this->aggregateFromRows(
                $dayRows,
                $websiteId,
                $window['day_bucket'],
                $window['tz'],
                $sessionFirstRows
            );

            $written = $writer !== null
                ? (int)$writer($websiteId, $window['day_bucket'], $aggregated['rows'])
                : $this->replaceBucketRows($websiteId, $window['day_bucket'], $aggregated['rows']);

            $hotCount = $hotCountLoader !== null
                ? (int)$hotCountLoader($websiteId, $window['start_utc'], $window['end_utc'])
                : $this->countHotRows($websiteId, $window['start_utc'], $window['end_utc']);

            $check = $this->validateEvents($hotCount, (int)$aggregated['events_total']);
            $status = $check['ok']
                ? PixelStatsJobLog::STATUS_SUCCESS
                : PixelStatsJobLog::STATUS_FAILED;
            $message = $check['ok']
                ? ''
                : sprintf(
                    'events check failed expected=%d actual=%d abs=%d rel=%.4f',
                    $check['expected'],
                    $check['actual'],
                    $check['abs_diff'],
                    $check['rel_error']
                );

            $finishedAt = date('Y-m-d H:i:s');
            $checkJson = json_encode([
                'expected' => $check['expected'],
                'actual' => $check['actual'],
                'abs_diff' => $check['abs_diff'],
                'rel_error' => $check['rel_error'],
                'ok' => $check['ok'],
                'rows' => $written,
                'funnel_step1_sessions' => (int)($aggregated['funnel']['step1_sessions'] ?? 0),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->writeJobLog($jobLogWriter, [
                'job_type' => PixelStatsJobLog::JOB_DAILY,
                'bucket' => $jobBucket,
                'website_id' => $websiteId,
                'tz' => $window['tz'],
                'status' => $status,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'check_json' => $checkJson === false ? null : $checkJson,
                'message' => $message,
            ]);

            return [
                'website_id' => $websiteId,
                'day_bucket' => $window['day_bucket'],
                'tz' => $window['tz'],
                'status' => $status,
                'rows' => $written,
                'events' => (int)$aggregated['events_total'],
                'check' => $check,
                'message' => $message,
            ];
        } catch (Throwable $e) {
            $finishedAt = date('Y-m-d H:i:s');
            $message = $this->truncate($e->getMessage(), 500);
            $this->writeJobLog($jobLogWriter, [
                'job_type' => PixelStatsJobLog::JOB_DAILY,
                'bucket' => $jobBucket,
                'website_id' => $websiteId,
                'tz' => $window['tz'],
                'status' => PixelStatsJobLog::STATUS_FAILED,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'check_json' => null,
                'message' => $message,
            ]);

            return [
                'website_id' => $websiteId,
                'day_bucket' => $window['day_bucket'],
                'tz' => $window['tz'],
                'status' => PixelStatsJobLog::STATUS_FAILED,
                'rows' => 0,
                'events' => 0,
                'check' => [
                    'ok' => false,
                    'expected' => 0,
                    'actual' => 0,
                    'abs_diff' => 0,
                    'rel_error' => 0.0,
                ],
                'message' => $message,
            ];
        }
    }

    /**
     * @param list<array{website_id:int,tz:string}>|null $targets
     * @return array{ok: int, failed: int, results: list<array<string, mixed>>}
     */
    public function runPreviousDayForAll(
        ?DateTimeInterface $now = null,
        ?array $targets = null,
        ?callable $dayRowLoader = null,
        ?callable $sessionFirstLoader = null,
        ?callable $hotCountLoader = null,
        ?callable $writer = null,
        ?callable $jobLogWriter = null
    ): array {
        $targets ??= $this->hourly()->listWebsiteTargets();
        $results = [];
        $ok = 0;
        $failed = 0;

        foreach ($targets as $target) {
            if (!\is_array($target)) {
                continue;
            }
            $websiteId = (int)($target['website_id'] ?? 0);
            $tz = $this->hourly()->normalizeTimezone((string)($target['tz'] ?? 'UTC'));
            $window = $this->resolvePreviousDayBucket($now, $tz);
            $result = $this->runForWebsite(
                $websiteId,
                $window['day_bucket'],
                $tz,
                $dayRowLoader,
                $sessionFirstLoader,
                $hotCountLoader,
                $writer,
                $jobLogWriter
            );
            $results[] = $result;
            if ($result['status'] === PixelStatsJobLog::STATUS_SUCCESS) {
                $ok++;
            } else {
                $failed++;
            }
        }

        return [
            'ok' => $ok,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function replaceBucketRows(int $websiteId, string $dayBucket, array $rows): int
    {
        /** @var PixelStatsDaily $model */
        $model = ObjectManager::getInstance(PixelStatsDaily::class);
        $model->reset()
            ->where(PixelStatsDaily::schema_fields_DAY_BUCKET, $dayBucket)
            ->where(PixelStatsDaily::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();

        if ($rows === []) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $insertRows = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $insertRows[] = [
                PixelStatsDaily::schema_fields_DAY_BUCKET => $row['day_bucket'] ?? $dayBucket,
                PixelStatsDaily::schema_fields_WEBSITE_ID => (int)($row['website_id'] ?? $websiteId),
                PixelStatsDaily::schema_fields_DIM_HASH => (string)($row['dim_hash'] ?? ''),
                PixelStatsDaily::schema_fields_TZ => (string)($row['tz'] ?? 'UTC'),
                PixelStatsDaily::schema_fields_TRAFFIC_TYPE => (string)($row['traffic_type'] ?? ''),
                PixelStatsDaily::schema_fields_CHANNEL_CODE => (string)($row['channel_code'] ?? ''),
                PixelStatsDaily::schema_fields_UTM_SOURCE => (string)($row['utm_source'] ?? ''),
                PixelStatsDaily::schema_fields_UTM_MEDIUM => (string)($row['utm_medium'] ?? ''),
                PixelStatsDaily::schema_fields_UTM_CAMPAIGN => (string)($row['utm_campaign'] ?? ''),
                PixelStatsDaily::schema_fields_EVENT_NAME => (string)($row['event_name'] ?? ''),
                PixelStatsDaily::schema_fields_DEVICE_CATEGORY => (string)($row['device_category'] ?? ''),
                PixelStatsDaily::schema_fields_EVENTS => (int)($row['events'] ?? 0),
                PixelStatsDaily::schema_fields_VALUE_SUM => (float)($row['value_sum'] ?? 0),
                PixelStatsDaily::schema_fields_VALUED_EVENTS => (int)($row['valued_events'] ?? 0),
                PixelStatsDaily::schema_fields_SESSION_STARTS => (int)($row['session_starts'] ?? 0),
                PixelStatsDaily::schema_fields_PURCHASES => (int)($row['purchases'] ?? 0),
                PixelStatsDaily::schema_fields_ADD_TO_CARTS => (int)($row['add_to_carts'] ?? 0),
                PixelStatsDaily::schema_fields_SESSIONS => (int)($row['sessions'] ?? 0),
                PixelStatsDaily::schema_fields_ENGAGED_SESSIONS => (int)($row['engaged_sessions'] ?? 0),
                PixelStatsDaily::schema_fields_BOUNCE_SESSIONS => (int)($row['bounce_sessions'] ?? 0),
                PixelStatsDaily::schema_fields_CONVERSIONS => (int)($row['conversions'] ?? 0),
                PixelStatsDaily::schema_fields_FUNNEL_JSON => (string)($row['funnel_json'] ?? ''),
                PixelStatsDaily::schema_fields_CREATED_AT => $now,
                PixelStatsDaily::schema_fields_UPDATED_AT => $now,
            ];
        }

        if ($insertRows === []) {
            return 0;
        }

        $model->reset()->insert($insertRows, [], '', true)->fetch();

        return \count($insertRows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadDayRows(int $websiteId, string $startUtc, string $endUtc): array
    {
        return $this->hourly()->loadHourRows($websiteId, $startUtc, $endUtc);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadSessionFirstRows(int $websiteId, string $startUtc, string $endUtc): array
    {
        return $this->hourly()->loadSessionFirstRows($websiteId, $startUtc, $endUtc);
    }

    public function countHotRows(int $websiteId, string $startUtc, string $endUtc): int
    {
        [$sql, $params] = $this->buildHotCountSql($websiteId, $startUtc, $endUtc);
        $pdo = ObjectManager::getInstance(Pixel::class)->getConnection()->getConnector()->getLink();
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . ltrim((string)$key, ':'), $value);
        }
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return (int)$value;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function buildHotCountSql(int $websiteId, string $startUtc, string $endUtc): array
    {
        $prefix = '';
        try {
            $prefix = (string)ObjectManager::getInstance(Pixel::class)
                ->getConnection()
                ->getConfigProvider()
                ->getPrefix();
        } catch (Throwable) {
            $prefix = '';
        }
        $table = '"' . str_replace('"', '""', $prefix . Pixel::schema_table) . '"';
        $wid = '"' . Pixel::schema_fields_WEBSITE_ID . '"';
        $created = 'COALESCE(p."' . Pixel::schema_fields_CREATED_AT . '", p."create_time")';
        $sql = "SELECT COUNT(*) FROM {$table} p"
            . " WHERE p.{$wid} = :website_id"
            . " AND {$created} >= :start_utc"
            . " AND {$created} < :end_utc";

        return [$sql, [
            'website_id' => $websiteId,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
        ]];
    }

    /**
     * @param list<array<string, mixed>> $dayRows
     * @return array<string, array{engaged: bool, bounce: bool, events: int, page_views: int, dwell_ms: int, duration_ms: int}>
     */
    public function buildSessionProfiles(array $dayRows): array
    {
        $sessions = [];
        foreach ($dayRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $sessionId = trim((string)($row[Pixel::schema_fields_SESSION_ID] ?? $row['session_id'] ?? ''));
            if ($sessionId === '') {
                continue;
            }
            if (!isset($sessions[$sessionId])) {
                $sessions[$sessionId] = [
                    'engaged' => false,
                    'events' => 0,
                    'page_views' => 0,
                    'dwell_ms' => 0,
                    'first_at' => '',
                    'last_at' => '',
                ];
            }
            $s = &$sessions[$sessionId];
            $s['events']++;
            $event = strtolower(trim((string)($row[Pixel::schema_fields_EVENT] ?? $row['event'] ?? '')));
            if (\in_array($event, self::PAGE_VIEW_EVENTS, true)) {
                $s['page_views']++;
            }
            if (\in_array($event, self::ENGAGED_EVENTS, true)) {
                $s['engaged'] = true;
            }
            $dwell = $this->dwellFromRow($row);
            if ($dwell > $s['dwell_ms']) {
                $s['dwell_ms'] = $dwell;
            }
            if ($dwell >= self::BOUNCE_DWELL_MS) {
                $s['engaged'] = true;
            }
            if (!empty($row['engaged']) || $this->engagedFlagFromRow($row)) {
                $s['engaged'] = true;
            }
            $at = (string)($row[Pixel::schema_fields_CREATED_AT] ?? $row['created_at'] ?? $row['create_time'] ?? '');
            if ($at !== '') {
                if ($s['first_at'] === '' || $at < $s['first_at']) {
                    $s['first_at'] = $at;
                }
                if ($s['last_at'] === '' || $at > $s['last_at']) {
                    $s['last_at'] = $at;
                }
            }
            unset($s);
        }

        foreach ($sessions as &$s) {
            $duration = $s['dwell_ms'];
            if ($duration <= 0 && $s['first_at'] !== '' && $s['last_at'] !== '' && $s['last_at'] > $s['first_at']) {
                $duration = max(0, (int)((strtotime($s['last_at']) - strtotime($s['first_at'])) * 1000));
            }
            $s['duration_ms'] = $duration;
            $s['bounce'] = !$s['engaged']
                && $s['page_views'] <= 1
                && $s['events'] <= 2
                && $duration < self::BOUNCE_DWELL_MS;
            unset($s['first_at'], $s['last_at']);
        }
        unset($s);

        return $sessions;
    }

    /**
     * @param array<string, string> $dims
     * @return array<string, mixed>
     */
    private function emptyMetricRow(
        int $websiteId,
        string $dayBucket,
        string $tz,
        array $dims,
        string $hash,
        string $funnelJson
    ): array {
        return [
            'day_bucket' => $dayBucket,
            'website_id' => $websiteId,
            'dim_hash' => $hash,
            'tz' => $tz,
            'traffic_type' => $dims['traffic_type'],
            'channel_code' => $dims['channel_code'],
            'utm_source' => $dims['utm_source'],
            'utm_medium' => $dims['utm_medium'],
            'utm_campaign' => $dims['utm_campaign'],
            'event_name' => $dims['event_name'],
            'device_category' => $dims['device_category'],
            'events' => 0,
            'value_sum' => 0.0,
            'valued_events' => 0,
            'session_starts' => 0,
            'purchases' => 0,
            'add_to_carts' => 0,
            'sessions' => 0,
            'engaged_sessions' => 0,
            'bounce_sessions' => 0,
            'conversions' => 0,
            'funnel_json' => $funnelJson,
            '_session_ids' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $dayRows
     * @return list<array<string, mixed>>
     */
    private function approximateSessionFirstRows(array $dayRows): array
    {
        $first = [];
        foreach ($dayRows as $i => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $sessionId = trim((string)($row[Pixel::schema_fields_SESSION_ID] ?? $row['session_id'] ?? ''));
            if ($sessionId === '') {
                continue;
            }
            $at = (string)($row[Pixel::schema_fields_CREATED_AT] ?? $row['created_at'] ?? $row['create_time'] ?? '');
            $key = sprintf('%s|%020d', $at, (int)($row[Pixel::schema_fields_ID] ?? $row['pixel_id'] ?? $i));
            if (!isset($first[$sessionId]) || $key < $first[$sessionId]['key']) {
                $first[$sessionId] = ['key' => $key, 'row' => $row];
            }
        }

        return array_values(array_map(static fn (array $item): array => $item['row'], $first));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function dwellFromRow(array $row): int
    {
        if (isset($row['dwell_ms'])) {
            return max(0, (int)$row['dwell_ms']);
        }
        $raw = $row[Pixel::schema_fields_BROWSER_INFO] ?? $row['browser_info'] ?? null;
        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($raw)) {
            return 0;
        }
        $additional = \is_array($raw['additionalInfo'] ?? null) ? $raw['additionalInfo'] : [];
        $engagement = \is_array($additional['engagement'] ?? null) ? $additional['engagement'] : [];
        $meta = \is_array($additional['meta'] ?? null) ? $additional['meta'] : [];

        return max(0, (int)($engagement['dwell_ms'] ?? $meta['duration_ms'] ?? $raw['dwell_ms'] ?? 0));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function engagedFlagFromRow(array $row): bool
    {
        $raw = $row[Pixel::schema_fields_BROWSER_INFO] ?? $row['browser_info'] ?? null;
        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($raw)) {
            return false;
        }
        $additional = \is_array($raw['additionalInfo'] ?? null) ? $raw['additionalInfo'] : [];
        $engagement = \is_array($additional['engagement'] ?? null) ? $additional['engagement'] : [];

        return !empty($engagement['engaged']);
    }

    /**
     * @param callable|null $jobLogWriter
     * @param array<string, mixed> $payload
     */
    private function writeJobLog(?callable $jobLogWriter, array $payload): void
    {
        if ($jobLogWriter !== null) {
            $jobLogWriter($payload);

            return;
        }
        $this->hourly()->upsertJobLog($payload);
    }

    private function hourly(): PixelStatsHourlyAggregateService
    {
        return $this->hourly ??= new PixelStatsHourlyAggregateService();
    }

    private function funnel(): PixelChannelFunnelService
    {
        return $this->funnel ??= new PixelChannelFunnelService();
    }

    private function truncate(string $message, int $max): string
    {
        if (\strlen($message) <= $max) {
            return $message;
        }

        return substr($message, 0, $max);
    }
}
