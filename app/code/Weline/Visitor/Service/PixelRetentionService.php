<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Service\Report\PixelQueryRouter;

/**
 * F04b：留存分析（简版；热表；全站报表）。
 *
 * 口径（对齐 GA4 日队列简版）：
 * - 访客键：user_id>0 → `u:{id}`；否则 `a:{sha256(ip|ua)[:32]}` 软指纹（匿名兜底，非跨设备 User-ID）；
 * - 队列日 = 窗口内该访客首次活动日；Day0 必为 100%；
 * - 偏移 DayN 仅在 `队列日+N` 仍落在筛选窗内时计入分母（可观测）；
 * - 热短窗默认 ≤7 天 → 最多展示 Day0–Day6；扫描行封顶 MAX_SCAN_ROWS。
 *
 * 路径探索属 F04a，不在本类。
 */
class PixelRetentionService
{
    /** 与热短窗对齐的最大偏移（Day0..Day6） */
    public const MAX_OFFSET = 6;

    public const DEFAULT_TOP_COHORTS = 14;

    /** 热表扫描保护上限 */
    public const MAX_SCAN_ROWS = 20000;

    public function __construct(
        private ?PixelQueryRouter $queryRouter = null,
    ) {
    }

    /**
     * 解析访客键：登录用户优先，否则 IP+UA 软指纹。
     *
     * @param array<string, mixed> $row
     */
    public function resolveVisitorKey(array $row): string
    {
        $userId = (int)($row['user_id'] ?? $row[Pixel::schema_fields_USER_ID] ?? 0);
        if ($userId > 0) {
            return 'u:' . $userId;
        }

        $ip = trim((string)($row['ip'] ?? $row[Pixel::schema_fields_IP] ?? ''));
        $ua = trim((string)($row['user_agent'] ?? $row[Pixel::schema_fields_USER_AGENT] ?? ''));
        if ($ip === '' && $ua === '') {
            return '';
        }

        return 'a:' . substr(hash('sha256', $ip . "\0" . $ua), 0, 32);
    }

    /**
     * 行集 → 访客活动日集合（Y-m-d）。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, list<string>> visitor_key → 升序去重活动日
     */
    public function visitorActivityDaysFromRows(array $rows): array
    {
        $days = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $key = $this->resolveVisitorKey($row);
            if ($key === '') {
                continue;
            }
            $day = $this->activityDayFromRow($row);
            if ($day === '') {
                continue;
            }
            $days[$key][$day] = true;
        }

        $normalized = [];
        foreach ($days as $key => $set) {
            $list = array_keys($set);
            sort($list);
            $normalized[$key] = $list;
        }

        return $normalized;
    }

    /**
     * 访客活动日 → 队列矩阵与汇总。
     *
     * @param array<string, list<string>> $visitorDays
     * @return array{
     *   total_visitors: int,
     *   returning_visitors: int,
     *   returning_rate: float,
     *   d1_rate: float,
     *   d1_eligible: int,
     *   d1_retained: int,
     *   offsets: list<int>,
     *   cohorts: list<array{cohort_date: string, size: int, retained: list<int|null>, rates: list<float|null>}>,
     *   offset_summary: list<array{offset: int, eligible: int, retained: int, rate: float}>
     * }
     */
    public function computeFromVisitorDays(
        array $visitorDays,
        DateTimeInterface $windowFrom,
        DateTimeInterface $windowTo,
        int $maxOffset = self::MAX_OFFSET,
        int $topCohorts = self::DEFAULT_TOP_COHORTS,
    ): array {
        $maxOffset = max(0, min(self::MAX_OFFSET, $maxOffset));
        $fromDay = DateTimeImmutable::createFromInterface($windowFrom)->format('Y-m-d');
        $toDay = DateTimeImmutable::createFromInterface($windowTo)->format('Y-m-d');
        $offsets = range(0, $maxOffset);

        $cohortSizes = [];
        $cohortRetained = [];
        $totalVisitors = 0;
        $returning = 0;

        foreach ($visitorDays as $days) {
            if (!\is_array($days) || $days === []) {
                continue;
            }
            $days = array_values(array_filter(array_map('strval', $days), static fn(string $d): bool => $d !== ''));
            if ($days === []) {
                continue;
            }
            sort($days);
            $totalVisitors++;
            if (\count($days) >= 2) {
                $returning++;
            }

            $cohort = $days[0];
            if ($cohort < $fromDay || $cohort > $toDay) {
                continue;
            }
            $cohortSizes[$cohort] = ($cohortSizes[$cohort] ?? 0) + 1;
            if (!isset($cohortRetained[$cohort])) {
                $cohortRetained[$cohort] = array_fill(0, $maxOffset + 1, 0);
            }
            $daySet = array_fill_keys($days, true);
            $cohortDt = new DateTimeImmutable($cohort . ' 00:00:00');
            for ($offset = 0; $offset <= $maxOffset; $offset++) {
                $target = $cohortDt->add(new DateInterval('P' . $offset . 'D'))->format('Y-m-d');
                if ($target > $toDay) {
                    break;
                }
                if (isset($daySet[$target])) {
                    $cohortRetained[$cohort][$offset]++;
                }
            }
        }

        krsort($cohortSizes);
        $cohorts = [];
        foreach (\array_slice($cohortSizes, 0, max(1, $topCohorts), true) as $cohortDate => $size) {
            $retained = [];
            $rates = [];
            $cohortDt = new DateTimeImmutable($cohortDate . ' 00:00:00');
            for ($offset = 0; $offset <= $maxOffset; $offset++) {
                $target = $cohortDt->add(new DateInterval('P' . $offset . 'D'))->format('Y-m-d');
                if ($target > $toDay) {
                    $retained[] = null;
                    $rates[] = null;
                    continue;
                }
                $count = (int)($cohortRetained[$cohortDate][$offset] ?? 0);
                $retained[] = $count;
                $rates[] = $size > 0 ? round($count / $size, 4) : 0.0;
            }
            $cohorts[] = [
                'cohort_date' => (string)$cohortDate,
                'size' => (int)$size,
                'retained' => $retained,
                'rates' => $rates,
            ];
        }

        $offsetSummary = [];
        foreach ($offsets as $offset) {
            $eligible = 0;
            $retainedSum = 0;
            foreach ($cohortSizes as $cohortDate => $size) {
                $target = (new DateTimeImmutable($cohortDate . ' 00:00:00'))
                    ->add(new DateInterval('P' . $offset . 'D'))
                    ->format('Y-m-d');
                if ($target > $toDay) {
                    continue;
                }
                $eligible += (int)$size;
                $retainedSum += (int)($cohortRetained[$cohortDate][$offset] ?? 0);
            }
            $offsetSummary[] = [
                'offset' => $offset,
                'eligible' => $eligible,
                'retained' => $retainedSum,
                'rate' => $eligible > 0 ? round($retainedSum / $eligible, 4) : 0.0,
            ];
        }

        $d1 = $offsetSummary[1] ?? ['eligible' => 0, 'retained' => 0, 'rate' => 0.0];

        return [
            'total_visitors' => $totalVisitors,
            'returning_visitors' => $returning,
            'returning_rate' => $totalVisitors > 0 ? round($returning / $totalVisitors, 4) : 0.0,
            'd1_rate' => (float)$d1['rate'],
            'd1_eligible' => (int)$d1['eligible'],
            'd1_retained' => (int)$d1['retained'],
            'offsets' => $offsets,
            'cohorts' => $cohorts,
            'offset_summary' => $offsetSummary,
        ];
    }

    /**
     * 将筛选窗钳到热短窗（默认 ≤7 天；同 F01/F04a）。
     *
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable, window_clamped: bool}
     */
    public function clampHotWindow(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $fromDt = DateTimeImmutable::createFromInterface($from);
        $toDt = DateTimeImmutable::createFromInterface($to);
        if ($fromDt > $toDt) {
            [$fromDt, $toDt] = [$toDt, $fromDt];
        }

        $hotDays = max(1, $this->getQueryRouter()->getHotWindowDays());
        $spanDays = (int)$fromDt->diff($toDt)->days + 1;
        $clamped = false;
        if ($spanDays > $hotDays) {
            $fromDt = $toDt->sub(new DateInterval('P' . ($hotDays - 1) . 'D'))->setTime(0, 0, 0);
            $clamped = true;
        }

        return [
            'from' => $fromDt,
            'to' => $toDt,
            'window_clamped' => $clamped,
        ];
    }

    /**
     * 全站（按 website_id）热表留存。
     *
     * @return array{
     *   website_id: int,
     *   from: string,
     *   to: string,
     *   window_clamped: bool,
     *   total_visitors: int,
     *   returning_visitors: int,
     *   returning_rate: float,
     *   d1_rate: float,
     *   d1_eligible: int,
     *   d1_retained: int,
     *   offsets: list<int>,
     *   cohorts: list<array<string, mixed>>,
     *   offset_summary: list<array<string, mixed>>,
     *   error: string
     * }
     */
    public function buildForWebsite(
        int $websiteId,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?callable $queryRunner = null,
    ): array {
        $fromDt = $from instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($from)
            : new DateTimeImmutable((string)$from);
        $toDt = $to instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($to)
            : new DateTimeImmutable((string)$to);

        $window = $this->clampHotWindow($fromDt, $toDt);
        $emptyComputed = $this->computeFromVisitorDays([], $window['from'], $window['to']);
        $empty = array_merge([
            'website_id' => $websiteId,
            'from' => $window['from']->format('Y-m-d H:i:s'),
            'to' => $window['to']->format('Y-m-d H:i:s'),
            'window_clamped' => $window['window_clamped'],
            'error' => '',
        ], $emptyComputed);

        if ($websiteId < 0) {
            $empty['error'] = 'invalid website_id';

            return $empty;
        }

        try {
            if ($queryRunner !== null) {
                /** @var array<int, array<string, mixed>> $rows */
                $rows = $queryRunner($websiteId, $window['from'], $window['to']);
            } else {
                $rows = $this->fetchActivityRows($websiteId, $window['from'], $window['to']);
            }
        } catch (\Throwable $throwable) {
            $empty['error'] = $throwable->getMessage();

            return $empty;
        }

        $visitorDays = $this->visitorActivityDaysFromRows(\is_array($rows) ? $rows : []);
        $computed = $this->computeFromVisitorDays($visitorDays, $window['from'], $window['to']);

        return array_merge($empty, $computed, ['error' => '']);
    }

    /**
     * @return array{0: string, 1: array<string, int|string>}
     */
    public function buildActivitySql(
        int $websiteId,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): array {
        $alias = 'p';
        $table = $this->tableSql($alias);
        $eventTime = $this->eventTimeExpression($alias);
        $idCol = $this->col(Pixel::schema_fields_ID, $alias);
        $userCol = $this->col(Pixel::schema_fields_USER_ID, $alias);
        $ipCol = $this->col(Pixel::schema_fields_IP, $alias);
        $uaCol = $this->col(Pixel::schema_fields_USER_AGENT, $alias);
        $websiteCol = $this->col(Pixel::schema_fields_WEBSITE_ID, $alias);

        $clauses = [
            "{$websiteCol} = :website_id",
            "{$eventTime} >= :start_date",
            "{$eventTime} <= :end_date",
        ];
        $params = [
            ':website_id' => $websiteId,
            ':start_date' => $from->format('Y-m-d H:i:s'),
            ':end_date' => $to->format('Y-m-d H:i:s'),
        ];

        $sql = "SELECT
                {$userCol} AS user_id,
                {$ipCol} AS ip,
                {$uaCol} AS user_agent,
                {$eventTime} AS created_at,
                {$idCol} AS pixel_id
            FROM {$table}
            WHERE " . implode(' AND ', $clauses) . "
            ORDER BY {$eventTime} ASC, {$idCol} ASC
            LIMIT " . self::MAX_SCAN_ROWS;

        return [$sql, $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function activityDayFromRow(array $row): string
    {
        if (isset($row['activity_day']) && trim((string)$row['activity_day']) !== '') {
            $raw = trim((string)$row['activity_day']);
            try {
                return (new DateTimeImmutable($raw))->format('Y-m-d');
            } catch (\Throwable) {
                return '';
            }
        }

        $at = trim((string)($row['created_at'] ?? $row['create_time'] ?? ''));
        if ($at === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($at))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchActivityRows(
        int $websiteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        [$sql, $params] = $this->buildActivitySql($websiteId, $from, $to);
        $statement = $this->getPdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, \is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $statement->execute();
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return \is_array($rows) ? $rows : [];
    }

    private function getQueryRouter(): PixelQueryRouter
    {
        if (!$this->queryRouter) {
            $this->queryRouter = ObjectManager::getInstance(PixelQueryRouter::class);
        }

        return $this->queryRouter;
    }

    private function eventTimeExpression(string $alias = 'p'): string
    {
        return 'COALESCE('
            . $this->col(Pixel::schema_fields_CREATED_AT, $alias)
            . ', '
            . $this->col('create_time', $alias)
            . ')';
    }

    private function tableSql(string $alias): string
    {
        $table = $this->quoteIdentifier($this->getPixelTableName());

        return $table . ' ' . $this->quoteIdentifier($alias);
    }

    private function col(string $field, string $alias = 'p'): string
    {
        return $this->quoteIdentifier($alias) . '.' . $this->quoteIdentifier($field);
    }

    private function quoteIdentifier(string $identifier): string
    {
        $quote = $this->getPdoDriver() === 'mysql' ? '`' : '"';
        $escaped = $quote . $quote;
        $parts = explode('.', $identifier);

        return implode('.', array_map(
            static fn(string $part): string => $quote . str_replace($quote, $escaped, $part) . $quote,
            $parts
        ));
    }

    private function getPixelTableName(): string
    {
        try {
            /** @var Pixel $model */
            $model = ObjectManager::getInstance(Pixel::class);

            return (string)$model->getTable();
        } catch (\Throwable) {
            return Pixel::schema_table;
        }
    }

    private function getPdoDriver(): string
    {
        try {
            return strtolower((string)$this->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable) {
            return 'mysql';
        }
    }

    private function getPdo(): \PDO
    {
        return ObjectManager::getInstance(Pixel::class)->getConnection()->getConnector()->getLink();
    }
}
