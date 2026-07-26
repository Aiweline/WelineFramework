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
use Weline\Visitor\Model\PixelStatsHourly;
use Weline\Visitor\Model\PixelStatsJobLog;
use Weline\Websites\Model\Website;

/**
 * G04：小时聚合（热表 → pixel_stats_hourly + job_log）。
 *
 * §2.5 口径：
 * - hour_bucket / tz 按站点时区（无效则 UTC）；
 * - 唯一键 (hour_bucket, website_id, dim_hash) 覆盖式重跑；
 * - dim_hash 走 PixelStatsHourly::dimHash（G01 契约）；
 * - session_starts：会话全局 first_at 落在本小时时，按首事件维计数；
 * - 不写日表、不删热。
 */
class PixelStatsHourlyAggregateService
{
    /** @var list<string> */
    public const PURCHASE_EVENTS = ['checkout_success', 'purchase'];

    /** @var list<string> */
    public const ADD_TO_CART_EVENTS = ['add_to_cart'];

    public function __construct(
        private ?PixelLandingDeviceDerivation $deviceDerivation = null,
    ) {
    }

    /**
     * 解析上一完整小时桶（站点时区）。
     *
     * @return array{hour_bucket: string, start_utc: string, end_utc: string, tz: string}
     */
    public function resolvePreviousHourBucket(?DateTimeInterface $now = null, string $tz = 'UTC'): array
    {
        $tzName = $this->normalizeTimezone($tz);
        $zone = new DateTimeZone($tzName);
        $localNow = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable('now'))
            ->setTimezone($zone);
        $bucketStart = $localNow->setTime((int)$localNow->format('H'), 0, 0)->modify('-1 hour');
        $bucketEnd = $bucketStart->modify('+1 hour');

        return [
            'hour_bucket' => $bucketStart->format('Y-m-d H:i:s'),
            'start_utc' => $bucketStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end_utc' => $bucketEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'tz' => $tzName,
        ];
    }

    /**
     * 将站点本地小时桶转为 UTC 半开区间 [start, end)。
     *
     * @return array{hour_bucket: string, start_utc: string, end_utc: string, tz: string}
     */
    public function resolveBucketWindow(string $hourBucket, string $tz = 'UTC'): array
    {
        $tzName = $this->normalizeTimezone($tz);
        $zone = new DateTimeZone($tzName);
        $bucketStart = new DateTimeImmutable($hourBucket, $zone);
        $bucketStart = $bucketStart->setTime((int)$bucketStart->format('H'), 0, 0);
        $bucketEnd = $bucketStart->modify('+1 hour');

        return [
            'hour_bucket' => $bucketStart->format('Y-m-d H:i:s'),
            'start_utc' => $bucketStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end_utc' => $bucketEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'tz' => $tzName,
        ];
    }

    public function normalizeTimezone(string $tz): string
    {
        $tz = trim($tz);
        if ($tz === '') {
            return 'UTC';
        }
        try {
            new DateTimeZone($tz);

            return $tz;
        } catch (Throwable) {
            return 'UTC';
        }
    }

    /**
     * 纯聚合（不查库）。$sessionFirstRows 为 first_at 落在本小时的会话首事件；
     * 若为 null，则仅用 $hourRows 内各 session 的最早事件近似（单测可用，生产请传真实首事件）。
     *
     * @param list<array<string, mixed>> $hourRows
     * @param list<array<string, mixed>>|null $sessionFirstRows
     * @return list<array<string, mixed>>
     */
    public function aggregateFromRows(
        array $hourRows,
        int $websiteId,
        string $hourBucket,
        string $tz,
        ?array $sessionFirstRows = null
    ): array {
        $tzName = $this->normalizeTimezone($tz);
        $bucket = $this->resolveBucketWindow($hourBucket, $tzName)['hour_bucket'];
        $groups = [];

        foreach ($hourRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $dims = $this->dimsFromRow($row);
            $hash = PixelStatsHourly::dimHash($dims);
            if (!isset($groups[$hash])) {
                $groups[$hash] = $this->emptyMetricRow($websiteId, $bucket, $tzName, $dims, $hash);
            }
            $groups[$hash]['events']++;
            $value = (float)($row[Pixel::schema_fields_VALUE] ?? $row['value'] ?? 0);
            $groups[$hash]['value_sum'] += $value;
            if ($value > 0) {
                $groups[$hash]['valued_events']++;
            }
            $event = strtolower(trim((string)($row[Pixel::schema_fields_EVENT] ?? $row['event'] ?? '')));
            if (\in_array($event, self::PURCHASE_EVENTS, true)) {
                $groups[$hash]['purchases']++;
            }
            if (\in_array($event, self::ADD_TO_CART_EVENTS, true)) {
                $groups[$hash]['add_to_carts']++;
            }
        }

        $startRows = $sessionFirstRows;
        if ($startRows === null) {
            $startRows = $this->approximateSessionFirstRows($hourRows);
        }
        foreach ($startRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $dims = $this->dimsFromRow($row);
            $hash = PixelStatsHourly::dimHash($dims);
            if (!isset($groups[$hash])) {
                $groups[$hash] = $this->emptyMetricRow($websiteId, $bucket, $tzName, $dims, $hash);
            }
            $groups[$hash]['session_starts']++;
        }

        foreach ($groups as &$group) {
            $group['value_sum'] = round((float)$group['value_sum'], 4);
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * 聚合并覆盖写入一站一时桶；成功/失败写 job_log。
     *
     * @param callable|null $hourRowLoader fn(int $websiteId, string $startUtc, string $endUtc): list<array>
     * @param callable|null $sessionFirstLoader fn(int $websiteId, string $startUtc, string $endUtc): list<array>
     * @param callable|null $writer fn(int $websiteId, string $hourBucket, list<array> $rows): int 返回写入行数
     * @param callable|null $jobLogWriter fn(array $payload): void
     * @return array{
     *   website_id: int,
     *   hour_bucket: string,
     *   tz: string,
     *   status: string,
     *   rows: int,
     *   events: int,
     *   message: string
     * }
     */
    public function runForWebsite(
        int $websiteId,
        string $hourBucket,
        string $tz,
        ?callable $hourRowLoader = null,
        ?callable $sessionFirstLoader = null,
        ?callable $writer = null,
        ?callable $jobLogWriter = null
    ): array {
        $window = $this->resolveBucketWindow($hourBucket, $tz);
        $startedAt = date('Y-m-d H:i:s');
        $this->writeJobLog($jobLogWriter, [
            'job_type' => PixelStatsJobLog::JOB_HOURLY,
            'bucket' => $window['hour_bucket'],
            'website_id' => $websiteId,
            'tz' => $window['tz'],
            'status' => PixelStatsJobLog::STATUS_RUNNING,
            'started_at' => $startedAt,
            'finished_at' => null,
            'check_json' => null,
            'message' => '',
        ]);

        try {
            $hourRows = $hourRowLoader !== null
                ? $hourRowLoader($websiteId, $window['start_utc'], $window['end_utc'])
                : $this->loadHourRows($websiteId, $window['start_utc'], $window['end_utc']);
            if (!\is_array($hourRows)) {
                $hourRows = [];
            }

            $sessionFirstRows = $sessionFirstLoader !== null
                ? $sessionFirstLoader($websiteId, $window['start_utc'], $window['end_utc'])
                : $this->loadSessionFirstRows($websiteId, $window['start_utc'], $window['end_utc']);
            if (!\is_array($sessionFirstRows)) {
                $sessionFirstRows = [];
            }

            $aggregated = $this->aggregateFromRows(
                $hourRows,
                $websiteId,
                $window['hour_bucket'],
                $window['tz'],
                $sessionFirstRows
            );

            $written = $writer !== null
                ? (int)$writer($websiteId, $window['hour_bucket'], $aggregated)
                : $this->replaceBucketRows($websiteId, $window['hour_bucket'], $aggregated);

            $events = 0;
            foreach ($aggregated as $row) {
                $events += (int)($row['events'] ?? 0);
            }

            $finishedAt = date('Y-m-d H:i:s');
            $this->writeJobLog($jobLogWriter, [
                'job_type' => PixelStatsJobLog::JOB_HOURLY,
                'bucket' => $window['hour_bucket'],
                'website_id' => $websiteId,
                'tz' => $window['tz'],
                'status' => PixelStatsJobLog::STATUS_SUCCESS,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'check_json' => json_encode([
                    'rows' => $written,
                    'events' => $events,
                    'session_first_rows' => \count($sessionFirstRows),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'message' => '',
            ]);

            return [
                'website_id' => $websiteId,
                'hour_bucket' => $window['hour_bucket'],
                'tz' => $window['tz'],
                'status' => PixelStatsJobLog::STATUS_SUCCESS,
                'rows' => $written,
                'events' => $events,
                'message' => '',
            ];
        } catch (Throwable $e) {
            $finishedAt = date('Y-m-d H:i:s');
            $message = $this->truncate($e->getMessage(), 500);
            $this->writeJobLog($jobLogWriter, [
                'job_type' => PixelStatsJobLog::JOB_HOURLY,
                'bucket' => $window['hour_bucket'],
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
                'hour_bucket' => $window['hour_bucket'],
                'tz' => $window['tz'],
                'status' => PixelStatsJobLog::STATUS_FAILED,
                'rows' => 0,
                'events' => 0,
                'message' => $message,
            ];
        }
    }

    /**
     * Cron 入口：对所有目标站跑上一完整小时。
     *
     * @param list<array{website_id:int,tz:string}>|null $targets
     * @return array{ok: int, failed: int, results: list<array<string, mixed>>}
     */
    public function runPreviousHourForAll(
        ?DateTimeInterface $now = null,
        ?array $targets = null,
        ?callable $hourRowLoader = null,
        ?callable $sessionFirstLoader = null,
        ?callable $writer = null,
        ?callable $jobLogWriter = null
    ): array {
        $targets ??= $this->listWebsiteTargets();
        $results = [];
        $ok = 0;
        $failed = 0;

        foreach ($targets as $target) {
            if (!\is_array($target)) {
                continue;
            }
            $websiteId = (int)($target['website_id'] ?? 0);
            $tz = $this->normalizeTimezone((string)($target['tz'] ?? 'UTC'));
            $window = $this->resolvePreviousHourBucket($now, $tz);
            $result = $this->runForWebsite(
                $websiteId,
                $window['hour_bucket'],
                $tz,
                $hourRowLoader,
                $sessionFirstLoader,
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
     * @return list<array{website_id: int, tz: string}>
     */
    public function listWebsiteTargets(?callable $loader = null): array
    {
        if ($loader !== null) {
            $rows = $loader();

            return $this->normalizeWebsiteTargets(\is_array($rows) ? $rows : []);
        }

        $out = [['website_id' => 0, 'tz' => 'UTC']];
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $rows = $website->reset()->select()->fetchArray();
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $id = (int)($row[Website::schema_fields_ID] ?? $row['website_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $out[] = [
                    'website_id' => $id,
                    'tz' => $this->normalizeTimezone((string)($row[Website::schema_fields_DEFAULT_TIMEZONE] ?? $row['default_timezone'] ?? 'UTC')),
                ];
            }
        } catch (Throwable) {
            // Websites 模块不可用时仍处理 website_id=0
        }

        return $this->normalizeWebsiteTargets($out);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{website_id: int, tz: string}>
     */
    public function normalizeWebsiteTargets(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row['website_id'] ?? 0);
            $map[$id] = [
                'website_id' => $id,
                'tz' => $this->normalizeTimezone((string)($row['tz'] ?? 'UTC')),
            ];
        }
        if (!isset($map[0])) {
            $map[0] = ['website_id' => 0, 'tz' => 'UTC'];
        }
        ksort($map);

        return array_values($map);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public function dimsFromRow(array $row): array
    {
        return PixelStatsHourly::normalizeDims([
            'traffic_type' => (string)($row[Pixel::schema_fields_TRAFFIC_TYPE] ?? $row['traffic_type'] ?? ''),
            'channel_code' => (string)($row[Pixel::schema_fields_CHANNEL_CODE] ?? $row['channel_code'] ?? ''),
            'utm_source' => (string)($row[Pixel::schema_fields_UTM_SOURCE] ?? $row['utm_source'] ?? ''),
            'utm_medium' => (string)($row[Pixel::schema_fields_UTM_MEDIUM] ?? $row['utm_medium'] ?? ''),
            'utm_campaign' => (string)($row[Pixel::schema_fields_UTM_CAMPAIGN] ?? $row['utm_campaign'] ?? ''),
            'event_name' => (string)($row[Pixel::schema_fields_EVENT] ?? $row['event'] ?? $row['event_name'] ?? ''),
            'device_category' => $this->deviceCategoryFromRow($row),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function deviceCategoryFromRow(array $row): string
    {
        $explicit = strtolower(trim((string)($row['device_category'] ?? '')));
        if ($explicit !== '') {
            return $explicit;
        }

        $ua = trim((string)($row[Pixel::schema_fields_USER_AGENT] ?? $row['user_agent'] ?? ''));
        $device = [];
        $raw = $row[Pixel::schema_fields_BROWSER_INFO] ?? $row['browser_info'] ?? null;
        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (\is_array($decoded)) {
                $additional = \is_array($decoded['additionalInfo'] ?? null) ? $decoded['additionalInfo'] : [];
                $device = \is_array($additional['device'] ?? null) ? $additional['device'] : [];
                if ($ua === '') {
                    $ua = trim((string)($decoded['user_agent'] ?? $decoded['ua'] ?? ''));
                }
            }
        } elseif (\is_array($raw)) {
            $additional = \is_array($raw['additionalInfo'] ?? null) ? $raw['additionalInfo'] : [];
            $device = \is_array($additional['device'] ?? null) ? $additional['device'] : [];
        }

        return $this->deviceDerivation()->deriveDeviceCategory($ua, $device);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function replaceBucketRows(int $websiteId, string $hourBucket, array $rows): int
    {
        /** @var PixelStatsHourly $model */
        $model = ObjectManager::getInstance(PixelStatsHourly::class);
        $model->reset()
            ->where(PixelStatsHourly::schema_fields_HOUR_BUCKET, $hourBucket)
            ->where(PixelStatsHourly::schema_fields_WEBSITE_ID, $websiteId)
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
                PixelStatsHourly::schema_fields_HOUR_BUCKET => $row['hour_bucket'] ?? $hourBucket,
                PixelStatsHourly::schema_fields_WEBSITE_ID => (int)($row['website_id'] ?? $websiteId),
                PixelStatsHourly::schema_fields_DIM_HASH => (string)($row['dim_hash'] ?? ''),
                PixelStatsHourly::schema_fields_TZ => (string)($row['tz'] ?? 'UTC'),
                PixelStatsHourly::schema_fields_TRAFFIC_TYPE => (string)($row['traffic_type'] ?? ''),
                PixelStatsHourly::schema_fields_CHANNEL_CODE => (string)($row['channel_code'] ?? ''),
                PixelStatsHourly::schema_fields_UTM_SOURCE => (string)($row['utm_source'] ?? ''),
                PixelStatsHourly::schema_fields_UTM_MEDIUM => (string)($row['utm_medium'] ?? ''),
                PixelStatsHourly::schema_fields_UTM_CAMPAIGN => (string)($row['utm_campaign'] ?? ''),
                PixelStatsHourly::schema_fields_EVENT_NAME => (string)($row['event_name'] ?? ''),
                PixelStatsHourly::schema_fields_DEVICE_CATEGORY => (string)($row['device_category'] ?? ''),
                PixelStatsHourly::schema_fields_EVENTS => (int)($row['events'] ?? 0),
                PixelStatsHourly::schema_fields_VALUE_SUM => (float)($row['value_sum'] ?? 0),
                PixelStatsHourly::schema_fields_VALUED_EVENTS => (int)($row['valued_events'] ?? 0),
                PixelStatsHourly::schema_fields_SESSION_STARTS => (int)($row['session_starts'] ?? 0),
                PixelStatsHourly::schema_fields_PURCHASES => (int)($row['purchases'] ?? 0),
                PixelStatsHourly::schema_fields_ADD_TO_CARTS => (int)($row['add_to_carts'] ?? 0),
                PixelStatsHourly::schema_fields_CREATED_AT => $now,
                PixelStatsHourly::schema_fields_UPDATED_AT => $now,
            ];
        }

        if ($insertRows === []) {
            return 0;
        }

        $model->reset()->insert($insertRows, [], '', true)->fetch();

        return \count($insertRows);
    }

    /**
     * @param array{
     *   job_type: string,
     *   bucket: string,
     *   website_id: int,
     *   tz: string,
     *   status: string,
     *   started_at: ?string,
     *   finished_at: ?string,
     *   check_json: ?string,
     *   message: string
     * } $payload
     */
    public function upsertJobLog(array $payload): void
    {
        /** @var PixelStatsJobLog $model */
        $model = ObjectManager::getInstance(PixelStatsJobLog::class);
        $jobType = (string)$payload['job_type'];
        $bucket = (string)$payload['bucket'];
        $websiteId = (int)$payload['website_id'];
        $now = date('Y-m-d H:i:s');

        $existing = $model->reset()
            ->where(PixelStatsJobLog::schema_fields_JOB_TYPE, $jobType)
            ->where(PixelStatsJobLog::schema_fields_BUCKET, $bucket)
            ->where(PixelStatsJobLog::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();

        $attempts = 1;
        $createdAt = $now;
        if ($existing instanceof PixelStatsJobLog && $existing->getId()) {
            $attempts = (int)$existing->getData(PixelStatsJobLog::schema_fields_ATTEMPTS) + 1;
            $createdAt = (string)($existing->getData(PixelStatsJobLog::schema_fields_CREATED_AT) ?: $now);
            $existing->delete();
        }

        $model->clear()->reset()->setData([
            PixelStatsJobLog::schema_fields_JOB_TYPE => $jobType,
            PixelStatsJobLog::schema_fields_BUCKET => $bucket,
            PixelStatsJobLog::schema_fields_WEBSITE_ID => $websiteId,
            PixelStatsJobLog::schema_fields_TZ => (string)$payload['tz'],
            PixelStatsJobLog::schema_fields_STATUS => (string)$payload['status'],
            PixelStatsJobLog::schema_fields_ATTEMPTS => $attempts,
            PixelStatsJobLog::schema_fields_CHECK_JSON => $payload['check_json'],
            PixelStatsJobLog::schema_fields_MESSAGE => (string)($payload['message'] ?? ''),
            PixelStatsJobLog::schema_fields_STARTED_AT => $payload['started_at'],
            PixelStatsJobLog::schema_fields_FINISHED_AT => $payload['finished_at'],
            PixelStatsJobLog::schema_fields_CREATED_AT => $createdAt,
            PixelStatsJobLog::schema_fields_UPDATED_AT => $now,
        ])->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadHourRows(int $websiteId, string $startUtc, string $endUtc): array
    {
        [$sql, $params] = $this->buildHourRowsSql($websiteId, $startUtc, $endUtc);

        return $this->fetchAll($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadSessionFirstRows(int $websiteId, string $startUtc, string $endUtc): array
    {
        [$sql, $params] = $this->buildSessionFirstRowsSql($websiteId, $startUtc, $endUtc);

        return $this->fetchAll($sql, $params);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function buildHourRowsSql(int $websiteId, string $startUtc, string $endUtc): array
    {
        $table = $this->table(Pixel::schema_table);
        $sql = "SELECT * FROM {$table} p"
            . ' WHERE p.' . $this->quoteIdent(Pixel::schema_fields_WEBSITE_ID) . ' = :website_id'
            . ' AND COALESCE(p.' . $this->quoteIdent(Pixel::schema_fields_CREATED_AT) . ', p.' . $this->quoteIdent('create_time') . ')'
            . ' >= :start_utc'
            . ' AND COALESCE(p.' . $this->quoteIdent(Pixel::schema_fields_CREATED_AT) . ', p.' . $this->quoteIdent('create_time') . ')'
            . ' < :end_utc'
            . ' ORDER BY COALESCE(p.' . $this->quoteIdent(Pixel::schema_fields_CREATED_AT) . ', p.' . $this->quoteIdent('create_time') . ') ASC,'
            . ' p.' . $this->quoteIdent(Pixel::schema_fields_ID) . ' ASC';

        return [$sql, [
            'website_id' => $websiteId,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
        ]];
    }

    /**
     * 会话全局 first_at 落在 [start,end) 的首事件行。
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function buildSessionFirstRowsSql(int $websiteId, string $startUtc, string $endUtc): array
    {
        $table = $this->table(Pixel::schema_table);
        $sid = $this->quoteIdent(Pixel::schema_fields_SESSION_ID);
        $wid = $this->quoteIdent(Pixel::schema_fields_WEBSITE_ID);
        $created = 'COALESCE(p.' . $this->quoteIdent(Pixel::schema_fields_CREATED_AT)
            . ', p.' . $this->quoteIdent('create_time') . ')';
        $pid = $this->quoteIdent(Pixel::schema_fields_ID);

        $sql = "SELECT p.* FROM {$table} p"
            . " INNER JOIN ("
            . " SELECT {$sid} AS session_id, MIN({$created}) AS first_at"
            . " FROM {$table} p"
            . " WHERE p.{$wid} = :website_id"
            . " AND TRIM(COALESCE(p.{$sid}, '')) <> ''"
            . " GROUP BY p.{$sid}"
            . " HAVING MIN({$created}) >= :start_utc AND MIN({$created}) < :end_utc"
            . " ) s ON p.{$sid} = s.session_id AND {$created} = s.first_at"
            . " WHERE p.{$wid} = :website_id2"
            . " ORDER BY {$created} ASC, p.{$pid} ASC";

        return [$sql, [
            'website_id' => $websiteId,
            'website_id2' => $websiteId,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
        ]];
    }

    /**
     * @param array<string, string> $dims
     * @return array<string, mixed>
     */
    private function emptyMetricRow(int $websiteId, string $hourBucket, string $tz, array $dims, string $hash): array
    {
        return [
            'hour_bucket' => $hourBucket,
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
        ];
    }

    /**
     * @param list<array<string, mixed>> $hourRows
     * @return list<array<string, mixed>>
     */
    private function approximateSessionFirstRows(array $hourRows): array
    {
        $first = [];
        foreach ($hourRows as $i => $row) {
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
     * @param callable|null $jobLogWriter
     * @param array<string, mixed> $payload
     */
    private function writeJobLog(?callable $jobLogWriter, array $payload): void
    {
        if ($jobLogWriter !== null) {
            $jobLogWriter($payload);

            return;
        }
        $this->upsertJobLog($payload);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params): array
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . ltrim((string)$key, ':'), $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return \is_array($rows) ? $rows : [];
    }

    private function pdo(): PDO
    {
        return ObjectManager::getInstance(Pixel::class)->getConnection()->getConnector()->getLink();
    }

    private function table(string $name): string
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

        return $this->quoteIdent($prefix . $name);
    }

    private function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function deviceDerivation(): PixelLandingDeviceDerivation
    {
        return $this->deviceDerivation ??= new PixelLandingDeviceDerivation();
    }

    private function truncate(string $message, int $max): string
    {
        if (\strlen($message) <= $max) {
            return $message;
        }

        return substr($message, 0, $max);
    }
}
