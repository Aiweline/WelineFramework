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
 * F04a：路径探索（简版；热表；全站报表）。
 *
 * 口径：
 * - 仅取页面事件（page_view / page_enter），按会话时间升序取页面序列；
 * - 相邻重复路径折叠；每会话限深 MAX_DEPTH（简版=3）步；
 * - 输出 Top 落地页 → 次页分布（含离站数）与 Top 限深路径序列；
 * - 路径规范化复用 D00 PixelLandingDeviceDerivation::normalizePagePath；
 * - 扫描行数封顶 MAX_SCAN_ROWS，防热表大窗拖垮后台。
 *
 * 留存分析属 F04b，不在本类。
 */
class PixelPathExplorationService
{
    /** @var list<string> 页面事件（与 D00 LANDING_EVENTS 对齐） */
    public const PAGE_EVENTS = ['page_view', 'page_enter'];

    /** 简版限深：落地 → 第 2 页 → 第 3 页 */
    public const MAX_DEPTH = 3;

    public const DEFAULT_TOP_LANDINGS = 10;
    public const DEFAULT_TOP_NEXT = 5;
    public const DEFAULT_TOP_PATHS = 10;

    /** 热表扫描保护上限 */
    public const MAX_SCAN_ROWS = 20000;

    public const PATH_SEPARATOR = ' → ';

    public function __construct(
        private ?PixelQueryRouter $queryRouter = null,
        private ?PixelLandingDeviceDerivation $derivation = null,
    ) {
    }

    public function isPageEvent(string $event): bool
    {
        return \in_array(strtolower(trim($event)), self::PAGE_EVENTS, true);
    }

    /**
     * 行集 → 会话限深路径序列（相邻重复折叠）。
     *
     * @param array<int, array<string, mixed>> $rows
     *   每行可含 session_id / event / page_path / path / url / created_at / pixel_id
     * @return array<string, list<string>> session_id → 限深路径列表
     */
    public function sessionPathsFromRows(array $rows, int $maxDepth = self::MAX_DEPTH): array
    {
        $maxDepth = max(1, $maxDepth);
        $grouped = [];
        foreach ($rows as $i => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $sessionId = trim((string)($row['session_id'] ?? $row[Pixel::schema_fields_SESSION_ID] ?? ''));
            if ($sessionId === '') {
                continue;
            }
            $event = (string)($row['event'] ?? $row[Pixel::schema_fields_EVENT] ?? '');
            if ($event !== '' && !$this->isPageEvent($event)) {
                continue;
            }
            $path = $this->pathFromRow($row);
            if ($path === '') {
                continue;
            }
            $grouped[$sessionId][] = [
                'i' => $i,
                'at' => $this->rowSortKey($row),
                'path' => $path,
            ];
        }

        $paths = [];
        foreach ($grouped as $sessionId => $items) {
            usort($items, static function (array $a, array $b): int {
                if ($a['at'] === $b['at']) {
                    return $a['i'] <=> $b['i'];
                }

                return $a['at'] <=> $b['at'];
            });

            $sequence = [];
            foreach ($items as $item) {
                $last = $sequence === [] ? null : $sequence[\count($sequence) - 1];
                if ($item['path'] === $last) {
                    continue;
                }
                $sequence[] = $item['path'];
                if (\count($sequence) >= $maxDepth) {
                    break;
                }
            }
            if ($sequence !== []) {
                $paths[$sessionId] = $sequence;
            }
        }

        return $paths;
    }

    /**
     * 会话路径 → Top 落地/次页 + Top 路径序列聚合。
     *
     * @param array<string, list<string>> $sessionPaths
     * @return array{
     *   total_sessions: int,
     *   bounced_sessions: int,
     *   landings: list<array{path: string, sessions: int, exits: int, next: list<array{path: string, sessions: int, rate: float}>}>,
     *   top_paths: list<array{path: string, steps: list<string>, sessions: int}>,
     *   max_depth: int
     * }
     */
    public function computeFromSessionPaths(
        array $sessionPaths,
        int $topLandings = self::DEFAULT_TOP_LANDINGS,
        int $topNext = self::DEFAULT_TOP_NEXT,
        int $topPaths = self::DEFAULT_TOP_PATHS,
    ): array {
        $total = 0;
        $bounced = 0;
        $landingSessions = [];
        $landingExits = [];
        $landingNext = [];
        $sequenceCounts = [];
        $sequenceSteps = [];

        foreach ($sessionPaths as $steps) {
            if (!\is_array($steps) || $steps === []) {
                continue;
            }
            $steps = array_values(array_map(static fn($step): string => (string)$step, $steps));
            $total++;

            $landing = $steps[0];
            $landingSessions[$landing] = ($landingSessions[$landing] ?? 0) + 1;

            if (\count($steps) === 1) {
                $bounced++;
                $landingExits[$landing] = ($landingExits[$landing] ?? 0) + 1;
            } else {
                $next = $steps[1];
                $landingNext[$landing][$next] = ($landingNext[$landing][$next] ?? 0) + 1;
            }

            $sequenceKey = implode(self::PATH_SEPARATOR, $steps);
            $sequenceCounts[$sequenceKey] = ($sequenceCounts[$sequenceKey] ?? 0) + 1;
            $sequenceSteps[$sequenceKey] = $steps;
        }

        arsort($landingSessions);
        $landings = [];
        foreach (\array_slice($landingSessions, 0, max(1, $topLandings), true) as $landing => $sessions) {
            $nextCounts = $landingNext[$landing] ?? [];
            arsort($nextCounts);
            $next = [];
            foreach (\array_slice($nextCounts, 0, max(1, $topNext), true) as $nextPath => $nextSessions) {
                $next[] = [
                    'path' => (string)$nextPath,
                    'sessions' => (int)$nextSessions,
                    'rate' => $sessions > 0 ? round($nextSessions / $sessions, 4) : 0.0,
                ];
            }
            $landings[] = [
                'path' => (string)$landing,
                'sessions' => (int)$sessions,
                'exits' => (int)($landingExits[$landing] ?? 0),
                'next' => $next,
            ];
        }

        arsort($sequenceCounts);
        $paths = [];
        foreach (\array_slice($sequenceCounts, 0, max(1, $topPaths), true) as $sequenceKey => $sessions) {
            $paths[] = [
                'path' => (string)$sequenceKey,
                'steps' => $sequenceSteps[$sequenceKey] ?? [],
                'sessions' => (int)$sessions,
            ];
        }

        return [
            'total_sessions' => $total,
            'bounced_sessions' => $bounced,
            'landings' => $landings,
            'top_paths' => $paths,
            'max_depth' => self::MAX_DEPTH,
        ];
    }

    /**
     * 将筛选窗钳到热短窗（默认 ≤7 天；同 F01 口径）。
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
     * 全站（按 website_id）热表路径探索。
     *
     * @return array{
     *   website_id: int,
     *   from: string,
     *   to: string,
     *   window_clamped: bool,
     *   total_sessions: int,
     *   bounced_sessions: int,
     *   landings: list<array<string, mixed>>,
     *   top_paths: list<array<string, mixed>>,
     *   max_depth: int,
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
        $empty = [
            'website_id' => $websiteId,
            'from' => $window['from']->format('Y-m-d H:i:s'),
            'to' => $window['to']->format('Y-m-d H:i:s'),
            'window_clamped' => $window['window_clamped'],
            'total_sessions' => 0,
            'bounced_sessions' => 0,
            'landings' => [],
            'top_paths' => [],
            'max_depth' => self::MAX_DEPTH,
            'error' => '',
        ];

        if ($websiteId < 0) {
            $empty['error'] = 'invalid website_id';

            return $empty;
        }

        try {
            if ($queryRunner !== null) {
                /** @var array<int, array<string, mixed>> $rows */
                $rows = $queryRunner($websiteId, $window['from'], $window['to']);
            } else {
                $rows = $this->fetchPageEventRows($websiteId, $window['from'], $window['to']);
            }
        } catch (\Throwable $throwable) {
            $empty['error'] = $throwable->getMessage();

            return $empty;
        }

        $sessionPaths = $this->sessionPathsFromRows(\is_array($rows) ? $rows : []);
        $computed = $this->computeFromSessionPaths($sessionPaths);

        return array_merge($empty, $computed, ['error' => '']);
    }

    /**
     * @return array{0: string, 1: array<string, int|string>}
     */
    public function buildPageEventSql(
        int $websiteId,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): array {
        $alias = 'p';
        $table = $this->tableSql($alias);
        $eventTime = $this->eventTimeExpression($alias);
        $idCol = $this->col(Pixel::schema_fields_ID, $alias);
        $sessionCol = $this->col(Pixel::schema_fields_SESSION_ID, $alias);
        $eventCol = $this->col(Pixel::schema_fields_EVENT, $alias);
        $urlCol = $this->col(Pixel::schema_fields_URL, $alias);
        $websiteCol = $this->col(Pixel::schema_fields_WEBSITE_ID, $alias);

        $clauses = [
            "{$websiteCol} = :website_id",
            "{$eventTime} >= :start_date",
            "{$eventTime} <= :end_date",
            "NULLIF({$sessionCol}, '') IS NOT NULL",
            'LOWER(' . $eventCol . ') IN (' . $this->inList(self::PAGE_EVENTS) . ')',
        ];
        $params = [
            ':website_id' => $websiteId,
            ':start_date' => $from->format('Y-m-d H:i:s'),
            ':end_date' => $to->format('Y-m-d H:i:s'),
        ];

        $sql = "SELECT
                {$sessionCol} AS session_id,
                {$eventCol} AS event,
                {$urlCol} AS url,
                {$eventTime} AS created_at,
                {$idCol} AS pixel_id
            FROM {$table}
            WHERE " . implode(' AND ', $clauses) . "
            ORDER BY {$sessionCol} ASC, {$eventTime} ASC, {$idCol} ASC
            LIMIT " . self::MAX_SCAN_ROWS;

        return [$sql, $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function pathFromRow(array $row): string
    {
        foreach (['page_path', 'path'] as $key) {
            $raw = trim((string)($row[$key] ?? ''));
            if ($raw !== '') {
                return $this->getDerivation()->normalizePagePath($raw);
            }
        }

        $url = trim((string)($row['url'] ?? $row[Pixel::schema_fields_URL] ?? ''));
        if ($url !== '') {
            return $this->getDerivation()->normalizePagePath($url);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowSortKey(array $row): string
    {
        $at = trim((string)($row['created_at'] ?? $row['create_time'] ?? ''));
        if ($at !== '') {
            return $at;
        }

        return sprintf('%020d', (int)($row['pixel_id'] ?? $row['id'] ?? 0));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPageEventRows(
        int $websiteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        [$sql, $params] = $this->buildPageEventSql($websiteId, $from, $to);
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

    private function getDerivation(): PixelLandingDeviceDerivation
    {
        if (!$this->derivation) {
            $this->derivation = new PixelLandingDeviceDerivation();
        }

        return $this->derivation;
    }

    /**
     * @param list<string> $values
     */
    private function inList(array $values): string
    {
        $quoted = array_map(static function (string $value): string {
            return "'" . str_replace("'", "''", strtolower($value)) . "'";
        }, $values);

        return implode(', ', $quoted);
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
