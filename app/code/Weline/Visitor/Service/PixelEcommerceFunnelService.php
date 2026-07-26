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
 * F01：字典电商四步漏斗（热表；全站报表）。
 *
 * 步骤（对齐 event_dictionary.json 电商族）：
 * view_item → add_to_cart → begin_checkout → checkout_success
 * （checkout_success 兼容同字典标记的 purchase）。
 *
 * 比率：到达步 N 的会话数 / 到达步 1 的会话数；须顺序门槛。
 * 非 B12 营销简漏斗；渠道详情切换见 F05b。
 */
class PixelEcommerceFunnelService
{
    public const STEP_VIEW_ITEM = 'view_item';
    public const STEP_ADD_TO_CART = 'add_to_cart';
    public const STEP_BEGIN_CHECKOUT = 'begin_checkout';
    public const STEP_CHECKOUT_SUCCESS = 'checkout_success';

    /** @var list<string> */
    public const STEP_ORDER = [
        self::STEP_VIEW_ITEM,
        self::STEP_ADD_TO_CART,
        self::STEP_BEGIN_CHECKOUT,
        self::STEP_CHECKOUT_SUCCESS,
    ];

    /** @var array<string, string> */
    public const STEP_LABELS = [
        self::STEP_VIEW_ITEM => '浏览商品',
        self::STEP_ADD_TO_CART => '加购',
        self::STEP_BEGIN_CHECKOUT => '开始结账',
        self::STEP_CHECKOUT_SUCCESS => '购买成功',
    ];

    /** @var list<string> */
    public const VIEW_ITEM_EVENTS = ['view_item'];

    /** @var list<string> */
    public const ADD_TO_CART_EVENTS = ['add_to_cart'];

    /** @var list<string> */
    public const BEGIN_CHECKOUT_EVENTS = ['begin_checkout'];

    /**
     * 字典 checkout_success 与 purchase 同标记族。
     *
     * @var list<string>
     */
    public const CHECKOUT_SUCCESS_EVENTS = ['checkout_success', 'purchase'];

    public function __construct(
        private ?PixelQueryRouter $queryRouter = null,
    ) {
    }

    public function eventMatchesStep(string $event, string $step): bool
    {
        $event = strtolower(trim($event));
        if ($event === '') {
            return false;
        }

        return match ($step) {
            self::STEP_VIEW_ITEM => \in_array($event, self::VIEW_ITEM_EVENTS, true),
            self::STEP_ADD_TO_CART => \in_array($event, self::ADD_TO_CART_EVENTS, true),
            self::STEP_BEGIN_CHECKOUT => \in_array($event, self::BEGIN_CHECKOUT_EVENTS, true),
            self::STEP_CHECKOUT_SUCCESS => \in_array($event, self::CHECKOUT_SUCCESS_EVENTS, true),
            default => false,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<string, array<string, bool>>
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
                    self::STEP_VIEW_ITEM => false,
                    self::STEP_ADD_TO_CART => false,
                    self::STEP_BEGIN_CHECKOUT => false,
                    self::STEP_CHECKOUT_SUCCESS => false,
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

        $step1 = (int)$reached[self::STEP_VIEW_ITEM];
        $steps = [];
        $prev = null;
        foreach (self::STEP_ORDER as $step) {
            $count = (int)$reached[$step];
            $rate = $step1 > 0 ? round($count / $step1, 4) : 0.0;
            $dropoff = 0.0;
            if ($prev !== null && $prev > 0) {
                $dropoff = round(1 - ($count / $prev), 4);
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
     * 将筛选窗钳到热短窗（默认 ≤7 天）。
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
     * 全站（按 website_id）热表电商漏斗。
     *
     * @return array{
     *   website_id: int,
     *   from: string,
     *   to: string,
     *   window_clamped: bool,
     *   steps: list<array<string, mixed>>,
     *   step1_sessions: int,
     *   scored_sessions: int,
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
        $emptyComputed = $this->computeFromSessionFlags([]);
        $empty = [
            'website_id' => $websiteId,
            'from' => $window['from']->format('Y-m-d H:i:s'),
            'to' => $window['to']->format('Y-m-d H:i:s'),
            'window_clamped' => $window['window_clamped'],
            'steps' => $emptyComputed['steps'],
            'step1_sessions' => 0,
            'scored_sessions' => 0,
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
                $rows = $this->fetchSessionStepRows($websiteId, $window['from'], $window['to']);
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
                self::STEP_VIEW_ITEM => (int)($row['step_view_item'] ?? 0) === 1,
                self::STEP_ADD_TO_CART => (int)($row['step_add_to_cart'] ?? 0) === 1,
                self::STEP_BEGIN_CHECKOUT => (int)($row['step_begin_checkout'] ?? 0) === 1,
                self::STEP_CHECKOUT_SUCCESS => (int)($row['step_checkout_success'] ?? 0) === 1,
            ];
        }

        return $flags;
    }

    /**
     * @return array{0: string, 1: array<string, int|string>}
     */
    public function buildSessionStepSql(
        int $websiteId,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): array {
        $alias = 'p';
        $table = $this->tableSql($alias);
        $eventTime = $this->eventTimeExpression($alias);
        $sessionCol = $this->col(Pixel::schema_fields_SESSION_ID, $alias);
        $eventCol = $this->col(Pixel::schema_fields_EVENT, $alias);
        $websiteCol = $this->col(Pixel::schema_fields_WEBSITE_ID, $alias);

        $clauses = [
            "{$websiteCol} = :website_id",
            "{$eventTime} >= :start_date",
            "{$eventTime} <= :end_date",
            "NULLIF({$sessionCol}, '') IS NOT NULL",
        ];
        $params = [
            ':website_id' => $websiteId,
            ':start_date' => $from->format('Y-m-d H:i:s'),
            ':end_date' => $to->format('Y-m-d H:i:s'),
        ];

        $viewIn = $this->inList(self::VIEW_ITEM_EVENTS);
        $cartIn = $this->inList(self::ADD_TO_CART_EVENTS);
        $checkoutIn = $this->inList(self::BEGIN_CHECKOUT_EVENTS);
        $successIn = $this->inList(self::CHECKOUT_SUCCESS_EVENTS);
        $eventLower = 'LOWER(' . $eventCol . ')';

        $sql = "SELECT
                {$sessionCol} AS session_id,
                MAX(CASE WHEN {$eventLower} IN ({$viewIn}) THEN 1 ELSE 0 END) AS step_view_item,
                MAX(CASE WHEN {$eventLower} IN ({$cartIn}) THEN 1 ELSE 0 END) AS step_add_to_cart,
                MAX(CASE WHEN {$eventLower} IN ({$checkoutIn}) THEN 1 ELSE 0 END) AS step_begin_checkout,
                MAX(CASE WHEN {$eventLower} IN ({$successIn}) THEN 1 ELSE 0 END) AS step_checkout_success
            FROM {$table}
            WHERE " . implode(' AND ', $clauses) . "
            GROUP BY {$sessionCol}";

        return [$sql, $params];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSessionStepRows(
        int $websiteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        [$sql, $params] = $this->buildSessionStepSql($websiteId, $from, $to);
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

    private function translateStepLabel(string $step): string
    {
        return match ($step) {
            self::STEP_VIEW_ITEM => (string)__('浏览商品'),
            self::STEP_ADD_TO_CART => (string)__('加购'),
            self::STEP_BEGIN_CHECKOUT => (string)__('开始结账'),
            self::STEP_CHECKOUT_SUCCESS => (string)__('购买成功'),
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
