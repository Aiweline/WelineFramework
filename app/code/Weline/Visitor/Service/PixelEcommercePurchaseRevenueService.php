<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Service\Report\PixelQueryRouter;

/**
 * F02：购成与收入（热表；依赖 F01 字典购买事件集）。
 *
 * - purchases：购买类事件次数（checkout_success / purchase）
 * - purchase_revenue：仅购买类事件的 value 合计（禁止把非购买 value 计入）
 * - avg_order_value：revenue / purchases
 * - purchase_sessions：至少一次购买的会话数
 * - purchase_rate_from_view_item：purchase_sessions / view_item 会话数
 * 另提供按 channel_code / 按日拆分。
 */
class PixelEcommercePurchaseRevenueService
{
    /** @var list<string> */
    public const PURCHASE_EVENTS = PixelEcommerceFunnelService::CHECKOUT_SUCCESS_EVENTS;

    /** @var list<string> */
    public const VIEW_ITEM_EVENTS = PixelEcommerceFunnelService::VIEW_ITEM_EVENTS;

    public function __construct(
        private ?PixelQueryRouter $queryRouter = null,
        private ?PixelEcommerceFunnelService $funnelService = null,
    ) {
    }

    public function isPurchaseEvent(string $event): bool
    {
        return \in_array(strtolower(trim($event)), self::PURCHASE_EVENTS, true);
    }

    public function isViewItemEvent(string $event): bool
    {
        return \in_array(strtolower(trim($event)), self::VIEW_ITEM_EVENTS, true);
    }

    /**
     * 纯聚合（不查库）。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{
     *   purchases: int,
     *   purchase_revenue: float,
     *   avg_order_value: float,
     *   purchase_sessions: int,
     *   view_item_sessions: int,
     *   purchase_rate_from_view_item: float,
     *   non_purchase_value_ignored: float
     * }
     */
    public function aggregateFromRows(array $rows): array
    {
        $purchases = 0;
        $revenue = 0.0;
        $ignored = 0.0;
        $purchaseSessions = [];
        $viewItemSessions = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $event = strtolower(trim((string)($row['event'] ?? $row[Pixel::schema_fields_EVENT] ?? '')));
            $value = (float)($row['value'] ?? $row[Pixel::schema_fields_VALUE] ?? 0);
            $sessionId = trim((string)($row['session_id'] ?? $row[Pixel::schema_fields_SESSION_ID] ?? ''));

            if ($this->isViewItemEvent($event) && $sessionId !== '') {
                $viewItemSessions[$sessionId] = true;
            }

            if ($this->isPurchaseEvent($event)) {
                $purchases++;
                $revenue += $value;
                if ($sessionId !== '') {
                    $purchaseSessions[$sessionId] = true;
                }
                continue;
            }

            if ($value != 0.0) {
                $ignored += $value;
            }
        }

        $viewSessions = \count($viewItemSessions);
        $buySessions = \count($purchaseSessions);
        $aov = $purchases > 0 ? round($revenue / $purchases, 4) : 0.0;
        $rate = $viewSessions > 0 ? round($buySessions / $viewSessions, 4) : 0.0;

        return [
            'purchases' => $purchases,
            'purchase_revenue' => round($revenue, 4),
            'avg_order_value' => $aov,
            'purchase_sessions' => $buySessions,
            'view_item_sessions' => $viewSessions,
            'purchase_rate_from_view_item' => $rate,
            'non_purchase_value_ignored' => round($ignored, 4),
        ];
    }

    /**
     * 按渠道拆分收入（仅购买类）。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<array{
     *   channel_code: string,
     *   purchases: int,
     *   purchase_revenue: float,
     *   avg_order_value: float,
     *   purchase_sessions: int
     * }>
     */
    public function aggregateByChannel(array $rows, int $limit = 20): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $event = (string)($row['event'] ?? '');
            if (!$this->isPurchaseEvent($event)) {
                continue;
            }
            $code = trim((string)($row['channel_code'] ?? ''));
            if ($code === '') {
                $code = '(none)';
            }
            if (!isset($buckets[$code])) {
                $buckets[$code] = [
                    'channel_code' => $code,
                    'purchases' => 0,
                    'purchase_revenue' => 0.0,
                    'sessions' => [],
                ];
            }
            $buckets[$code]['purchases']++;
            $buckets[$code]['purchase_revenue'] += (float)($row['value'] ?? 0);
            $sessionId = trim((string)($row['session_id'] ?? ''));
            if ($sessionId !== '') {
                $buckets[$code]['sessions'][$sessionId] = true;
            }
        }

        $out = [];
        foreach ($buckets as $bucket) {
            $purchases = (int)$bucket['purchases'];
            $revenue = round((float)$bucket['purchase_revenue'], 4);
            $out[] = [
                'channel_code' => (string)$bucket['channel_code'],
                'purchases' => $purchases,
                'purchase_revenue' => $revenue,
                'avg_order_value' => $purchases > 0 ? round($revenue / $purchases, 4) : 0.0,
                'purchase_sessions' => \count($bucket['sessions']),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $cmp = $b['purchase_revenue'] <=> $a['purchase_revenue'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b['purchases'] <=> $a['purchases'];
        });

        return \array_slice($out, 0, max(1, $limit));
    }

    /**
     * 按日拆分收入（仅购买类；日键取 created_at 的 Y-m-d）。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<array{
     *   day: string,
     *   purchases: int,
     *   purchase_revenue: float,
     *   avg_order_value: float,
     *   purchase_sessions: int
     * }>
     */
    public function aggregateByDay(array $rows, int $limit = 31): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $event = (string)($row['event'] ?? '');
            if (!$this->isPurchaseEvent($event)) {
                continue;
            }
            $day = $this->dayKey((string)($row['created_at'] ?? $row['day'] ?? ''));
            if ($day === '') {
                continue;
            }
            if (!isset($buckets[$day])) {
                $buckets[$day] = [
                    'day' => $day,
                    'purchases' => 0,
                    'purchase_revenue' => 0.0,
                    'sessions' => [],
                ];
            }
            $buckets[$day]['purchases']++;
            $buckets[$day]['purchase_revenue'] += (float)($row['value'] ?? 0);
            $sessionId = trim((string)($row['session_id'] ?? ''));
            if ($sessionId !== '') {
                $buckets[$day]['sessions'][$sessionId] = true;
            }
        }

        ksort($buckets);
        $out = [];
        foreach ($buckets as $bucket) {
            $purchases = (int)$bucket['purchases'];
            $revenue = round((float)$bucket['purchase_revenue'], 4);
            $out[] = [
                'day' => (string)$bucket['day'],
                'purchases' => $purchases,
                'purchase_revenue' => $revenue,
                'avg_order_value' => $purchases > 0 ? round($revenue / $purchases, 4) : 0.0,
                'purchase_sessions' => \count($bucket['sessions']),
            ];
        }

        if (\count($out) > $limit) {
            $out = \array_slice($out, -$limit);
        }

        return $out;
    }

    /**
     * @return array{
     *   website_id: int,
     *   from: string,
     *   to: string,
     *   window_clamped: bool,
     *   purchases: int,
     *   purchase_revenue: float,
     *   avg_order_value: float,
     *   purchase_sessions: int,
     *   view_item_sessions: int,
     *   purchase_rate_from_view_item: float,
     *   non_purchase_value_ignored: float,
     *   by_channel: list<array<string, mixed>>,
     *   by_day: list<array<string, mixed>>,
     *   error: string
     * }
     */
    public function buildForWebsite(
        int $websiteId,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?callable $queryRunner = null,
        int $channelLimit = 12,
        int $dayLimit = 31,
    ): array {
        $funnel = $this->getFunnelService();
        $fromDt = $from instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($from)
            : new DateTimeImmutable((string)$from);
        $toDt = $to instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($to)
            : new DateTimeImmutable((string)$to);
        $window = $funnel->clampHotWindow($fromDt, $toDt);

        $totals = $this->aggregateFromRows([]);
        $empty = array_merge($totals, [
            'website_id' => $websiteId,
            'from' => $window['from']->format('Y-m-d H:i:s'),
            'to' => $window['to']->format('Y-m-d H:i:s'),
            'window_clamped' => $window['window_clamped'],
            'by_channel' => [],
            'by_day' => [],
            'error' => '',
        ]);

        if ($websiteId < 0) {
            $empty['error'] = 'invalid website_id';

            return $empty;
        }

        try {
            if ($queryRunner !== null) {
                /** @var array<int, array<string, mixed>> $rows */
                $rows = $queryRunner($websiteId, $window['from'], $window['to']);
            } else {
                $rows = $this->fetchEventRows($websiteId, $window['from'], $window['to']);
            }
        } catch (\Throwable $throwable) {
            $empty['error'] = $throwable->getMessage();

            return $empty;
        }

        $rows = \is_array($rows) ? $rows : [];
        $totals = $this->aggregateFromRows($rows);

        return array_merge($empty, $totals, [
            'by_channel' => $this->aggregateByChannel($rows, $channelLimit),
            'by_day' => $this->aggregateByDay($rows, $dayLimit),
            'error' => '',
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, int|string>}
     */
    public function buildEventRowsSql(
        int $websiteId,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): array {
        $alias = 'p';
        $table = $this->tableSql($alias);
        $eventTime = $this->eventTimeExpression($alias);
        $sessionCol = $this->col(Pixel::schema_fields_SESSION_ID, $alias);
        $eventCol = $this->col(Pixel::schema_fields_EVENT, $alias);
        $valueCol = $this->col(Pixel::schema_fields_VALUE, $alias);
        $channelCol = $this->col(Pixel::schema_fields_CHANNEL_CODE, $alias);
        $websiteCol = $this->col(Pixel::schema_fields_WEBSITE_ID, $alias);

        $interest = array_values(array_unique(array_merge(self::PURCHASE_EVENTS, self::VIEW_ITEM_EVENTS)));
        $interestIn = $this->inList($interest);
        $eventLower = 'LOWER(' . $eventCol . ')';

        $sql = "SELECT
                {$sessionCol} AS session_id,
                {$eventCol} AS event,
                {$valueCol} AS value,
                {$channelCol} AS channel_code,
                {$eventTime} AS created_at
            FROM {$table}
            WHERE {$websiteCol} = :website_id
              AND {$eventTime} >= :start_date
              AND {$eventTime} <= :end_date
              AND {$eventLower} IN ({$interestIn})
            ORDER BY {$eventTime} ASC";

        return [$sql, [
            ':website_id' => $websiteId,
            ':start_date' => $from->format('Y-m-d H:i:s'),
            ':end_date' => $to->format('Y-m-d H:i:s'),
        ]];
    }

    private function dayKey(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
            return $m[1];
        }
        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEventRows(
        int $websiteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        [$sql, $params] = $this->buildEventRowsSql($websiteId, $from, $to);
        $statement = $this->getPdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, \is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $statement->execute();
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return \is_array($rows) ? $rows : [];
    }

    private function getFunnelService(): PixelEcommerceFunnelService
    {
        if (!$this->funnelService) {
            $this->funnelService = new PixelEcommerceFunnelService($this->getQueryRouter());
        }

        return $this->funnelService;
    }

    private function getQueryRouter(): PixelQueryRouter
    {
        if (!$this->queryRouter) {
            $this->queryRouter = ObjectManager::getInstance(PixelQueryRouter::class);
        }

        return $this->queryRouter;
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
        return $this->quoteIdentifier($this->getPixelTableName()) . ' ' . $this->quoteIdentifier($alias);
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
