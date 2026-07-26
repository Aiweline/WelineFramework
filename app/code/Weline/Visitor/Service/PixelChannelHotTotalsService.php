<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;

/**
 * B10：渠道详情热表总计（仅 7/30 天；无轨迹、不对接 list）。
 *
 * 口径：按 `channel_code` 过滤热表 `w_pixel`；有站点绑定则再限 website_id。
 * Done：events 与同条件 COUNT(*) 一致。
 */
class PixelChannelHotTotalsService
{
    public const WINDOW_DAYS = [7, 30];

    /** @var list<string> */
    public const PAGE_VIEW_EVENTS = ['page_view', 'page_enter'];

    /** @var list<string> */
    public const INTERACTION_EVENTS = ['cta_click', 'contact_click', 'route_click', 'lead_submit'];

    /** @var list<string> */
    public const ADD_TO_CART_EVENTS = ['add_to_cart'];

    /** @var list<string> */
    public const CONVERSION_EVENTS = ['checkout_success', 'purchase', 'lead_submit'];

    /**
     * @return array{days: int, start_date: string, end_date: string}
     */
    public function resolveWindow(int $days): array
    {
        $days = \in_array($days, self::WINDOW_DAYS, true) ? $days : 7;
        $end = new \DateTimeImmutable('now');
        $start = $end->modify('-' . ($days - 1) . ' days')->setTime(0, 0, 0);

        return [
            'days' => $days,
            'start_date' => $start->format('Y-m-d H:i:s'),
            'end_date' => $end->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 纯聚合：供单测验证与热表 COUNT 口径一致（不查库）。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int|float>
     */
    public function aggregateFromRows(array $rows): array
    {
        $events = 0;
        $sessions = [];
        $users = [];
        $valueSum = 0.0;
        $pageViews = 0;
        $interactions = 0;
        $addToCarts = 0;
        $conversions = 0;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $events++;
            $sessionId = trim((string)($row[Pixel::schema_fields_SESSION_ID] ?? $row['session_id'] ?? ''));
            if ($sessionId !== '') {
                $sessions[$sessionId] = true;
            }
            $ip = trim((string)($row[Pixel::schema_fields_IP] ?? $row['ip'] ?? ''));
            if ($ip !== '') {
                $users[$ip] = true;
            }
            $valueSum += (float)($row[Pixel::schema_fields_VALUE] ?? $row['value'] ?? 0);
            $event = strtolower(trim((string)($row[Pixel::schema_fields_EVENT] ?? $row['event'] ?? '')));
            if (\in_array($event, self::PAGE_VIEW_EVENTS, true)) {
                $pageViews++;
            }
            if (\in_array($event, self::INTERACTION_EVENTS, true)) {
                $interactions++;
            }
            if (\in_array($event, self::ADD_TO_CART_EVENTS, true)) {
                $addToCarts++;
            }
            if (\in_array($event, self::CONVERSION_EVENTS, true)) {
                $conversions++;
            }
        }

        return $this->normalizeTotals([
            'events' => $events,
            'sessions' => \count($sessions),
            'users' => \count($users),
            'value_sum' => $valueSum,
            'page_views' => $pageViews,
            'interactions' => $interactions,
            'add_to_carts' => $addToCarts,
            'conversions' => $conversions,
        ]);
    }

    /**
     * @param array<string, mixed> $channel pixel_channel 行
     * @return array{
     *   channel_code: string,
     *   website_id: int|null,
     *   windows: array<int, array<string, mixed>>,
     *   error: string
     * }
     */
    public function buildForChannel(array $channel, ?callable $queryRunner = null): array
    {
        $code = trim((string)($channel['code'] ?? ''));
        $websiteId = (int)($channel['website_id'] ?? 0);
        $siteFilter = $websiteId > 0 ? $websiteId : null;
        $windows = [];
        $error = '';

        foreach (self::WINDOW_DAYS as $days) {
            try {
                $windows[$days] = $this->queryHotTotals($code, $siteFilter, $days, $queryRunner);
            } catch (\Throwable $throwable) {
                $error = $throwable->getMessage();
                $windows[$days] = $this->emptyWindow($days);
            }
        }

        return [
            'channel_code' => $code,
            'website_id' => $siteFilter,
            'windows' => $windows,
            'error' => $error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queryHotTotals(
        string $channelCode,
        ?int $websiteId,
        int $days,
        ?callable $queryRunner = null
    ): array {
        $channelCode = trim($channelCode);
        $window = $this->resolveWindow($days);
        if ($channelCode === '') {
            return $this->emptyWindow($days);
        }

        if ($queryRunner !== null) {
            /** @var array<string, mixed> $raw */
            $raw = $queryRunner($channelCode, $websiteId, $window);

            return $this->mergeWindowMeta($this->normalizeTotals($raw), $window);
        }

        $raw = $this->fetchSqlTotals($channelCode, $websiteId, $window);

        return $this->mergeWindowMeta($this->normalizeTotals($raw), $window);
    }

    /**
     * 独立 COUNT(*)，供验收「与热表 COUNT 一致」。
     */
    public function countHotEvents(string $channelCode, ?int $websiteId, int $days): int
    {
        $channelCode = trim($channelCode);
        if ($channelCode === '') {
            return 0;
        }
        $window = $this->resolveWindow($days);
        [$sql, $params] = $this->buildCountSql($channelCode, $websiteId, $window);
        $row = $this->fetchOne($sql, $params);

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * @return array{0: string, 1: array<string, int|string>}
     */
    public function buildTotalsSql(string $channelCode, ?int $websiteId, array $window): array
    {
        $alias = 'p';
        $table = $this->tableSql($alias);
        $eventTime = $this->eventTimeExpression($alias);
        $channelCol = $this->col(Pixel::schema_fields_CHANNEL_CODE, $alias);
        $sessionCol = $this->col(Pixel::schema_fields_SESSION_ID, $alias);
        $ipCol = $this->col(Pixel::schema_fields_IP, $alias);
        $valueCol = $this->col(Pixel::schema_fields_VALUE, $alias);
        $eventCol = $this->col(Pixel::schema_fields_EVENT, $alias);

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

        $pageIn = $this->inList(self::PAGE_VIEW_EVENTS);
        $interactionIn = $this->inList(self::INTERACTION_EVENTS);
        $cartIn = $this->inList(self::ADD_TO_CART_EVENTS);
        $conversionIn = $this->inList(self::CONVERSION_EVENTS);

        $sql = "SELECT
                COUNT(*) AS events,
                COUNT(DISTINCT NULLIF({$sessionCol}, '')) AS sessions,
                COUNT(DISTINCT NULLIF({$ipCol}, '')) AS users,
                COALESCE(SUM({$valueCol}), 0) AS value_sum,
                SUM(CASE WHEN LOWER({$eventCol}) IN ({$pageIn}) THEN 1 ELSE 0 END) AS page_views,
                SUM(CASE WHEN LOWER({$eventCol}) IN ({$interactionIn}) THEN 1 ELSE 0 END) AS interactions,
                SUM(CASE WHEN LOWER({$eventCol}) IN ({$cartIn}) THEN 1 ELSE 0 END) AS add_to_carts,
                SUM(CASE WHEN LOWER({$eventCol}) IN ({$conversionIn}) THEN 1 ELSE 0 END) AS conversions
            FROM {$table}
            WHERE " . implode(' AND ', $clauses);

        return [$sql, $params];
    }

    /**
     * @return array{0: string, 1: array<string, int|string>}
     */
    public function buildCountSql(string $channelCode, ?int $websiteId, array $window): array
    {
        $alias = 'p';
        $table = $this->tableSql($alias);
        $eventTime = $this->eventTimeExpression($alias);
        $channelCol = $this->col(Pixel::schema_fields_CHANNEL_CODE, $alias);
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

        $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE " . implode(' AND ', $clauses);

        return [$sql, $params];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, int|float>
     */
    public function normalizeTotals(array $raw): array
    {
        return [
            'events' => (int)($raw['events'] ?? 0),
            'sessions' => (int)($raw['sessions'] ?? 0),
            'users' => (int)($raw['users'] ?? 0),
            'value_sum' => round((float)($raw['value_sum'] ?? 0), 4),
            'page_views' => (int)($raw['page_views'] ?? 0),
            'interactions' => (int)($raw['interactions'] ?? 0),
            'add_to_carts' => (int)($raw['add_to_carts'] ?? 0),
            'conversions' => (int)($raw['conversions'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyWindow(int $days): array
    {
        return $this->mergeWindowMeta($this->normalizeTotals([]), $this->resolveWindow($days));
    }

    /**
     * @param array<string, int|float> $totals
     * @param array{days: int, start_date: string, end_date: string} $window
     * @return array<string, mixed>
     */
    private function mergeWindowMeta(array $totals, array $window): array
    {
        return array_merge($totals, [
            'days' => (int)$window['days'],
            'start_date' => (string)$window['start_date'],
            'end_date' => (string)$window['end_date'],
        ]);
    }

    /**
     * @param array{days: int, start_date: string, end_date: string} $window
     * @return array<string, mixed>
     */
    private function fetchSqlTotals(string $channelCode, ?int $websiteId, array $window): array
    {
        [$sql, $params] = $this->buildTotalsSql($channelCode, $websiteId, $window);

        return $this->fetchOne($sql, $params);
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
        } catch (\Throwable $throwable) {
            return Pixel::schema_table;
        }
    }

    private function getPdoDriver(): string
    {
        try {
            $driver = (string)$this->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

            return strtolower($driver);
        } catch (\Throwable $throwable) {
            return 'mysql';
        }
    }

    /**
     * @param array<string, int|string> $params
     * @return array<string, mixed>
     */
    private function fetchOne(string $sql, array $params = []): array
    {
        $statement = $this->getPdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, \is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return \is_array($row) ? $row : [];
    }

    private function getPdo(): \PDO
    {
        return ObjectManager::getInstance(Pixel::class)->getConnection()->getConnector()->getLink();
    }
}
