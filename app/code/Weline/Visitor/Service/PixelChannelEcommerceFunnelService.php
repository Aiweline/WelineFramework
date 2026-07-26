<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;

/**
 * F05b：渠道详情「电商四步」漏斗（热表；按 channel_code 限定）。
 *
 * 步骤与比率口径复用 F01 `PixelEcommerceFunnelService`（view_item → add_to_cart
 * → begin_checkout → checkout_success，purchase 兼容末步；顺序门槛、按会话去重）。
 *
 * 与 F01 的差别仅在取数范围：
 * - 过滤 `channel_code`（有站点绑定再限 website_id），与 B12 营销简漏斗一致；
 * - 时间窗走 `PixelChannelHotTotalsService::resolveWindow()` 的 7/30 天，
 *   而非报表引擎热短窗，保证渠道详情两种模式切换时窗口一致可比。
 */
class PixelChannelEcommerceFunnelService
{
    public const DEFAULT_DAYS = PixelChannelFunnelService::DEFAULT_DAYS;

    /** @var list<string> */
    public const STEP_ORDER = PixelEcommerceFunnelService::STEP_ORDER;

    public function __construct(
        private ?PixelChannelHotTotalsService $hotTotals = null,
        private ?PixelEcommerceFunnelService $ecommerceFunnel = null,
    ) {
    }

    public function normalizeDays(int $days): int
    {
        return \in_array($days, PixelChannelHotTotalsService::WINDOW_DAYS, true)
            ? $days
            : self::DEFAULT_DAYS;
    }

    public function eventMatchesStep(string $event, string $step): bool
    {
        return $this->getEcommerceFunnel()->eventMatchesStep($event, $step);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<string, array<string, bool>>
     */
    public function sessionStepFlagsFromEvents(array $events): array
    {
        return $this->getEcommerceFunnel()->sessionStepFlagsFromEvents($events);
    }

    /**
     * @param array<string, array<string, bool>> $sessionFlags
     * @return array{steps: list<array<string, mixed>>, step1_sessions: int, scored_sessions: int}
     */
    public function computeFromSessionFlags(array $sessionFlags): array
    {
        return $this->getEcommerceFunnel()->computeFromSessionFlags($sessionFlags);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array{steps: list<array<string, mixed>>, step1_sessions: int, scored_sessions: int}
     */
    public function computeFromEvents(array $events): array
    {
        return $this->getEcommerceFunnel()->computeFromEvents($events);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, bool>>
     */
    public function sessionFlagsFromSqlRows(array $rows): array
    {
        return $this->getEcommerceFunnel()->sessionFlagsFromSqlRows($rows);
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

        $viewIn = $this->inList(PixelEcommerceFunnelService::VIEW_ITEM_EVENTS);
        $cartIn = $this->inList(PixelEcommerceFunnelService::ADD_TO_CART_EVENTS);
        $checkoutIn = $this->inList(PixelEcommerceFunnelService::BEGIN_CHECKOUT_EVENTS);
        $successIn = $this->inList(PixelEcommerceFunnelService::CHECKOUT_SUCCESS_EVENTS);
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

    private function getEcommerceFunnel(): PixelEcommerceFunnelService
    {
        if (!$this->ecommerceFunnel) {
            $this->ecommerceFunnel = ObjectManager::getInstance(PixelEcommerceFunnelService::class);
        }

        return $this->ecommerceFunnel;
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
