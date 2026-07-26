<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelStatsJobLog;
use Weline\Visitor\Service\Report\PixelQueryRouter;

/**
 * G08：热表 Retention（§2.5 门禁）。
 *
 * 仅当：
 * - 行 `created_at < now - hot_days`，且
 * - 对应站点日桶在 `pixel_stats_job_log` 存在 `job_type=daily` + `status=success`
 * 才允许「先迁冷再删热」。缺行 / failed / 非 success **一律跳过**，绝不默认放行。
 *
 * 默认 dry-run；正式删热必须显式 apply。
 */
class PixelHotRetentionService
{
    public const DEFAULT_HOT_DAYS = PixelQueryRouter::DEFAULT_HOT_RETENTION_DAYS;
    public const DEFAULT_LIMIT = 500;
    public const MAX_LIMIT = 5000;

    public function __construct(
        private ?PixelArchiveMigrateService $archiveMigrate = null,
        private int $hotDays = self::DEFAULT_HOT_DAYS,
    ) {
        if ($this->hotDays < 1) {
            throw new \InvalidArgumentException('hot days must be greater than zero');
        }
    }

    public function getHotDays(): int
    {
        return $this->hotDays;
    }

    /**
     * @param array{
     *   website_id?: int|null,
     *   hot_days?: int|null,
     *   limit?: int,
     *   now?: DateTimeInterface|string|null
     * } $options
     * @return array{
     *   website_id: int|null,
     *   hot_days: int,
     *   cutoff: string,
     *   limit: int,
     *   dry_run: bool,
     *   eligible_days: int,
     *   skipped_days: int,
     *   candidate_rows: int,
     *   would_archive: int,
     *   would_delete: int,
     *   archived: int,
     *   deleted: int,
     *   days: list<array<string, mixed>>,
     *   message: string
     * }
     */
    public function dryRun(array $options = [], ?callable $jobLogLoader = null, ?callable $hotRowLoader = null): array
    {
        return $this->run(false, $options, $jobLogLoader, $hotRowLoader, null, null);
    }

    /**
     * @param array{
     *   website_id?: int|null,
     *   hot_days?: int|null,
     *   limit?: int,
     *   now?: DateTimeInterface|string|null
     * } $options
     * @return array<string, mixed>
     */
    public function apply(
        array $options = [],
        ?callable $jobLogLoader = null,
        ?callable $hotRowLoader = null,
        ?callable $archiver = null,
        ?callable $deleter = null
    ): array {
        return $this->run(true, $options, $jobLogLoader, $hotRowLoader, $archiver, $deleter);
    }

    /**
     * 门禁：只有显式 daily + success 才放行。
     *
     * @param array<string, mixed> $jobLog
     */
    public function isEligibleJobLog(array $jobLog): bool
    {
        $type = strtolower(trim((string)($jobLog[PixelStatsJobLog::schema_fields_JOB_TYPE] ?? $jobLog['job_type'] ?? '')));
        $status = strtolower(trim((string)($jobLog[PixelStatsJobLog::schema_fields_STATUS] ?? $jobLog['status'] ?? '')));

        return $type === PixelStatsJobLog::JOB_DAILY
            && $status === PixelStatsJobLog::STATUS_SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $jobLogs
     * @return array{eligible: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function partitionJobLogs(array $jobLogs, string $cutoffUtc): array
    {
        $eligible = [];
        $skipped = [];
        $cutoff = new DateTimeImmutable($cutoffUtc, new DateTimeZone('UTC'));

        foreach ($jobLogs as $log) {
            if (!\is_array($log)) {
                continue;
            }
            $day = $this->normalizeDayBucket((string)($log[PixelStatsJobLog::schema_fields_BUCKET] ?? $log['bucket'] ?? ''));
            $tz = $this->normalizeTimezone((string)($log[PixelStatsJobLog::schema_fields_TZ] ?? $log['tz'] ?? 'UTC'));
            $websiteId = (int)($log[PixelStatsJobLog::schema_fields_WEBSITE_ID] ?? $log['website_id'] ?? 0);
            $window = $this->dayWindowUtc($day, $tz);
            $meta = [
                'website_id' => $websiteId,
                'day_bucket' => $day,
                'tz' => $tz,
                'start_utc' => $window['start_utc'],
                'end_utc' => $window['end_utc'],
                'status' => (string)($log[PixelStatsJobLog::schema_fields_STATUS] ?? $log['status'] ?? ''),
                'job_type' => (string)($log[PixelStatsJobLog::schema_fields_JOB_TYPE] ?? $log['job_type'] ?? ''),
                'reason' => '',
            ];

            if (!$this->isEligibleJobLog($log)) {
                $meta['reason'] = 'job_log_not_success';
                $skipped[] = $meta;
                continue;
            }

            // 日桶结束时刻必须整段落在 cutoff 之前，避免删到仍在热窗内的当天尾巴
            $dayEnd = new DateTimeImmutable($window['end_utc'], new DateTimeZone('UTC'));
            if ($dayEnd > $cutoff) {
                $meta['reason'] = 'day_not_before_cutoff';
                $skipped[] = $meta;
                continue;
            }

            $eligible[] = $meta;
        }

        return ['eligible' => $eligible, 'skipped' => $skipped];
    }

    /**
     * @return array{day_bucket: string, start_utc: string, end_utc: string, tz: string}
     */
    public function dayWindowUtc(string $dayBucket, string $tz = 'UTC'): array
    {
        $tzName = $this->normalizeTimezone($tz);
        $day = $this->normalizeDayBucket($dayBucket);
        $zone = new DateTimeZone($tzName);
        $start = new DateTimeImmutable($day . ' 00:00:00', $zone);
        $end = $start->modify('+1 day');

        return [
            'day_bucket' => $day,
            'start_utc' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end_utc' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'tz' => $tzName,
        ];
    }

    public function resolveCutoff(?DateTimeInterface $now = null, ?int $hotDays = null): string
    {
        $days = $hotDays ?? $this->hotDays;
        $days = max(1, $days);
        $nowAt = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        return $nowAt->sub(new DateInterval('P' . $days . 'D'))->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $options
     * @return array{
     *   website_id: int|null,
     *   hot_days: int,
     *   cutoff: string,
     *   limit: int,
     *   now: DateTimeImmutable
     * }
     */
    public function normalizeOptions(array $options): array
    {
        $websiteId = array_key_exists('website_id', $options) ? $options['website_id'] : null;
        if ($websiteId !== null) {
            $websiteId = (int)$websiteId;
            if ($websiteId < 0) {
                $websiteId = null;
            }
        }

        $hotDays = (int)($options['hot_days'] ?? $this->hotDays);
        $hotDays = max(1, $hotDays);
        $limit = (int)($options['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $nowRaw = $options['now'] ?? null;
        if ($nowRaw instanceof DateTimeInterface) {
            $now = DateTimeImmutable::createFromInterface($nowRaw)->setTimezone(new DateTimeZone('UTC'));
        } elseif (\is_string($nowRaw) && trim($nowRaw) !== '') {
            $now = new DateTimeImmutable(trim($nowRaw), new DateTimeZone('UTC'));
        } else {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        return [
            'website_id' => $websiteId,
            'hot_days' => $hotDays,
            'cutoff' => $this->resolveCutoff($now, $hotDays),
            'limit' => $limit,
            'now' => $now,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function run(
        bool $write,
        array $options,
        ?callable $jobLogLoader,
        ?callable $hotRowLoader,
        ?callable $archiver,
        ?callable $deleter
    ): array {
        $normalized = $this->normalizeOptions($options);
        $jobLogs = $jobLogLoader !== null
            ? $jobLogLoader($normalized)
            : $this->loadJobLogsForRetention($normalized['website_id'], $normalized['cutoff'], $normalized['limit']);
        if (!\is_array($jobLogs)) {
            $jobLogs = [];
        }

        $partition = $this->partitionJobLogs($jobLogs, $normalized['cutoff']);
        $dayReports = [];
        $candidateRows = 0;
        $wouldArchive = 0;
        $wouldDelete = 0;
        $archived = 0;
        $deleted = 0;
        $remaining = $normalized['limit'];

        foreach ($partition['eligible'] as $day) {
            if ($remaining <= 0) {
                break;
            }
            $rows = $hotRowLoader !== null
                ? $hotRowLoader($day, $normalized['cutoff'], $remaining)
                : $this->loadHotRowsForDay($day, $normalized['cutoff'], $remaining);
            if (!\is_array($rows)) {
                $rows = [];
            }

            $rowCount = \count($rows);
            $candidateRows += $rowCount;
            $remaining -= $rowCount;

            $pixelIds = [];
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $id = (int)($row[Pixel::schema_fields_ID] ?? $row['pixel_id'] ?? 0);
                if ($id > 0) {
                    $pixelIds[] = $id;
                }
            }

            $dayReport = $day;
            $dayReport['candidate_rows'] = $rowCount;
            $dayReport['would_archive'] = $rowCount;
            $dayReport['would_delete'] = $rowCount;
            $dayReport['archived'] = 0;
            $dayReport['deleted'] = 0;
            $dayReport['action'] = $write ? 'apply' : 'dry-run';

            if ($write && $rowCount > 0) {
                if ($archiver !== null) {
                    $archResult = $archiver($rows, $day);
                    $dayReport['archived'] = (int)($archResult['inserted'] ?? $archResult['archived'] ?? $rowCount);
                } else {
                    $archResult = $this->archiveRows($rows, $day);
                    $dayReport['archived'] = (int)($archResult['inserted'] ?? 0) + (int)($archResult['already_archived'] ?? 0);
                }

                if ($deleter !== null) {
                    $dayReport['deleted'] = (int)$deleter($pixelIds, $day);
                } else {
                    $dayReport['deleted'] = $this->deleteHotRows($pixelIds);
                }
                $archived += (int)$dayReport['archived'];
                $deleted += (int)$dayReport['deleted'];
            }

            $wouldArchive += $rowCount;
            $wouldDelete += $rowCount;
            $dayReports[] = $dayReport;
        }

        foreach ($partition['skipped'] as $skipped) {
            $skipped['candidate_rows'] = 0;
            $skipped['would_archive'] = 0;
            $skipped['would_delete'] = 0;
            $skipped['archived'] = 0;
            $skipped['deleted'] = 0;
            $skipped['action'] = 'skipped';
            $dayReports[] = $skipped;
        }

        return [
            'website_id' => $normalized['website_id'],
            'hot_days' => $normalized['hot_days'],
            'cutoff' => $normalized['cutoff'],
            'limit' => $normalized['limit'],
            'dry_run' => !$write,
            'eligible_days' => \count($partition['eligible']),
            'skipped_days' => \count($partition['skipped']),
            'candidate_rows' => $candidateRows,
            'would_archive' => $wouldArchive,
            'would_delete' => $wouldDelete,
            'archived' => $archived,
            'deleted' => $deleted,
            'days' => $dayReports,
            'message' => $write
                ? 'retention apply: archived then deleted only job_log=success days before cutoff'
                : 'retention dry-run: no archive writes / no hot deletes',
        ];
    }

    /**
     * 优先加载 success 日桶（可删候选），再附带少量非 success 供跳过报告。
     *
     * @return list<array<string, mixed>>
     */
    private function loadJobLogsForRetention(?int $websiteId, string $cutoffUtc, int $limit): array
    {
        $success = $this->loadJobLogsByStatus($websiteId, $cutoffUtc, PixelStatsJobLog::STATUS_SUCCESS, max($limit, 50));
        $failed = $this->loadJobLogsByStatus($websiteId, $cutoffUtc, PixelStatsJobLog::STATUS_FAILED, 50);
        $other = $this->loadJobLogsByStatus($websiteId, $cutoffUtc, null, 20, [PixelStatsJobLog::STATUS_SUCCESS, PixelStatsJobLog::STATUS_FAILED]);

        return array_merge($success, $failed, $other);
    }

    /**
     * @param list<string>|null $excludeStatuses
     * @return list<array<string, mixed>>
     */
    private function loadJobLogsByStatus(
        ?int $websiteId,
        string $cutoffUtc,
        ?string $status,
        int $limit,
        ?array $excludeStatuses = null
    ): array {
        $table = $this->quoteTable(PixelStatsJobLog::schema_table);
        $sql = 'SELECT * FROM ' . $table
            . ' WHERE ' . $this->qi(PixelStatsJobLog::schema_fields_JOB_TYPE) . ' = :job_type'
            . ' AND ' . $this->qi(PixelStatsJobLog::schema_fields_BUCKET) . ' < :cutoff';
        $params = [
            'job_type' => PixelStatsJobLog::JOB_DAILY,
            'cutoff' => $cutoffUtc,
        ];
        if ($status !== null) {
            $sql .= ' AND ' . $this->qi(PixelStatsJobLog::schema_fields_STATUS) . ' = :status';
            $params['status'] = $status;
        }
        if ($excludeStatuses !== null && $excludeStatuses !== []) {
            $parts = [];
            foreach (array_values($excludeStatuses) as $i => $ex) {
                $key = 'ex' . $i;
                $parts[] = ':' . $key;
                $params[$key] = $ex;
            }
            $sql .= ' AND ' . $this->qi(PixelStatsJobLog::schema_fields_STATUS)
                . ' NOT IN (' . implode(', ', $parts) . ')';
        }
        if ($websiteId !== null) {
            $sql .= ' AND ' . $this->qi(PixelStatsJobLog::schema_fields_WEBSITE_ID) . ' = :website_id';
            $params['website_id'] = $websiteId;
        }
        $sql .= ' ORDER BY ' . $this->qi(PixelStatsJobLog::schema_fields_WEBSITE_ID) . ' ASC,'
            . ' ' . $this->qi(PixelStatsJobLog::schema_fields_BUCKET) . ' ASC'
            . ' LIMIT ' . (int)max(1, $limit);

        return $this->fetchAll($sql, $params);
    }

    /**
     * @param array{website_id:int,start_utc:string,end_utc:string} $day
     * @return list<array<string, mixed>>
     */
    private function loadHotRowsForDay(array $day, string $cutoffUtc, int $limit): array
    {
        $table = $this->quoteTable(Pixel::schema_table);
        $created = 'COALESCE(p.' . $this->qi(Pixel::schema_fields_CREATED_AT) . ', p.' . $this->qi('create_time') . ')';
        $sql = "SELECT p.* FROM {$table} p"
            . ' WHERE p.' . $this->qi(Pixel::schema_fields_WEBSITE_ID) . ' = :website_id'
            . " AND {$created} >= :start_utc"
            . " AND {$created} < :end_utc"
            . " AND {$created} < :cutoff"
            . " ORDER BY {$created} ASC, p." . $this->qi(Pixel::schema_fields_ID) . ' ASC'
            . ' LIMIT ' . (int)$limit;

        return $this->fetchAll($sql, [
            'website_id' => (int)$day['website_id'],
            'start_utc' => (string)$day['start_utc'],
            'end_utc' => (string)$day['end_utc'],
            'cutoff' => $cutoffUtc,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $day
     * @return array<string, mixed>
     */
    private function archiveRows(array $rows, array $day): array
    {
        return $this->archiveMigrate()->migrate(
            [
                'website_id' => (int)$day['website_id'],
                'before' => (string)$day['end_utc'],
                'after' => (string)$day['start_utc'],
                'limit' => max(\count($rows), 1),
                'offset' => 0,
            ],
            static fn (): array => $rows
        );
    }

    /**
     * @param list<int> $pixelIds
     */
    private function deleteHotRows(array $pixelIds): int
    {
        $ids = [];
        foreach ($pixelIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return 0;
        }

        $table = $this->quoteTable(Pixel::schema_table);
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $sql = 'DELETE FROM ' . $table
            . ' WHERE ' . $this->qi(Pixel::schema_fields_ID)
            . ' IN (' . implode(', ', $placeholders) . ')';
        $pdo = $this->pdo();
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . ltrim((string)$key, ':'), $value);
        }
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function normalizeDayBucket(string $bucket): string
    {
        $bucket = trim($bucket);
        if ($bucket === '') {
            return '1970-01-01';
        }

        return substr($bucket, 0, 10);
    }

    private function normalizeTimezone(string $tz): string
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

    private function archiveMigrate(): PixelArchiveMigrateService
    {
        return $this->archiveMigrate ??= new PixelArchiveMigrateService();
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

    private function quoteTable(string $name): string
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

        return $this->qi($prefix . $name);
    }

    private function qi(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
