<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;

/**
 * B11：渠道详情事件轨迹时间线（热表；无漏斗）。
 *
 * 按 channel_code（+ 可选 website_id）取短窗事件，按时间排序后按 session 分组，
 * 保证会话内事件序可见。
 */
class PixelChannelTimelineService
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;
    public const DEFAULT_DAYS = 7;

    public function __construct(
        private ?PixelChannelHotTotalsService $hotTotals = null,
    ) {
    }

    public function normalizeLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return \min(self::MAX_LIMIT, $limit);
    }

    public function normalizeDays(int $days): int
    {
        return \in_array($days, PixelChannelHotTotalsService::WINDOW_DAYS, true)
            ? $days
            : self::DEFAULT_DAYS;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   pixel_id: int,
     *   event: string,
     *   url: string,
     *   session_id: string,
     *   ip: string,
     *   value: float,
     *   traffic_type: string,
     *   utm_source: string,
     *   utm_medium: string,
     *   utm_campaign: string,
     *   created_at: string
     * }
     */
    public function normalizeEvent(array $row): array
    {
        $created = trim((string)($row['created_at'] ?? $row[Pixel::schema_fields_CREATED_AT] ?? $row['create_time'] ?? ''));

        return [
            'pixel_id' => (int)($row['pixel_id'] ?? $row[Pixel::schema_fields_ID] ?? 0),
            'event' => trim((string)($row['event'] ?? $row[Pixel::schema_fields_EVENT] ?? '')),
            'url' => trim((string)($row['url'] ?? $row[Pixel::schema_fields_URL] ?? '')),
            'session_id' => trim((string)($row['session_id'] ?? $row[Pixel::schema_fields_SESSION_ID] ?? '')),
            'ip' => trim((string)($row['ip'] ?? $row[Pixel::schema_fields_IP] ?? '')),
            'value' => round((float)($row['value'] ?? $row[Pixel::schema_fields_VALUE] ?? 0), 4),
            'traffic_type' => trim((string)($row['traffic_type'] ?? $row[Pixel::schema_fields_TRAFFIC_TYPE] ?? '')),
            'utm_source' => trim((string)($row['utm_source'] ?? $row[Pixel::schema_fields_UTM_SOURCE] ?? '')),
            'utm_medium' => trim((string)($row['utm_medium'] ?? $row[Pixel::schema_fields_UTM_MEDIUM] ?? '')),
            'utm_campaign' => trim((string)($row['utm_campaign'] ?? $row[Pixel::schema_fields_UTM_CAMPAIGN] ?? '')),
            'created_at' => $created,
        ];
    }

    /**
     * 升序保证事件序；同秒用 pixel_id 稳定排序。
     *
     * @param array<int, array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public function sortByEventTimeAsc(array $events): array
    {
        $normalized = [];
        foreach ($events as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $normalized[] = $this->normalizeEvent($row);
        }
        usort($normalized, static function (array $a, array $b): int {
            $cmp = strcmp((string)$a['created_at'], (string)$b['created_at']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int)$a['pixel_id']) <=> ((int)$b['pixel_id']);
        });

        return $normalized;
    }

    /**
     * 按 session 分组：会话按首事件时间倒序（新会话在上），会话内保持升序事件序。
     *
     * @param array<int, array<string, mixed>> $events
     * @return list<array{session_id: string, started_at: string, ended_at: string, event_count: int, events: list<array<string, mixed>>}>
     */
    public function groupBySession(array $events): array
    {
        $ordered = $this->sortByEventTimeAsc($events);
        $buckets = [];
        $order = [];

        foreach ($ordered as $event) {
            $sessionId = (string)$event['session_id'];
            $key = $sessionId !== '' ? $sessionId : '__none__:' . (string)$event['pixel_id'];
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'session_id' => $sessionId,
                    'started_at' => (string)$event['created_at'],
                    'ended_at' => (string)$event['created_at'],
                    'event_count' => 0,
                    'events' => [],
                ];
                $order[] = $key;
            }
            $buckets[$key]['events'][] = $event;
            $buckets[$key]['event_count']++;
            $buckets[$key]['ended_at'] = (string)$event['created_at'];
        }

        // 新会话在上：按 started_at DESC，同时间按 key 稳定
        usort($order, static function (string $a, string $b) use ($buckets): int {
            $cmp = strcmp((string)$buckets[$b]['started_at'], (string)$buckets[$a]['started_at']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a, $b);
        });

        $sessions = [];
        foreach ($order as $key) {
            $sessions[] = $buckets[$key];
        }

        return $sessions;
    }

    /**
     * @param array<string, mixed> $channel
     * @return array{
     *   channel_code: string,
     *   website_id: int|null,
     *   days: int,
     *   limit: int,
     *   start_date: string,
     *   end_date: string,
     *   events: list<array<string, mixed>>,
     *   sessions: list<array<string, mixed>>,
     *   event_count: int,
     *   session_count: int,
     *   error: string
     * }
     */
    public function buildForChannel(
        array $channel,
        int $days = self::DEFAULT_DAYS,
        int $limit = self::DEFAULT_LIMIT,
        ?callable $queryRunner = null
    ): array {
        $code = trim((string)($channel['code'] ?? ''));
        $websiteId = (int)($channel['website_id'] ?? 0);
        $siteFilter = $websiteId > 0 ? $websiteId : null;
        $days = $this->normalizeDays($days);
        $limit = $this->normalizeLimit($limit);
        $window = $this->getHotTotals()->resolveWindow($days);

        $empty = [
            'channel_code' => $code,
            'website_id' => $siteFilter,
            'days' => $days,
            'limit' => $limit,
            'start_date' => (string)$window['start_date'],
            'end_date' => (string)$window['end_date'],
            'events' => [],
            'sessions' => [],
            'event_count' => 0,
            'session_count' => 0,
            'error' => '',
        ];

        if ($code === '') {
            return $empty;
        }

        try {
            if ($queryRunner !== null) {
                /** @var array<int, array<string, mixed>> $rows */
                $rows = $queryRunner($code, $siteFilter, $window, $limit);
            } else {
                $rows = $this->fetchTimelineRows($code, $siteFilter, $window, $limit);
            }
        } catch (\Throwable $throwable) {
            $empty['error'] = $throwable->getMessage();

            return $empty;
        }

        $events = $this->sortByEventTimeAsc(\is_array($rows) ? $rows : []);
        $sessions = $this->groupBySession($events);

        return [
            'channel_code' => $code,
            'website_id' => $siteFilter,
            'days' => $days,
            'limit' => $limit,
            'start_date' => (string)$window['start_date'],
            'end_date' => (string)$window['end_date'],
            'events' => $events,
            'sessions' => $sessions,
            'event_count' => \count($events),
            'session_count' => \count($sessions),
            'error' => '',
        ];
    }

    /**
     * @param array{days: int, start_date: string, end_date: string} $window
     * @return array{0: string, 1: array<string, int|string>}
     */
    public function buildTimelineSql(string $channelCode, ?int $websiteId, array $window, int $limit): array
    {
        $alias = 'p';
        $table = $this->tableSql($alias);
        $eventTime = $this->eventTimeExpression($alias);
        $channelCol = $this->col(Pixel::schema_fields_CHANNEL_CODE, $alias);
        $limit = $this->normalizeLimit($limit);

        $clauses = [
            "{$channelCol} = :channel_code",
            "{$eventTime} >= :start_date",
            "{$eventTime} <= :end_date",
        ];
        $params = [
            ':channel_code' => $channelCode,
            ':start_date' => (string)$window['start_date'],
            ':end_date' => (string)$window['end_date'],
        ];
        if ($websiteId !== null && $websiteId > 0) {
            $clauses[] = $this->col(Pixel::schema_fields_WEBSITE_ID, $alias) . ' = :website_id';
            $params[':website_id'] = $websiteId;
        }

        $pixelId = $this->col(Pixel::schema_fields_ID, $alias);
        $sql = "SELECT
                {$pixelId} AS pixel_id,
                {$this->col(Pixel::schema_fields_EVENT, $alias)} AS event,
                {$this->col(Pixel::schema_fields_URL, $alias)} AS url,
                {$this->col(Pixel::schema_fields_SESSION_ID, $alias)} AS session_id,
                {$this->col(Pixel::schema_fields_IP, $alias)} AS ip,
                {$this->col(Pixel::schema_fields_VALUE, $alias)} AS value,
                {$this->col(Pixel::schema_fields_TRAFFIC_TYPE, $alias)} AS traffic_type,
                {$this->col(Pixel::schema_fields_UTM_SOURCE, $alias)} AS utm_source,
                {$this->col(Pixel::schema_fields_UTM_MEDIUM, $alias)} AS utm_medium,
                {$this->col(Pixel::schema_fields_UTM_CAMPAIGN, $alias)} AS utm_campaign,
                {$eventTime} AS created_at
            FROM {$table}
            WHERE " . implode(' AND ', $clauses) . "
            ORDER BY {$eventTime} ASC, {$pixelId} ASC
            LIMIT {$limit}";

        return [$sql, $params];
    }

    /**
     * @param array{days: int, start_date: string, end_date: string} $window
     * @return array<int, array<string, mixed>>
     */
    private function fetchTimelineRows(string $channelCode, ?int $websiteId, array $window, int $limit): array
    {
        [$sql, $params] = $this->buildTimelineSql($channelCode, $websiteId, $window, $limit);
        $statement = $this->getPdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, \is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $statement->execute();
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return \is_array($rows) ? $rows : [];
    }

    private function getHotTotals(): PixelChannelHotTotalsService
    {
        if (!$this->hotTotals) {
            $this->hotTotals = ObjectManager::getInstance(PixelChannelHotTotalsService::class);
        }

        return $this->hotTotals;
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
        } catch (\Throwable $throwable) {
            return Pixel::schema_table;
        }
    }

    private function getPdoDriver(): string
    {
        try {
            return strtolower((string)$this->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $throwable) {
            return 'mysql';
        }
    }

    private function getPdo(): \PDO
    {
        return ObjectManager::getInstance(Pixel::class)->getConnection()->getConnector()->getLink();
    }
}
