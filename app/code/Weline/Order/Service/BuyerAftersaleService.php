<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\BuyerAftersaleRequest;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;

/**
 * 买家售后状态机。
 *
 * 先保存申请单，再派发标量事件。派发失败只记日志，不回滚。
 * 本服务不调用退款协调；支付联动只由 link_payment 观察者处理。
 * exchange 永不派发 link_payment。
 */
final class BuyerAftersaleService
{
    public const EVENT_SUBMITTED = 'Weline_Order::buyer_after_sale_submitted';
    public const EVENT_APPROVED = 'Weline_Order::buyer_after_sale_approved';
    public const EVENT_LINK_PAYMENT = 'Weline_Order::buyer_after_sale_link_payment';
    public const EVENT_COLLECT_PRODUCT_RETURN_ADDRESS = 'Weline_Order::buyer_after_sale_collect_product_return_address';

    public const ERROR_DISABLED = 'buyer_aftersale_disabled';
    public const ERROR_LOGIN_REQUIRED = 'buyer_aftersale_login_required';
    public const ERROR_ORDER_REQUIRED = 'buyer_aftersale_order_required';
    public const ERROR_RESOLUTION_INVALID = 'buyer_aftersale_resolution_invalid';
    public const ERROR_REASON_INVALID = 'buyer_aftersale_reason_invalid';
    public const ERROR_ORDER_NOT_OWNED = 'buyer_aftersale_order_not_owned';
    public const ERROR_NOT_FOUND = 'buyer_aftersale_not_found';
    public const ERROR_INVALID_TRANSITION = 'buyer_aftersale_invalid_transition';
    public const ERROR_TRACKING_REQUIRED = 'buyer_aftersale_tracking_required';
    public const ERROR_FORBIDDEN = 'buyer_aftersale_forbidden';
    public const ERROR_REQUEST_UUID_INVALID = 'buyer_aftersale_request_uuid_invalid';

    /** @var list<string> */
    private const RESOLUTIONS = [
        BuyerAftersaleRequest::RESOLUTION_RETURN,
        BuyerAftersaleRequest::RESOLUTION_REFUND_ONLY,
        BuyerAftersaleRequest::RESOLUTION_EXCHANGE,
    ];

    /** @var list<string> */
    private const STATUSES = [
        BuyerAftersaleRequest::STATUS_SUBMITTED,
        BuyerAftersaleRequest::STATUS_REJECTED,
        BuyerAftersaleRequest::STATUS_CANCELLED,
        BuyerAftersaleRequest::STATUS_APPROVED,
        BuyerAftersaleRequest::STATUS_RETURN_RECEIVED,
        BuyerAftersaleRequest::STATUS_AWAITING_RESHIP,
        BuyerAftersaleRequest::STATUS_MANUAL_DONE,
    ];

    /** @var list<string> */
    private const REASONS = [
        BuyerAftersaleRequest::REASON_QUALITY,
        BuyerAftersaleRequest::REASON_WRONG_OR_MISSING,
        BuyerAftersaleRequest::REASON_TRANSIT_DAMAGE,
    ];

    public function __construct(
        private readonly BuyerAftersaleConfig $config,
        private readonly ?EventsManager $eventsManager = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,error_code:?string,request_uuid?:string,status?:string}
     */
    public function submit(int $customerId, array $input): array
    {
        if (!$this->config->isBuyerAftersaleEnabled()) {
            return $this->fail(self::ERROR_DISABLED);
        }
        if ($customerId <= 0) {
            return $this->fail(self::ERROR_LOGIN_REQUIRED);
        }
        $orderUuid = trim((string)($input['order_uuid'] ?? ''));
        if ($orderUuid === '') {
            return $this->fail(self::ERROR_ORDER_REQUIRED);
        }
        $resolution = trim((string)($input['resolution'] ?? ''));
        if (!\in_array($resolution, self::RESOLUTIONS, true)) {
            return $this->fail(self::ERROR_RESOLUTION_INVALID);
        }
        $reasonCode = trim((string)($input['reason_code'] ?? ''));
        if (!\in_array($reasonCode, self::REASONS, true)) {
            return $this->fail(self::ERROR_REASON_INVALID);
        }
        $requestUuid = trim((string)($input['request_uuid'] ?? ''));
        if ($requestUuid === '') {
            $requestUuid = $this->newUuid();
        } elseif (\strlen($requestUuid) > 36) {
            return $this->fail(self::ERROR_REQUEST_UUID_INVALID);
        }
        $order = $this->findOwnedOrder($orderUuid, $customerId);
        if ($order === null) {
            return $this->fail(self::ERROR_ORDER_NOT_OWNED);
        }

        $idempotencyKey = 'buyer-aftersale:' . $requestUuid;
        $existing = $this->findByIdempotency($idempotencyKey);
        if ($existing instanceof BuyerAftersaleRequest && $existing->getId()) {
            return $this->ok($existing);
        }

        $now = date('Y-m-d H:i:s');
        $reasonText = trim((string)($input['reason_text'] ?? ''));
        $row = $this->newRequest();
        $row->setData([
            BuyerAftersaleRequest::schema_fields_REQUEST_UUID => $requestUuid,
            BuyerAftersaleRequest::schema_fields_ORDER_UUID => $orderUuid,
            BuyerAftersaleRequest::schema_fields_CUSTOMER_ID => $customerId,
            BuyerAftersaleRequest::schema_fields_WEBSITE_ID => (int)$order['website_id'],
            BuyerAftersaleRequest::schema_fields_RESOLUTION => $resolution,
            BuyerAftersaleRequest::schema_fields_REASON_CODE => $reasonCode,
            BuyerAftersaleRequest::schema_fields_REASON_TEXT => $reasonText !== '' ? $reasonText : null,
            BuyerAftersaleRequest::schema_fields_STATUS => BuyerAftersaleRequest::STATUS_SUBMITTED,
            BuyerAftersaleRequest::schema_fields_CURRENCY => (string)$order['currency'],
            BuyerAftersaleRequest::schema_fields_AMOUNT_MINOR => $this->nonNegativeInt($input['amount_minor'] ?? 0),
            BuyerAftersaleRequest::schema_fields_ITEM_COUNT => $this->nonNegativeInt($input['item_count'] ?? 0),
            BuyerAftersaleRequest::schema_fields_LINK_PAYMENT_SNAPSHOT => $this->config->isPaymentLinkActive() ? 1 : 0,
            BuyerAftersaleRequest::schema_fields_IDEMPOTENCY_KEY => $idempotencyKey,
            BuyerAftersaleRequest::schema_fields_RETURN_ADDRESS_SNAPSHOT => null,
            BuyerAftersaleRequest::schema_fields_CREATED_AT => $now,
            BuyerAftersaleRequest::schema_fields_UPDATED_AT => $now,
        ]);
        try {
            $row->save();
        } catch (\Throwable $e) {
            $again = $this->findByIdempotency($idempotencyKey);
            if ($again instanceof BuyerAftersaleRequest && $again->getId()) {
                return $this->ok($again);
            }
            throw $e;
        }

        $this->dispatch(self::EVENT_SUBMITTED, $this->payload($row, 'customer'));

        return $this->ok($row);
    }

    /**
     * @return array{ok:bool,error_code:?string,request_uuid?:string,status?:string}
     */
    public function approve(string $requestUuid, string $actor = 'admin'): array
    {
        $row = $this->requireRow($requestUuid);
        if (\is_array($row)) {
            return $row;
        }
        if ((string)$row->getData(BuyerAftersaleRequest::schema_fields_STATUS) !== BuyerAftersaleRequest::STATUS_SUBMITTED) {
            return $this->fail(self::ERROR_INVALID_TRANSITION);
        }
        $resolution = (string)$row->getData(BuyerAftersaleRequest::schema_fields_RESOLUTION);
        $events = [];
        if ($resolution === BuyerAftersaleRequest::RESOLUTION_RETURN
            || $resolution === BuyerAftersaleRequest::RESOLUTION_EXCHANGE
        ) {
            $events[] = self::EVENT_APPROVED;
        } elseif ($resolution === BuyerAftersaleRequest::RESOLUTION_REFUND_ONLY && $this->config->isPaymentLinkActive()) {
            $events[] = self::EVENT_LINK_PAYMENT;
        }

        $extra = [];
        $payloadExtra = [];
        if (\in_array(self::EVENT_APPROVED, $events, true)) {
            $resolved = $this->resolveReturnAddress($row);
            $extra[BuyerAftersaleRequest::schema_fields_RETURN_ADDRESS_SNAPSHOT] = $resolved['address'] !== ''
                ? $resolved['address']
                : null;
            $payloadExtra = [
                'return_address' => $resolved['address'],
                'return_address_source' => $resolved['source'],
                'warehouse_ids' => implode(',', $resolved['warehouse_ids']),
            ];
        }

        return $this->applyStatus($row, BuyerAftersaleRequest::STATUS_APPROVED, $extra, $actor, $events, $payloadExtra);
    }

    /**
     * @return array{ok:bool,error_code:?string,request_uuid?:string,status?:string}
     */
    public function reject(string $requestUuid, string $actor = 'admin'): array
    {
        $row = $this->requireRow($requestUuid);
        if (\is_array($row)) {
            return $row;
        }
        if ((string)$row->getData(BuyerAftersaleRequest::schema_fields_STATUS) !== BuyerAftersaleRequest::STATUS_SUBMITTED) {
            return $this->fail(self::ERROR_INVALID_TRANSITION);
        }

        return $this->applyStatus($row, BuyerAftersaleRequest::STATUS_REJECTED, [], $actor, []);
    }

    /**
     * @return array{ok:bool,error_code:?string,request_uuid?:string,status?:string}
     */
    public function cancel(int $customerId, string $requestUuid): array
    {
        $row = $this->requireOwned($customerId, $requestUuid);
        if (\is_array($row)) {
            return $row;
        }
        if ((string)$row->getData(BuyerAftersaleRequest::schema_fields_STATUS) !== BuyerAftersaleRequest::STATUS_SUBMITTED) {
            return $this->fail(self::ERROR_INVALID_TRANSITION);
        }

        return $this->applyStatus($row, BuyerAftersaleRequest::STATUS_CANCELLED, [], 'customer', []);
    }

    /**
     * return：approved → return_received，两闸都开才派 link_payment。
     * exchange：approved → awaiting_reship，不派 link_payment。
     *
     * @return array{ok:bool,error_code:?string,request_uuid?:string,status?:string}
     */
    public function markReturnReceived(string $requestUuid, string $actor = 'admin'): array
    {
        $row = $this->requireRow($requestUuid);
        if (\is_array($row)) {
            return $row;
        }
        if ((string)$row->getData(BuyerAftersaleRequest::schema_fields_STATUS) !== BuyerAftersaleRequest::STATUS_APPROVED) {
            return $this->fail(self::ERROR_INVALID_TRANSITION);
        }
        $resolution = (string)$row->getData(BuyerAftersaleRequest::schema_fields_RESOLUTION);
        if ($resolution === BuyerAftersaleRequest::RESOLUTION_EXCHANGE) {
            return $this->applyStatus($row, BuyerAftersaleRequest::STATUS_AWAITING_RESHIP, [], $actor, []);
        }
        if ($resolution !== BuyerAftersaleRequest::RESOLUTION_RETURN) {
            return $this->fail(self::ERROR_INVALID_TRANSITION);
        }
        $events = $this->config->isPaymentLinkActive() ? [self::EVENT_LINK_PAYMENT] : [];

        return $this->applyStatus($row, BuyerAftersaleRequest::STATUS_RETURN_RECEIVED, [], $actor, $events);
    }

    /**
     * exchange：awaiting_reship → manual_done。其它方案拒绝。
     *
     * @return array{ok:bool,error_code:?string,request_uuid?:string,status?:string}
     */
    public function markReshipped(string $requestUuid, string $trackingNo, string $actor = 'admin'): array
    {
        if (!$this->config->isBuyerAftersaleEnabled()) {
            return $this->fail(self::ERROR_DISABLED);
        }
        $trackingNo = trim($trackingNo);
        if ($trackingNo === '') {
            return $this->fail(self::ERROR_TRACKING_REQUIRED);
        }
        $row = $this->requireRow($requestUuid);
        if (\is_array($row)) {
            return $row;
        }
        if ((string)$row->getData(BuyerAftersaleRequest::schema_fields_RESOLUTION) !== BuyerAftersaleRequest::RESOLUTION_EXCHANGE
            || (string)$row->getData(BuyerAftersaleRequest::schema_fields_STATUS) !== BuyerAftersaleRequest::STATUS_AWAITING_RESHIP
        ) {
            return $this->fail(self::ERROR_INVALID_TRANSITION);
        }

        return $this->applyStatus($row, BuyerAftersaleRequest::STATUS_MANUAL_DONE, [
            BuyerAftersaleRequest::schema_fields_RESHIP_TRACKING_NO => $trackingNo,
        ], $actor, []);
    }

    /**
     * @return array{ok:bool,error_code:?string,request_uuid?:string,status?:string}
     */
    public function setTracking(int $customerId, string $requestUuid, string $trackingNo): array
    {
        if (!$this->config->isBuyerAftersaleEnabled()) {
            return $this->fail(self::ERROR_DISABLED);
        }
        $trackingNo = trim($trackingNo);
        if ($trackingNo === '') {
            return $this->fail(self::ERROR_TRACKING_REQUIRED);
        }
        $row = $this->requireOwned($customerId, $requestUuid);
        if (\is_array($row)) {
            return $row;
        }
        $resolution = (string)$row->getData(BuyerAftersaleRequest::schema_fields_RESOLUTION);
        $status = (string)$row->getData(BuyerAftersaleRequest::schema_fields_STATUS);
        $returnLike = $resolution === BuyerAftersaleRequest::RESOLUTION_RETURN
            || $resolution === BuyerAftersaleRequest::RESOLUTION_EXCHANGE;
        if (!$returnLike || $status !== BuyerAftersaleRequest::STATUS_APPROVED) {
            return $this->fail(self::ERROR_INVALID_TRANSITION);
        }
        $row->setData(BuyerAftersaleRequest::schema_fields_RETURN_TRACKING_NO, $trackingNo);
        $row->setData(BuyerAftersaleRequest::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
        $row->save();

        return $this->ok($row);
    }

    /**
     * @param array<string, mixed> $extra
     * @param list<string> $events
     * @param array<string, int|string> $payloadExtra
     * @return array{ok:bool,error_code:?string,request_uuid?:string,status?:string}
     */
    private function applyStatus(
        BuyerAftersaleRequest $row,
        string $status,
        array $extra,
        string $actor,
        array $events,
        array $payloadExtra = [],
    ): array {
        $row->setData(BuyerAftersaleRequest::schema_fields_STATUS, $status);
        foreach ($extra as $field => $value) {
            $row->setData((string)$field, $value);
        }
        $row->setData(BuyerAftersaleRequest::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
        $row->save();
        $payload = $this->payload($row, $actor) + $payloadExtra;
        foreach ($events as $eventName) {
            $this->dispatch($eventName, $payload);
        }

        return $this->ok($row);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $eventName, array &$payload): void
    {
        try {
            $events = $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
            $events->dispatch($eventName, $payload);
        } catch (\Throwable $e) {
            w_log_error('BuyerAftersaleService dispatch ' . $eventName . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function payload(BuyerAftersaleRequest $row, string $actor): array
    {
        return [
            'request_uuid' => (string)$row->getData(BuyerAftersaleRequest::schema_fields_REQUEST_UUID),
            'order_uuid' => (string)$row->getData(BuyerAftersaleRequest::schema_fields_ORDER_UUID),
            'customer_id' => (int)$row->getData(BuyerAftersaleRequest::schema_fields_CUSTOMER_ID),
            'website_id' => (int)$row->getData(BuyerAftersaleRequest::schema_fields_WEBSITE_ID),
            'resolution' => (string)$row->getData(BuyerAftersaleRequest::schema_fields_RESOLUTION),
            'status' => (string)$row->getData(BuyerAftersaleRequest::schema_fields_STATUS),
            'reason_code' => (string)$row->getData(BuyerAftersaleRequest::schema_fields_REASON_CODE),
            'currency' => (string)$row->getData(BuyerAftersaleRequest::schema_fields_CURRENCY),
            'amount_minor' => (int)$row->getData(BuyerAftersaleRequest::schema_fields_AMOUNT_MINOR),
            'item_count' => (int)$row->getData(BuyerAftersaleRequest::schema_fields_ITEM_COUNT),
            'link_payment' => $this->config->isPaymentLinkActive() ? 1 : 0,
            'idempotency_key' => (string)$row->getData(BuyerAftersaleRequest::schema_fields_IDEMPOTENCY_KEY),
            'actor' => $actor,
            'occurred_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return BuyerAftersaleRequest|array{ok:bool,error_code:?string}
     */
    private function requireRow(string $requestUuid): BuyerAftersaleRequest|array
    {
        if (!$this->config->isBuyerAftersaleEnabled()) {
            return $this->fail(self::ERROR_DISABLED);
        }
        $requestUuid = trim($requestUuid);
        if ($requestUuid === '') {
            return $this->fail(self::ERROR_NOT_FOUND);
        }
        $row = $this->newRequest();
        $row->clear()
            ->where(BuyerAftersaleRequest::schema_fields_REQUEST_UUID, $requestUuid)
            ->find()
            ->fetch();
        if (!$row->getId()) {
            return $this->fail(self::ERROR_NOT_FOUND);
        }

        return $row;
    }

    /**
     * @return BuyerAftersaleRequest|array{ok:bool,error_code:?string}
     */
    private function requireOwned(int $customerId, string $requestUuid): BuyerAftersaleRequest|array
    {
        if ($customerId <= 0) {
            if (!$this->config->isBuyerAftersaleEnabled()) {
                return $this->fail(self::ERROR_DISABLED);
            }

            return $this->fail(self::ERROR_LOGIN_REQUIRED);
        }
        $row = $this->requireRow($requestUuid);
        if (\is_array($row)) {
            return $row;
        }
        if ((int)$row->getData(BuyerAftersaleRequest::schema_fields_CUSTOMER_ID) !== $customerId) {
            return $this->fail(self::ERROR_FORBIDDEN);
        }

        return $row;
    }

    /**
     * @return array{source:string,address:string,warehouse_ids:list<int>}
     */
    private function resolveReturnAddress(BuyerAftersaleRequest $row): array
    {
        $orderUuid = (string)$row->getData(BuyerAftersaleRequest::schema_fields_ORDER_UUID);
        $websiteId = (int)$row->getData(BuyerAftersaleRequest::schema_fields_WEBSITE_ID);
        $productIds = $this->productIdsForOrder($orderUuid);
        $scope = $this->storageScopeForOrder($orderUuid);

        return (new BuyerAftersaleReturnAddressResolver())->resolve(
            $this->collectProductReturnAddresses($websiteId, $productIds),
            $this->config->isReturnAddressCustomEnabled($scope),
            $this->config->customReturnAddress($scope),
            $this->warehouseIdsForOrder($orderUuid),
        );
    }

    /**
     * @param list<int> $productIds
     * @return array<int, string>
     */
    private function collectProductReturnAddresses(int $websiteId, array $productIds): array
    {
        $bag = [
            'website_id' => $websiteId,
            'product_ids' => $productIds,
            'product_return_addresses' => [],
        ];
        $this->dispatch(self::EVENT_COLLECT_PRODUCT_RETURN_ADDRESS, $bag);
        $addresses = $bag['product_return_addresses'] ?? [];

        return \is_array($addresses) ? $addresses : [];
    }

    /**
     * @return list<int>
     */
    private function productIdsForOrder(string $orderUuid): array
    {
        if ($orderUuid === '') {
            return [];
        }
        try {
            /** @var OrderItem $model */
            $model = ObjectManager::getInstance(OrderItem::class);
            $items = $model->clear()->where(OrderItem::schema_fields_ORDER_UUID, $orderUuid)->select()->fetch()->getItems();
        } catch (\Throwable) {
            return [];
        }
        $ids = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            $id = (int)(is_object($item) ? $item->getData(OrderItem::schema_fields_PRODUCT_ID) : 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @return list<int>
     */
    private function warehouseIdsForOrder(string $orderUuid): array
    {
        if ($orderUuid === '') {
            return [];
        }
        try {
            /** @var FulfillmentUnit $model */
            $model = ObjectManager::getInstance(FulfillmentUnit::class);
            $items = $model->clear()
                ->where(FulfillmentUnit::schema_fields_ORDER_UUID, $orderUuid)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }
        $ids = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!is_object($item)) {
                continue;
            }
            if ((string)$item->getData(FulfillmentUnit::schema_fields_STATUS) === FulfillmentUnit::STATUS_CANCELLED) {
                continue;
            }
            $id = (int)$item->getData(FulfillmentUnit::schema_fields_WAREHOUSE_ID);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function storageScopeForOrder(string $orderUuid): string
    {
        try {
            $order = $this->newOrder();
            $order->clear()
                ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
                ->find()
                ->fetch();
            if (!$order->getId()) {
                return 'default.default.default';
            }
            $identity = ObjectManager::getInstance(OrderObjectScopeService::class)->fromOrder($order);
            $scope = strtolower(trim($identity->toLegacyScopeString()));

            return $scope !== '' ? $scope : 'default.default.default';
        } catch (\Throwable) {
            return 'default.default.default';
        }
    }

    /**
     * @return array{website_id:int,currency:string}|null
     */
    private function findOwnedOrder(string $orderUuid, int $customerId): ?array
    {
        $order = $this->newOrder();
        $order->clear()
            ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
            ->where(Order::schema_fields_CUSTOMER_ID, $customerId)
            ->find()
            ->fetch();
        if (!$order->getId()) {
            return null;
        }
        $currency = strtoupper(trim((string)$order->getData(Order::schema_fields_CURRENCY)));
        if ($currency === '') {
            $currency = 'CNY';
        }

        return [
            'website_id' => (int)$order->getData(Order::schema_fields_WEBSITE_ID),
            'currency' => substr($currency, 0, 3),
        ];
    }

    private function findByIdempotency(string $idempotencyKey): BuyerAftersaleRequest
    {
        $row = $this->newRequest();
        $row->clear()
            ->where(BuyerAftersaleRequest::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
            ->find()
            ->fetch();

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(string $status, string $keyword, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $query = $this->newRequest()->clear();
        if (\in_array($status, self::STATUSES, true)) {
            $query->where(BuyerAftersaleRequest::schema_fields_STATUS, $status);
        }
        $rows = $query
            ->order(BuyerAftersaleRequest::schema_fields_CREATED_AT, 'DESC')
            ->limit(200)
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $rows = array_values(array_filter(
                $rows,
                static function (mixed $row) use ($keyword): bool {
                    if (!\is_array($row)) {
                        return false;
                    }
                    $haystack = implode(' ', [
                        (string)($row[BuyerAftersaleRequest::schema_fields_REQUEST_UUID] ?? ''),
                        (string)($row[BuyerAftersaleRequest::schema_fields_ORDER_UUID] ?? ''),
                        (string)($row[BuyerAftersaleRequest::schema_fields_REASON_TEXT] ?? ''),
                        (string)($row[BuyerAftersaleRequest::schema_fields_RETURN_TRACKING_NO] ?? ''),
                    ]);

                    return str_contains($haystack, $keyword);
                },
            ));
        }
        $listed = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            if (\is_array($row)) {
                $listed[] = $row;
            }
        }

        return $listed;
    }

    private function newRequest(): BuyerAftersaleRequest
    {
        /** @var BuyerAftersaleRequest $model */
        $model = ObjectManager::getInstance(BuyerAftersaleRequest::class, [], false);

        return $model;
    }

    private function newOrder(): Order
    {
        /** @var Order $model */
        $model = ObjectManager::getInstance(Order::class, [], false);

        return $model;
    }

    private function nonNegativeInt(mixed $value): int
    {
        if (\is_int($value)) {
            return max(0, $value);
        }
        if (\is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int)$value;
        }

        return 0;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8)
            . '-' . substr($hex, 8, 4)
            . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4)
            . '-' . substr($hex, 20, 12);
    }

    /**
     * @return array{ok:bool,error_code:?string,request_uuid?:string,status?:string}
     */
    private function ok(BuyerAftersaleRequest $row): array
    {
        return [
            'ok' => true,
            'error_code' => null,
            'request_uuid' => (string)$row->getData(BuyerAftersaleRequest::schema_fields_REQUEST_UUID),
            'status' => (string)$row->getData(BuyerAftersaleRequest::schema_fields_STATUS),
        ];
    }

    /**
     * @return array{ok:false,error_code:string}
     */
    private function fail(string $code): array
    {
        return ['ok' => false, 'error_code' => $code];
    }
}
