<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\B2BOrderHang;
use Weline\B2B\Model\B2BOrderHangRecord;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\InventoryCapabilityInterface;
use Weline\Payment\Model\PaymentIntent;

/**
 * ToB hang-order lifecycle: deposit → merchant approval → balance.
 * Inventory is deferred until deposit is paid.
 */
final class B2BHangOrderService
{
    public const ERROR_NOT_FOUND = 'b2b_hang_not_found';
    public const ERROR_INVALID_STATE = 'b2b_hang_invalid_state';
    public const ERROR_INVALID = 'b2b_hang_invalid';
    public const ERROR_ALREADY_EXISTS = 'b2b_hang_already_exists';

    public const DEFAULT_INVENTORY_TTL_SECONDS = 259200; // 72h

    public const PURPOSE_DEPOSIT = 'deposit';
    public const PURPOSE_BALANCE = 'balance';

    /** @var array<string, B2BOrderHang>|null keyed by hang_id */
    private ?array $byHangId = null;

    /** @var array<string, string>|null order_ref => hang_id */
    private ?array $byOrderRef = null;

    /** @var list<array<string,mixed>> */
    private array $refunds = [];

    /** @var list<array<string,mixed>> */
    private array $eventPayloads = [];

    /** @var (\Closure(): B2BOrderHangRecord)|null */
    private readonly ?\Closure $recordFactory;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param (callable(): B2BOrderHangRecord)|null $recordFactory
     * @param callable(): int|null $clock
     */
    public function __construct(
        ?callable $recordFactory = null,
        ?callable $clock = null,
        bool $useMemory = false,
        private readonly int $inventoryTtlSeconds = self::DEFAULT_INVENTORY_TTL_SECONDS,
        private ?InventoryCapabilityInterface $inventory = null,
    ) {
        $this->recordFactory = $recordFactory !== null ? \Closure::fromCallable($recordFactory) : null;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
        if ($useMemory) {
            $this->byHangId = [];
            $this->byOrderRef = [];
        }
    }

    public static function forTesting(
        ?callable $clock = null,
        int $inventoryTtlSeconds = self::DEFAULT_INVENTORY_TTL_SECONDS,
        ?InventoryCapabilityInterface $inventory = null,
    ): self {
        return new self(
            clock: $clock,
            useMemory: true,
            inventoryTtlSeconds: $inventoryTtlSeconds,
            inventory: $inventory,
        );
    }

    public function isMemory(): bool
    {
        return $this->byHangId !== null;
    }

    /** @return list<array<string,mixed>> */
    public function refunds(): array
    {
        return $this->refunds;
    }

    /** @return list<array<string,mixed>> */
    public function eventPayloads(): array
    {
        return $this->eventPayloads;
    }

    /**
     * @param array{
     *   order_ref:string,
     *   customer_id:string,
     *   website_id:int,
     *   goods_subtotal_taxed_minor:int,
     *   shipping_amount_minor?:int,
     *   is_shipping_owner?:bool,
     *   token_ids?:list<string>,
     *   group_id?:?string,
     *   price_list_id?:?string,
     *   list_version?:?int,
     *   deposit_ratio_bps?:int,
     *   deposit_intent_code?:?string
     * } $input
     * @return array<string,mixed>
     */
    public function createAwaitingDeposit(array $input): array
    {
        $orderRef = trim((string)($input['order_ref'] ?? ''));
        $customerId = trim((string)($input['customer_id'] ?? ''));
        $websiteId = (int)($input['website_id'] ?? -1);
        $goods = (int)($input['goods_subtotal_taxed_minor'] ?? -1);
        $shipping = max(0, (int)($input['shipping_amount_minor'] ?? 0));
        $isOwner = (bool)($input['is_shipping_owner'] ?? false);
        $ratio = (int)($input['deposit_ratio_bps'] ?? B2BOrderHang::DEPOSIT_RATIO_BPS);
        $tokenIds = array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string)$id),
            is_array($input['token_ids'] ?? null) ? $input['token_ids'] : [],
        )));

        if ($orderRef === '' || $customerId === '' || $websiteId < 0 || $goods < 0) {
            throw new B2BConflictException(self::ERROR_INVALID, __('B2B hang 创建参数非法'));
        }
        if ($this->getByOrderRef($orderRef) !== null) {
            throw new B2BConflictException(
                self::ERROR_ALREADY_EXISTS,
                __('B2B hang 已存在'),
                ['order_ref' => $orderRef],
            );
        }

        $deposit = B2BOrderHang::computeDepositMinor($goods, $ratio);
        $remainingGoods = max(0, $goods - $deposit);
        $balance = $remainingGoods + ($isOwner ? $shipping : 0);
        $now = ($this->clock)();
        $hang = new B2BOrderHang(
            hangId: 'hang_' . bin2hex(random_bytes(12)),
            orderRef: $orderRef,
            customerId: $customerId,
            websiteId: $websiteId,
            hangStatus: B2BOrderHang::STATUS_AWAITING_DEPOSIT,
            goodsSubtotalTaxedMinor: $goods,
            depositAmountMinor: $deposit,
            balanceAmountMinor: $balance,
            shippingAmountMinor: $shipping,
            isShippingOwner: $isOwner,
            depositRatioBps: $ratio,
            tokenIds: $tokenIds,
            groupId: $this->optionalString($input, 'group_id'),
            priceListId: $this->optionalString($input, 'price_list_id'),
            listVersion: isset($input['list_version']) ? (int)$input['list_version'] : null,
            depositIntentCode: $this->optionalString($input, 'deposit_intent_code'),
            createdAtEpoch: $now,
            updatedAtEpoch: $now,
        );
        $this->put($hang);
        $this->recordEvent($hang);

        return $hang->toArray();
    }

    /** Deposit Payment Intent paid → reserve inventory → awaiting_merchant_approval. */
    public function onDepositPaid(
        string $orderRef,
        string $depositIntentCode,
        ?callable $reserveInventory = null,
    ): array {
        $hang = $this->requireByOrderRef($orderRef);
        if ($hang->hangStatus !== B2BOrderHang::STATUS_AWAITING_DEPOSIT) {
            throw new B2BConflictException(
                self::ERROR_INVALID_STATE,
                __('仅 awaiting_deposit 可确认定金'),
                ['hang_status' => $hang->hangStatus],
            );
        }

        $now = ($this->clock)();
        $reservations = [];
        if ($reserveInventory !== null) {
            $result = $reserveInventory($hang);
            $reservations = is_array($result) ? array_values($result) : [];
        } elseif ($this->inventory !== null) {
            // Production path leaves reservation to caller; memory tests inject callable.
            $reservations = [];
        }

        $updated = $hang->with(
            hangStatus: B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL,
            depositIntentCode: $depositIntentCode,
            reservationSnapshots: $reservations,
            inventoryReservedAtEpoch: $now,
            inventoryExpiresAtEpoch: $now + max(1, $this->inventoryTtlSeconds),
            updatedAtEpoch: $now,
        );
        $this->put($updated);
        $this->recordEvent($updated);

        return $updated->toArray();
    }

    /** Merchant approve only when awaiting_merchant_approval → awaiting_balance. */
    public function approve(string $hangIdOrOrderRef, ?string $balanceIntentCode = null): array
    {
        $hang = $this->requireFlexible($hangIdOrOrderRef);
        if ($hang->hangStatus !== B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL) {
            throw new B2BConflictException(
                self::ERROR_INVALID_STATE,
                __('仅 awaiting_merchant_approval 可审批'),
                ['hang_status' => $hang->hangStatus],
            );
        }
        $updated = $hang->with(
            hangStatus: B2BOrderHang::STATUS_AWAITING_BALANCE,
            balanceIntentCode: $balanceIntentCode,
            updatedAtEpoch: ($this->clock)(),
        );
        $this->put($updated);
        $this->recordEvent($updated);

        return $updated->toArray();
    }

    /**
     * Reject before balance: refund deposit + release inventory.
     *
     * @param callable(B2BOrderHang): void|null $releaseInventory
     * @param callable(B2BOrderHang): void|null $refundDeposit
     */
    public function reject(
        string $hangIdOrOrderRef,
        ?callable $releaseInventory = null,
        ?callable $refundDeposit = null,
        string $notes = '',
    ): array {
        $hang = $this->requireFlexible($hangIdOrOrderRef);
        if (!in_array($hang->hangStatus, [
            B2BOrderHang::STATUS_AWAITING_DEPOSIT,
            B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL,
        ], true)) {
            throw new B2BConflictException(
                self::ERROR_INVALID_STATE,
                __('尾款开始后不可驳回'),
                ['hang_status' => $hang->hangStatus],
            );
        }

        if ($hang->hangStatus === B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL
            || $hang->depositIntentCode !== null
        ) {
            if ($refundDeposit !== null) {
                $refundDeposit($hang);
            } else {
                $this->refunds[] = [
                    'order_ref' => $hang->orderRef,
                    'deposit_intent_code' => $hang->depositIntentCode,
                    'amount_minor' => $hang->depositAmountMinor,
                    'notes' => $notes,
                ];
            }
            if ($releaseInventory !== null) {
                $releaseInventory($hang);
            }
        }

        $updated = $hang->with(
            hangStatus: B2BOrderHang::STATUS_REJECTED,
            reservationSnapshots: [],
            inventoryReservedAtEpoch: null,
            inventoryExpiresAtEpoch: null,
            updatedAtEpoch: ($this->clock)(),
        );
        // with() keeps previous reservation via ?? — force clear via new instance
        $updated = new B2BOrderHang(
            hangId: $updated->hangId,
            orderRef: $updated->orderRef,
            customerId: $updated->customerId,
            websiteId: $updated->websiteId,
            hangStatus: B2BOrderHang::STATUS_REJECTED,
            goodsSubtotalTaxedMinor: $updated->goodsSubtotalTaxedMinor,
            depositAmountMinor: $updated->depositAmountMinor,
            balanceAmountMinor: $updated->balanceAmountMinor,
            shippingAmountMinor: $updated->shippingAmountMinor,
            isShippingOwner: $updated->isShippingOwner,
            depositRatioBps: $updated->depositRatioBps,
            tokenIds: $updated->tokenIds,
            groupId: $updated->groupId,
            priceListId: $updated->priceListId,
            listVersion: $updated->listVersion,
            depositIntentCode: $updated->depositIntentCode,
            balanceIntentCode: $updated->balanceIntentCode,
            reservationSnapshots: [],
            inventoryReservedAtEpoch: null,
            inventoryExpiresAtEpoch: null,
            createdAtEpoch: $updated->createdAtEpoch,
            updatedAtEpoch: $updated->updatedAtEpoch,
        );
        $this->put($updated);
        $this->recordEvent($updated);

        return $updated->toArray() + ['notes' => $notes];
    }

    public function onBalancePaid(string $orderRef, string $balanceIntentCode): array
    {
        $hang = $this->requireByOrderRef($orderRef);
        if ($hang->hangStatus !== B2BOrderHang::STATUS_AWAITING_BALANCE) {
            throw new B2BConflictException(
                self::ERROR_INVALID_STATE,
                __('仅 awaiting_balance 可确认尾款'),
                ['hang_status' => $hang->hangStatus],
            );
        }
        $updated = $hang->with(
            hangStatus: B2BOrderHang::STATUS_COMPLETED,
            balanceIntentCode: $balanceIntentCode,
            updatedAtEpoch: ($this->clock)(),
        );
        $this->put($updated);
        $this->recordEvent($updated);

        return $updated->toArray();
    }

    /**
     * SPI bridge from Payment Intent. Prefer explicit purpose; fall back to intent data bags.
     *
     * @param array<string,mixed> $metadata
     */
    public function onPaymentIntentLifecycle(
        PaymentIntent $intent,
        array $metadata = [],
    ): ?array {
        $payableId = trim((string)$intent->getData(PaymentIntent::schema_fields_PAYABLE_ID));
        if ($metadata === []) {
            foreach (['metadata', 'terms_snapshot', 'config_snapshot', 'amount_snapshot'] as $field) {
                $raw = $intent->getData($field);
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $metadata = $decoded;
                        break;
                    }
                } elseif (is_array($raw)) {
                    $metadata = $raw;
                    break;
                }
            }
        }
        $purpose = strtolower(trim((string)($metadata['purpose'] ?? $metadata['hang_purpose'] ?? '')));
        $intentCode = (string)$intent->getData(PaymentIntent::schema_fields_INTENT_CODE);
        if ($payableId === '' || $purpose === '') {
            return null;
        }
        if ($purpose === self::PURPOSE_DEPOSIT) {
            return $this->onDepositPaid($payableId, $intentCode);
        }
        if ($purpose === self::PURPOSE_BALANCE) {
            return $this->onBalancePaid($payableId, $intentCode);
        }

        return null;
    }

    public function getByOrderRef(string $orderRef): ?B2BOrderHang
    {
        $orderRef = trim($orderRef);
        if ($orderRef === '') {
            return null;
        }
        if ($this->byOrderRef !== null) {
            $hangId = $this->byOrderRef[$orderRef] ?? null;

            return $hangId !== null ? ($this->byHangId[$hangId] ?? null) : null;
        }
        $model = $this->newRecord();
        $model->clear()->where(B2BOrderHangRecord::schema_fields_ORDER_REF, $orderRef)->find()->fetch();
        if (!$model->getId()) {
            return null;
        }

        return $this->hydrate($model->getData());
    }

    public function get(string $hangId): ?B2BOrderHang
    {
        $hangId = trim($hangId);
        if ($hangId === '') {
            return null;
        }
        if ($this->byHangId !== null) {
            return $this->byHangId[$hangId] ?? null;
        }
        $model = $this->newRecord();
        $model->clear()->where(B2BOrderHangRecord::schema_fields_HANG_ID, $hangId)->find()->fetch();
        if (!$model->getId()) {
            return null;
        }

        return $this->hydrate($model->getData());
    }

    /** @return array<string,mixed> */
    public function typePayloadForOrder(string $orderRef): array
    {
        $hang = $this->getByOrderRef($orderRef);

        return $hang !== null ? $hang->typePayload() : [];
    }

    private function requireByOrderRef(string $orderRef): B2BOrderHang
    {
        $hang = $this->getByOrderRef($orderRef);
        if ($hang === null) {
            throw new B2BConflictException(self::ERROR_NOT_FOUND, __('B2B hang 不存在'), ['order_ref' => $orderRef]);
        }

        return $hang;
    }

    private function requireFlexible(string $hangIdOrOrderRef): B2BOrderHang
    {
        $key = trim($hangIdOrOrderRef);
        $hang = $this->get($key) ?? $this->getByOrderRef($key);
        if ($hang === null) {
            throw new B2BConflictException(self::ERROR_NOT_FOUND, __('B2B hang 不存在'), ['key' => $key]);
        }

        return $hang;
    }

    private function put(B2BOrderHang $hang): void
    {
        if ($this->byHangId !== null) {
            $this->byHangId[$hang->hangId] = $hang;
            $this->byOrderRef[$hang->orderRef] = $hang->hangId;

            return;
        }
        $existing = $this->newRecord();
        $existing->clear()->where(B2BOrderHangRecord::schema_fields_HANG_ID, $hang->hangId)->find()->fetch();
        $model = $existing->getId() ? $existing : $this->newRecord();
        $model->setData($this->recordData($hang))->save();
    }

    /** @return array<string,mixed> */
    private function recordData(B2BOrderHang $hang): array
    {
        return [
            B2BOrderHangRecord::schema_fields_HANG_ID => $hang->hangId,
            B2BOrderHangRecord::schema_fields_ORDER_REF => $hang->orderRef,
            B2BOrderHangRecord::schema_fields_CUSTOMER_ID => $hang->customerId,
            B2BOrderHangRecord::schema_fields_WEBSITE_ID => $hang->websiteId,
            B2BOrderHangRecord::schema_fields_HANG_STATUS => $hang->hangStatus,
            B2BOrderHangRecord::schema_fields_GOODS_SUBTOTAL_TAXED_MINOR => $hang->goodsSubtotalTaxedMinor,
            B2BOrderHangRecord::schema_fields_DEPOSIT_AMOUNT_MINOR => $hang->depositAmountMinor,
            B2BOrderHangRecord::schema_fields_BALANCE_AMOUNT_MINOR => $hang->balanceAmountMinor,
            B2BOrderHangRecord::schema_fields_SHIPPING_AMOUNT_MINOR => $hang->shippingAmountMinor,
            B2BOrderHangRecord::schema_fields_IS_SHIPPING_OWNER => $hang->isShippingOwner ? 1 : 0,
            B2BOrderHangRecord::schema_fields_DEPOSIT_RATIO_BPS => $hang->depositRatioBps,
            B2BOrderHangRecord::schema_fields_TOKEN_IDS_JSON => json_encode(
                $hang->tokenIds,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            B2BOrderHangRecord::schema_fields_GROUP_ID => $hang->groupId,
            B2BOrderHangRecord::schema_fields_PRICE_LIST_ID => $hang->priceListId,
            B2BOrderHangRecord::schema_fields_LIST_VERSION => $hang->listVersion,
            B2BOrderHangRecord::schema_fields_DEPOSIT_INTENT_CODE => $hang->depositIntentCode,
            B2BOrderHangRecord::schema_fields_BALANCE_INTENT_CODE => $hang->balanceIntentCode,
            B2BOrderHangRecord::schema_fields_RESERVATION_SNAPSHOTS_JSON => json_encode(
                $hang->reservationSnapshots,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            B2BOrderHangRecord::schema_fields_INVENTORY_RESERVED_AT_EPOCH => $hang->inventoryReservedAtEpoch,
            B2BOrderHangRecord::schema_fields_INVENTORY_EXPIRES_AT_EPOCH => $hang->inventoryExpiresAtEpoch,
            B2BOrderHangRecord::schema_fields_CREATED_AT_EPOCH => $hang->createdAtEpoch,
            B2BOrderHangRecord::schema_fields_UPDATED_AT_EPOCH => $hang->updatedAtEpoch,
            B2BOrderHangRecord::schema_fields_CREATED_AT => gmdate('Y-m-d H:i:s', $hang->createdAtEpoch),
            B2BOrderHangRecord::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s', $hang->updatedAtEpoch),
        ];
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): B2BOrderHang
    {
        $tokens = json_decode((string)($row[B2BOrderHangRecord::schema_fields_TOKEN_IDS_JSON] ?? '[]'), true);
        $reservations = json_decode(
            (string)($row[B2BOrderHangRecord::schema_fields_RESERVATION_SNAPSHOTS_JSON] ?? '[]'),
            true,
        );

        return new B2BOrderHang(
            hangId: (string)$row[B2BOrderHangRecord::schema_fields_HANG_ID],
            orderRef: (string)$row[B2BOrderHangRecord::schema_fields_ORDER_REF],
            customerId: (string)$row[B2BOrderHangRecord::schema_fields_CUSTOMER_ID],
            websiteId: (int)$row[B2BOrderHangRecord::schema_fields_WEBSITE_ID],
            hangStatus: (string)$row[B2BOrderHangRecord::schema_fields_HANG_STATUS],
            goodsSubtotalTaxedMinor: (int)$row[B2BOrderHangRecord::schema_fields_GOODS_SUBTOTAL_TAXED_MINOR],
            depositAmountMinor: (int)$row[B2BOrderHangRecord::schema_fields_DEPOSIT_AMOUNT_MINOR],
            balanceAmountMinor: (int)$row[B2BOrderHangRecord::schema_fields_BALANCE_AMOUNT_MINOR],
            shippingAmountMinor: (int)$row[B2BOrderHangRecord::schema_fields_SHIPPING_AMOUNT_MINOR],
            isShippingOwner: (bool)$row[B2BOrderHangRecord::schema_fields_IS_SHIPPING_OWNER],
            depositRatioBps: (int)$row[B2BOrderHangRecord::schema_fields_DEPOSIT_RATIO_BPS],
            tokenIds: is_array($tokens) ? array_values(array_map('strval', $tokens)) : [],
            groupId: $this->optionalString($row, B2BOrderHangRecord::schema_fields_GROUP_ID),
            priceListId: $this->optionalString($row, B2BOrderHangRecord::schema_fields_PRICE_LIST_ID),
            listVersion: isset($row[B2BOrderHangRecord::schema_fields_LIST_VERSION])
                && $row[B2BOrderHangRecord::schema_fields_LIST_VERSION] !== ''
                && $row[B2BOrderHangRecord::schema_fields_LIST_VERSION] !== null
                ? (int)$row[B2BOrderHangRecord::schema_fields_LIST_VERSION]
                : null,
            depositIntentCode: $this->optionalString($row, B2BOrderHangRecord::schema_fields_DEPOSIT_INTENT_CODE),
            balanceIntentCode: $this->optionalString($row, B2BOrderHangRecord::schema_fields_BALANCE_INTENT_CODE),
            reservationSnapshots: is_array($reservations) ? array_values($reservations) : [],
            inventoryReservedAtEpoch: isset($row[B2BOrderHangRecord::schema_fields_INVENTORY_RESERVED_AT_EPOCH])
                && $row[B2BOrderHangRecord::schema_fields_INVENTORY_RESERVED_AT_EPOCH] !== null
                && $row[B2BOrderHangRecord::schema_fields_INVENTORY_RESERVED_AT_EPOCH] !== ''
                ? (int)$row[B2BOrderHangRecord::schema_fields_INVENTORY_RESERVED_AT_EPOCH]
                : null,
            inventoryExpiresAtEpoch: isset($row[B2BOrderHangRecord::schema_fields_INVENTORY_EXPIRES_AT_EPOCH])
                && $row[B2BOrderHangRecord::schema_fields_INVENTORY_EXPIRES_AT_EPOCH] !== null
                && $row[B2BOrderHangRecord::schema_fields_INVENTORY_EXPIRES_AT_EPOCH] !== ''
                ? (int)$row[B2BOrderHangRecord::schema_fields_INVENTORY_EXPIRES_AT_EPOCH]
                : null,
            createdAtEpoch: (int)$row[B2BOrderHangRecord::schema_fields_CREATED_AT_EPOCH],
            updatedAtEpoch: (int)$row[B2BOrderHangRecord::schema_fields_UPDATED_AT_EPOCH],
        );
    }

    private function recordEvent(B2BOrderHang $hang): void
    {
        $this->eventPayloads[] = [
            'order_type' => 'tob',
            'type_payload' => $hang->typePayload(),
            'order_ref' => $hang->orderRef,
        ];
    }

    private function newRecord(): B2BOrderHangRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(B2BOrderHangRecord::class, [], false);
    }

    /** @param array<string,mixed> $data */
    private function optionalString(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;

        return $value !== null && $value !== '' ? (string)$value : null;
    }
}
