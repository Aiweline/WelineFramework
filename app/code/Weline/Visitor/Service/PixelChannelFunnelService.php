<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;

/**
 * B12：渠道详情热表简化漏斗（§2.4 四步；按 session 去重步进；非电商字典四步）。
 *
 * 比率：到达步 N 的会话数 / 到达步 1 的会话数。
 * 步进为顺序门槛：到达步 N 须同时满足步 1…N 的事件集合（session 内至少各命中一次）。
 */
class PixelChannelFunnelService
{
    public const DEFAULT_DAYS = 7;

    public const STEP_LANDING = 'landing';
    public const STEP_INTERACTION = 'interaction';
    public const STEP_ADD_TO_CART = 'add_to_cart';
    public const STEP_CONVERSION = 'conversion';

    /** @var list<string> */
    public const STEP_ORDER = [
        self::STEP_LANDING,
        self::STEP_INTERACTION,
        self::STEP_ADD_TO_CART,
        self::STEP_CONVERSION,
    ];

    /** @var array<string, string> */
    public const STEP_LABELS = [
        self::STEP_LANDING => '落地',
        self::STEP_INTERACTION => '互动',
        self::STEP_ADD_TO_CART => '加购',
        self::STEP_CONVERSION => '转化',
    ];

    /** @var list<string> */
    public const LANDING_EVENTS = PixelChannelHotTotalsService::PAGE_VIEW_EVENTS;

    /** @var list<string> */
    public const INTERACTION_EVENTS = PixelChannelHotTotalsService::INTERACTION_EVENTS;

    /** @var list<string> */
    public const ADD_TO_CART_EVENTS = PixelChannelHotTotalsService::ADD_TO_CART_EVENTS;

    /** @var list<string> */
    public const CONVERSION_EVENTS = PixelChannelHotTotalsService::CONVERSION_EVENTS;

    public function __construct(
        private ?PixelChannelHotTotalsService $hotTotals = null,
    ) {
    }

    public function normalizeDays(int $days): int
    {
        return \in_array($days, PixelChannelHotTotalsService::WINDOW_DAYS, true)
            ? $days
            : self::DEFAULT_DAYS;
    }

    /**
     * 事件是否命中某一步（含互动步的 search_* 前缀）。
     */
    public function eventMatchesStep(string $event, string $step): bool
    {
        $event = strtolower(trim($event));
        if ($event === '') {
            return false;
        }

        return match ($step) {
            self::STEP_LANDING => \in_array($event, self::LANDING_EVENTS, true),
            self::STEP_INTERACTION => \in_array($event, self::INTERACTION_EVENTS, true)
                || str_starts_with($event, 'search_'),
            self::STEP_ADD_TO_CART => \in_array($event, self::ADD_TO_CART_EVENTS, true),
            self::STEP_CONVERSION => \in_array($event, self::CONVERSION_EVENTS, true),
            default => false,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<string, array{landing: bool, interaction: bool, add_to_cart: bool, conversion: bool}>
     */
    public function sessionStepFlagsFromEvents(array $events): array
    {
        $flags = [];
        foreach ($events as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $sessionId = trim((string)($row['session_id'] ?? $row[Pixel::schema_fields_SESSION_ID] ?? ''));
            if ($sessionId === '') {
                continue;
            }
            if (!isset($flags[$sessionId])) {
                $flags[$sessionId] = [
                    self::STEP_LANDING => false,
                    self::STEP_INTERACTION => false,
                    self::STEP_ADD_TO_CART => false,
                    self::STEP_CONVERSION => false,
                ];
            }
            $event = (string)($row['event'] ?? $row[Pixel::schema_fields_EVENT] ?? '');
            foreach (self::STEP_ORDER as $step) {
                if ($this->eventMatchesStep($event, $step)) {
                    $flags[$sessionId][$step] = true;
                }
            }
        }

        return $flags;
    }

    /**
     * 从 session→步骤命中表计算顺序漏斗。
     *
     * @param array<string, array<string, bool>> $sessionFlags
     * @return array{
     *   steps: list<array{key: string, label: string, sessions: int, rate_from_step1: float, dropoff_from_prev: float}>,
     *   step1_sessions: int,
     *   scored_sessions: int
     * }
     */
    public function computeFromSessionFlags(array $sessionFlags): array
    {
        $reached = [];
        foreach (self::STEP_ORDER as $step) {
            $reached[$step] = 0;
        }

        $scored = 0;
        foreach ($sessionFlags as $flags) {
            if (!\is_array($flags)) {
                continue;
            }
            $scored++;
            $okSoFar = true;
            foreach (self::STEP_ORDER as $step) {
                if (!$okSoFar || empty($flags[$step])) {
                    $okSoFar = false;
                    continue;
                }
                $reached[$step]++;
            }
        }

        $step1 = (int)$reached[self::STEP_LANDING];
        $steps = [];
        $prev = null;
        foreach (self::STEP_ORDER as $step) {
            $count = (int)$reached[$step];
            $rate = $step1 > 0 ? round($count / $step1, 4) : 0.0;
            $dropoff = 0.0;
            if ($prev !== null && $prev > 0) {
                $dropoff = round(1 - ($count / $prev), 4);
            } elseif ($prev !== null && $prev === 0) {
                $dropoff = 0.0;
            }
            $steps[] = [
                'key' => $step,
                'label' => $this->translateStepLabel($step),
                'sessions' => $count,
                'rate_from_step1' => $rate,
                'dropoff_from_prev' => $dropoff,
            ];
            $prev = $count;
        }

        return [
            'steps' => $steps,
            'step1_sessions' => $step1,
            'scored_sessions' => $scored,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array{steps: list<array<string, mixed>>, step1_sessions: int, scored_sessions: int}
     */
    public function computeFromEvents(array $events): array
    {
        return $this->computeFromSessionFlags($this->sessionStepFlagsFromEvents($events));
    }

    /**
     * @param array<string, mixed> $channel
     * @return array{
     *   channel_code: string,
     *   website_id: int|null,
     *   days: int,
     *   start_date: string,
     *   end_date: string,
     *   steps: list<array<string, mixed>>,
     *   step1_sessions: int,
     *   scored_sessions: int,
     *   error: string
     * }
     */
    public function buildForChannel(
        array $channel,
        int $days = self::DEFAULT_DAYS,
        ?callable $queryRunner = null
    ): array {
        $code = trim((string)($channel['code'] ?? ''));
        $websiteId = (int)($channel['website_id'] ?? 0);
        $siteFilter = $websiteId > 0 ? $websiteId : null;
        $days = $this->normalizeDays($days);
        $window = $this->getHotTotals()->resolveWindow($days);

        $empty = [
            'channel_code' => $code,
            'website_id' => $siteFilter,
            'days' => $days,
            'start_date' => (string)$window['start_date'],
            'end_date' => (string)$window['end_date'],
            'steps' => $this->computeFromSessionFlags([])['steps'],
            'step1_sessions' => 0,
            'scored_sessions' => 0,
            'error' => '',
        ];

        if ($code === '') {
            return $empty;
        }

        try {
            if ($queryRunner !== null) {
                /** @var array<int, array<string, mixed>> $rows */
                $rows = $queryRunner($code, $siteFilter, $window);
            } else {
                $rows = $this->fetchSessionStepRows($code, $siteFilter, $window);
            }
        } catch (\Throwable $throwable) {
            $empty['error'] = $throwable->getMessage();

            return $empty;
        }

        $flags = $this->sessionFlagsFromSqlRows(\is_array($rows) ? $rows : []);
        $computed = $this->computeFromSessionFlags($flags);

        return array_merge($empty, $computed, ['error' => '']);
    }

    /**
     * SQL 行：每个 session 一行，含 step_* 0/1。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, bool>>
     */
    public function sessionFlagsFromSqlRows(array $rows): array
    {
        $flags = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $sessionId = trim((string)($row['session_id'] ?? ''));
            if ($sessionId === '') {
                continue;
            }
            $flags[$sessionId] = [
                self::STEP_LANDING => (int)($row['step_landing'] ?? 0) === 1,
                self::STEP_INTERACTION => (int)($row['step_interaction'] ?? 0) === 1,
                self::STEP_ADD_TO_CART => (int)($row['step_add_to_cart'] ?? 0) === 1,
                self::STEP_CONVERSION => (int)($row['step_conversion'] ?? 0) === 1,
            ];
        }

        return $flags;
    }

    /**
     * @param array{days: int, start_date: string, end_date: string} $window
     * @return array{0: string, 1: array<string, int|string>}
     */
    public function buildSessionStepSql(string $channelCode, ?int $websiteId, array $window): array
    {
        $alias = 'p';
        $table = $this->tableSql($alias);
        $eventTime = $this->eventTimeExpression($alias);
        $channelCol = $this->col(Pixel::schema_fields_CHANNEL_CODE, $alias);
        $sessionCol = $this->col(Pixel::schema_fields_SESSION_ID, $alias);
        $eventCol = $this->col(Pixel::schema_fields_EVENT, $alias);

        $clauses = [
            "{$channelCol} = :channel_code",
            "{$eventTime} >= :start_date",
            "{$eventTime} <= :end_date",
            "NULLIF({$sessionCol}, '') IS NOT NULL",
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

        $landingIn = $this->inList(self::LANDING_EVENTS);
        $interactionIn = $this->inList(self::INTERACTION_EVENTS);
        $cartIn = $this->inList(self::ADD_TO_CART_EVENTS);
        $conversionIn = $this->inList(self::CONVERSION_EVENTS);
        $eventLower = 'LOWER(' . $eventCol . ')';

        $sql = "SELECT
                {$sessionCol} AS session_id,
                MAX(CASE WHEN {$eventLower} IN ({$landingIn}) THEN 1 ELSE 0 END) AS step_landing,
                MAX(CASE WHEN {$eventLower} IN ({$interactionIn}) OR {$eventLower} LIKE 'search\\_%' ESCAPE '\\' THEN 1 ELSE 0 END) AS step_interaction,
                MAX(CASE WHEN {$eventLower} IN ({$cartIn}) THEN 1 ELSE 0 END) AS step_add_to_cart,
                MAX(CASE WHEN {$eventLower} IN ({$conversionIn}) THEN 1 ELSE 0 END) AS step_conversion
            FROM {$table}
            WHERE " . implode(' AND ', $clauses) . "
            GROUP BY {$sessionCol}";

        return [$sql, $params];
    }

    /**
     * @param array{days: int, start_date: string, end_date: string} $window
     * @return array<int, array<string, mixed>>
     */
    private function fetchSessionStepRows(string $channelCode, ?int $websiteId, array $window): array
    {
        [$sql, $params] = $this->buildSessionStepSql($channelCode, $websiteId, $window);
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

    private function translateStepLabel(string $step): string
    {
        return match ($step) {
            self::STEP_LANDING => (string)__('落地'),
            self::STEP_INTERACTION => (string)__('互动'),
            self::STEP_ADD_TO_CART => (string)__('加购'),
            self::STEP_CONVERSION => (string)__('转化'),
            default => self::STEP_LABELS[$step] ?? $step,
        };
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
