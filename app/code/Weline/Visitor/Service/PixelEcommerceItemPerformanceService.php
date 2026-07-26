<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Service\Report\PixelQueryRouter;

/**
 * F03：商品表现（items 展开；热表）。
 *
 * 从 browser_info.additionalInfo.ecommerce.items（或行内已展开 items）按 item_id 聚合：
 * 曝光(view_item) / 加购(add_to_cart) / 购买与收入(checkout_success|purchase)。
 */
class PixelEcommerceItemPerformanceService
{
    /** @var list<string> */
    public const VIEW_EVENTS = PixelEcommerceFunnelService::VIEW_ITEM_EVENTS;

    /** @var list<string> */
    public const ADD_TO_CART_EVENTS = PixelEcommerceFunnelService::ADD_TO_CART_EVENTS;

    /** @var list<string> */
    public const BEGIN_CHECKOUT_EVENTS = PixelEcommerceFunnelService::BEGIN_CHECKOUT_EVENTS;

    /** @var list<string> */
    public const PURCHASE_EVENTS = PixelEcommerceFunnelService::CHECKOUT_SUCCESS_EVENTS;

    public function __construct(
        private ?PixelQueryRouter $queryRouter = null,
        private ?PixelEcommerceFunnelService $funnelService = null,
    ) {
    }

    /**
     * 从单行展开商品行（不查库）。
     *
     * @param array<string, mixed> $row
     * @return list<array{
     *   item_id: string,
     *   item_name: string,
     *   quantity: float,
     *   price: float|null,
     *   line_revenue: float,
     *   event: string,
     *   session_id: string
     * }>
     */
    public function expandItemsFromRow(array $row): array
    {
        $event = strtolower(trim((string)($row['event'] ?? $row[Pixel::schema_fields_EVENT] ?? '')));
        $sessionId = trim((string)($row['session_id'] ?? $row[Pixel::schema_fields_SESSION_ID] ?? ''));
        $items = $this->extractItems($row);
        if ($items === []) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            $itemId = trim((string)($item['item_id'] ?? $item['product_id'] ?? $item['sku'] ?? ''));
            $name = trim((string)($item['item_name'] ?? $item['name'] ?? ''));
            if ($itemId === '' && $name === '') {
                continue;
            }
            if ($itemId === '') {
                $itemId = '(unnamed:' . mb_substr($name, 0, 40) . ')';
            }
            $qty = (float)($item['quantity'] ?? $item['qty'] ?? 1);
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $price = isset($item['price']) && $item['price'] !== null && $item['price'] !== ''
                ? (float)$item['price']
                : null;
            $lineRevenue = $price !== null ? round($price * $qty, 4) : 0.0;
            $out[] = [
                'item_id' => $itemId,
                'item_name' => $name !== '' ? $name : $itemId,
                'quantity' => $qty,
                'price' => $price,
                'line_revenue' => $lineRevenue,
                'event' => $event,
                'session_id' => $sessionId,
            ];
        }

        // 购买事件若无单价，用事件 value 均摊到各 item
        if ($this->isPurchaseEvent($event)) {
            $eventValue = (float)($row['value'] ?? $row[Pixel::schema_fields_VALUE] ?? 0);
            $knownRevenue = 0.0;
            foreach ($out as $line) {
                $knownRevenue += (float)$line['line_revenue'];
            }
            if ($knownRevenue <= 0.0 && $eventValue > 0.0 && $out !== []) {
                $share = round($eventValue / \count($out), 4);
                foreach ($out as &$line) {
                    $line['line_revenue'] = $share;
                }
                unset($line);
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array{
     *   item_id: string,
     *   item_name: string,
     *   views: int,
     *   add_to_carts: int,
     *   begin_checkouts: int,
     *   purchases: int,
     *   quantity_sold: float,
     *   item_revenue: float
     * }>
     */
    public function aggregateByItem(array $rows, int $limit = 30): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            foreach ($this->expandItemsFromRow($row) as $line) {
                $id = $line['item_id'];
                if (!isset($buckets[$id])) {
                    $buckets[$id] = [
                        'item_id' => $id,
                        'item_name' => $line['item_name'],
                        'views' => 0,
                        'add_to_carts' => 0,
                        'begin_checkouts' => 0,
                        'purchases' => 0,
                        'quantity_sold' => 0.0,
                        'item_revenue' => 0.0,
                    ];
                }
                if ($buckets[$id]['item_name'] === $id && $line['item_name'] !== '') {
                    $buckets[$id]['item_name'] = $line['item_name'];
                }
                $event = $line['event'];
                if ($this->isViewEvent($event)) {
                    $buckets[$id]['views']++;
                } elseif ($this->isAddToCartEvent($event)) {
                    $buckets[$id]['add_to_carts']++;
                } elseif ($this->isBeginCheckoutEvent($event)) {
                    $buckets[$id]['begin_checkouts']++;
                } elseif ($this->isPurchaseEvent($event)) {
                    $buckets[$id]['purchases']++;
                    $buckets[$id]['quantity_sold'] += (float)$line['quantity'];
                    $buckets[$id]['item_revenue'] += (float)$line['line_revenue'];
                }
            }
        }

        $out = array_values($buckets);
        foreach ($out as &$row) {
            $row['quantity_sold'] = round((float)$row['quantity_sold'], 4);
            $row['item_revenue'] = round((float)$row['item_revenue'], 4);
        }
        unset($row);

        usort($out, static function (array $a, array $b): int {
            $cmp = $b['item_revenue'] <=> $a['item_revenue'];
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $b['purchases'] <=> $a['purchases'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b['views'] <=> $a['views'];
        });

        return \array_slice($out, 0, max(1, $limit));
    }

    /**
     * @return array{
     *   website_id: int,
     *   from: string,
     *   to: string,
     *   window_clamped: bool,
     *   items: list<array<string, mixed>>,
     *   item_count: int,
     *   error: string
     * }
     */
    public function buildForWebsite(
        int $websiteId,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?callable $queryRunner = null,
        int $limit = 30,
    ): array {
        $funnel = $this->getFunnelService();
        $fromDt = $from instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($from)
            : new DateTimeImmutable((string)$from);
        $toDt = $to instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($to)
            : new DateTimeImmutable((string)$to);
        $window = $funnel->clampHotWindow($fromDt, $toDt);

        $empty = [
            'website_id' => $websiteId,
            'from' => $window['from']->format('Y-m-d H:i:s'),
            'to' => $window['to']->format('Y-m-d H:i:s'),
            'window_clamped' => $window['window_clamped'],
            'items' => [],
            'item_count' => 0,
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
                $rows = $this->fetchEventRows($websiteId, $window['from'], $window['to']);
            }
        } catch (\Throwable $throwable) {
            $empty['error'] = $throwable->getMessage();

            return $empty;
        }

        $items = $this->aggregateByItem(\is_array($rows) ? $rows : [], $limit);

        return array_merge($empty, [
            'items' => $items,
            'item_count' => \count($items),
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
        $browserCol = $this->col(Pixel::schema_fields_BROWSER_INFO, $alias);
        $websiteCol = $this->col(Pixel::schema_fields_WEBSITE_ID, $alias);

        $interest = array_values(array_unique(array_merge(
            self::VIEW_EVENTS,
            self::ADD_TO_CART_EVENTS,
            self::BEGIN_CHECKOUT_EVENTS,
            self::PURCHASE_EVENTS
        )));
        $interestIn = $this->inList($interest);
        $eventLower = 'LOWER(' . $eventCol . ')';

        $sql = "SELECT
                {$sessionCol} AS session_id,
                {$eventCol} AS event,
                {$valueCol} AS value,
                {$browserCol} AS browser_info,
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

    public function isViewEvent(string $event): bool
    {
        return \in_array(strtolower(trim($event)), self::VIEW_EVENTS, true);
    }

    public function isAddToCartEvent(string $event): bool
    {
        return \in_array(strtolower(trim($event)), self::ADD_TO_CART_EVENTS, true);
    }

    public function isBeginCheckoutEvent(string $event): bool
    {
        return \in_array(strtolower(trim($event)), self::BEGIN_CHECKOUT_EVENTS, true);
    }

    public function isPurchaseEvent(string $event): bool
    {
        return \in_array(strtolower(trim($event)), self::PURCHASE_EVENTS, true);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    public function extractItems(array $row): array
    {
        if (isset($row['items']) && \is_array($row['items'])) {
            return array_values(array_filter($row['items'], 'is_array'));
        }

        $browser = $this->decodeBrowserInfo($row['browser_info'] ?? $row[Pixel::schema_fields_BROWSER_INFO] ?? null);
        $additional = \is_array($browser['additionalInfo'] ?? null) ? $browser['additionalInfo'] : [];
        $ecommerce = \is_array($additional['ecommerce'] ?? null) ? $additional['ecommerce'] : [];
        if (isset($ecommerce['items']) && \is_array($ecommerce['items'])) {
            return array_values(array_filter($ecommerce['items'], 'is_array'));
        }
        $meta = \is_array($additional['meta'] ?? null) ? $additional['meta'] : [];
        if (isset($meta['items']) && \is_array($meta['items'])) {
            return array_values(array_filter($meta['items'], 'is_array'));
        }

        // 单品事件：仅有 item_id / product_id
        $itemId = trim((string)($ecommerce['item_id'] ?? $ecommerce['product_id'] ?? $meta['item_id'] ?? $meta['product_id'] ?? ''));
        if ($itemId !== '') {
            return [[
                'item_id' => $itemId,
                'product_id' => (string)($ecommerce['product_id'] ?? $meta['product_id'] ?? $itemId),
                'sku' => (string)($ecommerce['sku'] ?? $meta['sku'] ?? ''),
                'item_name' => (string)($meta['name'] ?? $meta['item_name'] ?? ''),
                'price' => $meta['price'] ?? null,
                'quantity' => $meta['quantity'] ?? $meta['qty'] ?? 1,
            ]];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBrowserInfo(mixed $raw): array
    {
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
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
