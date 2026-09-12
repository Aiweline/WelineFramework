<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\B2BOrderHang;
use Weline\B2B\Model\B2BOrderHangRecord;
use Weline\Framework\Event\EventsManager;
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

    /** Framework Event — see doc/event/hang_status_changed.md */
    public const EVENT_HANG_STATUS_CHANGED = 'Weline_B2B::hang_status_changed';

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

    /**
     * Reconcile hang after PSP success (sync Checkout, browser return, or already_paid).
     * Idempotent when hang already past the target stage for the given purpose.
     *
     * @return array<string,mixed>|null
     */
    public function reconcilePaymentSuccess(string $orderRef, string $purpose, string $intentCode): ?array
    {
        $orderRef = trim($orderRef);
        $purpose = strtolower(trim($purpose));
        $intentCode = trim($intentCode);
        if ($orderRef === '' || $intentCode === '') {
            return null;
        }
        if ($purpose === self::PURPOSE_DEPOSIT) {
            return $this->onDepositPaid($orderRef, $intentCode);
        }
        if ($purpose === self::PURPOSE_BALANCE) {
            return $this->onBalancePaid($orderRef, $intentCode);
        }

        return null;
    }

    /** Deposit Payment Intent paid → reserve inventory → awaiting_merchant_approval. */
    public function onDepositPaid(
        string $orderRef,
        string $depositIntentCode,
        ?callable $reserveInventory = null,
    ): array {
        $hang = $this->requireByOrderRef($orderRef);
        // Idempotent: already past deposit (approve / balance / completed).
        if (in_array($hang->hangStatus, [
            B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL,
            B2BOrderHang::STATUS_AWAITING_BALANCE,
            B2BOrderHang::STATUS_COMPLETED,
        ], true)) {
            return $hang->toArray();
        }
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
        } else {
            $reservations = $this->reserveInventoryForHang($hang);
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
        $this->commitB2bCreditOnDepositPaid($orderRef, $depositIntentCode);

        return $updated->toArray();
    }

    /**
     * Production path: reserve via InventoryCapabilityInterface after deposit.
     *
     * @return list<array<string,mixed>>
     */
    private function reserveInventoryForHang(B2BOrderHang $hang): array
    {
        $inventory = $this->inventory;
        if ($inventory === null && class_exists(InventoryCapabilityInterface::class)) {
            try {
                $resolved = ObjectManager::getInstance(InventoryCapabilityInterface::class);
                $inventory = $resolved instanceof InventoryCapabilityInterface ? $resolved : null;
            } catch (\Throwable) {
                $inventory = null;
            }
        }
        if ($inventory === null || !class_exists(\Weline\Order\Api\OrderFacadeInterface::class)) {
            return [];
        }
        try {
            $orders = ObjectManager::getInstance(\Weline\Order\Api\OrderFacadeInterface::class);
            if (!$orders instanceof \Weline\Order\Api\OrderFacadeInterface) {
                return [];
            }
            $read = $orders->get($hang->orderRef);
            $snapshots = [];
            foreach ($read->items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!(bool)($item['requires_shipping'] ?? true)) {
                    continue;
                }
                $offerId = (int)($item['offer_id'] ?? 0);
                $qty = max(0, (int)($item['qty_minor'] ?? 0));
                if ($offerId <= 0 || $qty <= 0) {
                    continue;
                }
                $lineKey = trim((string)($item['line_uuid'] ?? $item['order_item_uuid'] ?? ('idx' . $index)));
                $idempotencyKey = 'hang_' . $hang->orderRef . '_' . ($lineKey !== '' ? $lineKey : (string)$offerId);
                $requestHash = hash(
                    'sha256',
                    json_encode([
                        'order_ref' => $hang->orderRef,
                        'offer_id' => $offerId,
                        'quantity_minor' => $qty,
                        'website_id' => $read->websiteId,
                        'store_id' => $read->storeId,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                );
                $result = $inventory->reserve(
                    $read->websiteId,
                    $read->storeId,
                    $offerId,
                    $qty,
                    $idempotencyKey,
                    $requestHash,
                );
                $snapshots[] = $result->toArray() + [
                    'offer_id' => $offerId,
                    'line_key' => $lineKey,
                ];
            }

            return $snapshots;
        } catch (\Throwable) {
            return [];
        }
    }

    private function commitB2bCreditOnDepositPaid(string $orderRef, string $depositIntentCode): void
    {
        if (!class_exists(\Weline\Order\Api\OrderFacadeInterface::class)
            || !class_exists(B2BDepositCreditOrchestrator::class)
        ) {
            return;
        }
        try {
            $orders = ObjectManager::getInstance(\Weline\Order\Api\OrderFacadeInterface::class);
            if (!$orders instanceof \Weline\Order\Api\OrderFacadeInterface) {
                return;
            }
            $read = $orders->get($orderRef);
            $typePayload = $read->typePayload;
            if (($typePayload['discount_kind'] ?? '') !== 'asset_b2b_credit') {
                return;
            }
            $orch = ObjectManager::getInstance(B2BDepositCreditOrchestrator::class);
            if (!$orch instanceof B2BDepositCreditOrchestrator) {
                return;
            }
            $result = $orch->commitOnDepositPaid($typePayload, $orderRef, $depositIntentCode);
            if ($orders instanceof \Weline\Order\Api\OrderFacadeInterface) {
                $orders->mergeTypePayload($orderRef, $result['type_payload']);
            }
        } catch (\Throwable) {
            // Soft-fail: hang state already advanced; credit reconcile later.
        }
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

    public function onBalancePaid(string $orderRef, string $balanceIntentCode, ?int $paidAmountMinor = null): array
    {
        $hang = $this->requireByOrderRef($orderRef);
        // Idempotent: already completed.
        if ($hang->hangStatus === B2BOrderHang::STATUS_COMPLETED) {
            return $hang->toArray();
        }
        if ($hang->hangStatus !== B2BOrderHang::STATUS_AWAITING_BALANCE) {
            throw new B2BConflictException(
                self::ERROR_INVALID_STATE,
                __('仅 awaiting_balance 可确认尾款'),
                ['hang_status' => $hang->hangStatus],
            );
        }
        $this->assertNoPendingBalanceRevision($orderRef);
        if ($paidAmountMinor !== null && $paidAmountMinor !== $hang->balanceAmountMinor) {
            throw new B2BConflictException(
                self::ERROR_INVALID_STATE,
                __('尾款支付金额与当前应付不一致'),
                [
                    'expected_balance_minor' => $hang->balanceAmountMinor,
                    'paid_amount_minor' => $paidAmountMinor,
                ],
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
     * Merchant proposes a new balance while awaiting_balance.
     *
     * @return array<string,mixed>
     */
    public function proposeBalanceRevision(string $orderRef, int $newBalanceMinor, int $expectedVersion = 0): array
    {
        $hang = $this->requireByOrderRef($orderRef);
        if ($hang->hangStatus !== B2BOrderHang::STATUS_AWAITING_BALANCE) {
            throw new B2BConflictException(
                self::ERROR_INVALID_STATE,
                __('仅 awaiting_balance 可提议尾款改价'),
                ['hang_status' => $hang->hangStatus],
            );
        }
        $newBalanceMinor = max(0, $newBalanceMinor);
        $currentVersion = $this->readRevisionVersion($orderRef);
        if ($expectedVersion > 0 && $expectedVersion !== $currentVersion) {
            throw new B2BConflictException(
                self::ERROR_INVALID_STATE,
                __('尾款改价版本冲突'),
                ['expected' => $expectedVersion, 'current' => $currentVersion],
            );
        }
        $nextVersion = $currentVersion + 1;
        $this->patchOrderRevision($orderRef, [
            'hang_revision_pending' => true,
            'hang_revision_version' => $nextVersion,
            'hang_revision_proposed_balance_minor' => $newBalanceMinor,
            'hang_revision_previous_balance_minor' => $hang->balanceAmountMinor,
        ]);

        return [
            'order_ref' => $orderRef,
            'hang_status' => $hang->hangStatus,
            'revision_pending' => true,
            'revision_version' => $nextVersion,
            'proposed_balance_minor' => $newBalanceMinor,
            'current_balance_minor' => $hang->balanceAmountMinor,
        ];
    }

    /**
     * Buyer confirms pending balance revision → update hang + OrderFacade payable.
     *
     * @return array<string,mixed>
     */
    public function confirmBalanceRevision(string $orderRef, int $expectedVersion): array
    {
        $hang = $this->requireByOrderRef($orderRef);
        if ($hang->hangStatus !== B2BOrderHang::STATUS_AWAITING_BALANCE) {
            throw new B2BConflictException(
                self::ERROR_INVALID_STATE,
                __('仅 awaiting_balance 可确认尾款改价'),
                ['hang_status' => $hang->hangStatus],
            );
        }
        $tp = $this->readTypePayload($orderRef);
        if (!(bool)($tp['hang_revision_pending'] ?? false)) {
            throw new B2BConflictException(self::ERROR_INVALID_STATE, __('没有待确认的尾款改价'));
        }
        $version = (int)($tp['hang_revision_version'] ?? 0);
        if ($expectedVersion !== $version) {
            throw new B2BConflictException(
                self::ERROR_INVALID_STATE,
                __('尾款改价版本冲突'),
                ['expected' => $expectedVersion, 'current' => $version],
            );
        }
        $newBalance = max(0, (int)($tp['hang_revision_proposed_balance_minor'] ?? -1));
        if (!array_key_exists('hang_revision_proposed_balance_minor', $tp)) {
            throw new B2BConflictException(self::ERROR_INVALID, __('尾款改价金额无效'));
        }
        $updated = $hang->with(
            balanceAmountMinor: $newBalance,
            updatedAtEpoch: ($this->clock)(),
        );
        $this->put($updated);
        $this->recordEvent($updated);

        if (class_exists(\Weline\Order\Api\OrderFacadeInterface::class)) {
            try {
                $orders = ObjectManager::getInstance(\Weline\Order\Api\OrderFacadeInterface::class);
                if ($orders instanceof \Weline\Order\Api\OrderFacadeInterface) {
                    $orders->reviseTobHangPayable($orderRef, [
                        'balance_amount_minor' => $newBalance,
                        'revision_version' => $version,
                        'revision_pending' => false,
                        'audit' => [
                            'previous_balance_minor' => $hang->balanceAmountMinor,
                            'confirmed_at_epoch' => ($this->clock)(),
                        ],
                    ]);
                }
            } catch (\Throwable) {
                // Soft-fail: hang already updated; Order projection can reconcile later.
            }
        }

        $this->appendRevisionSystemMessage(
            $orderRef,
            (string)$hang->customerId,
            (int)$hang->websiteId,
            $hang->hangId,
            (int)$hang->balanceAmountMinor,
            $newBalance,
            $version,
        );

        return $updated->toArray() + [
            'revision_pending' => false,
            'revision_version' => $version,
        ];
    }

    private function appendRevisionSystemMessage(
        string $orderRef,
        string $customerId,
        int $websiteId,
        ?string $hangId,
        int $previousBalance,
        int $newBalance,
        int $version,
    ): void {
        try {
            $chat = ObjectManager::getInstance(B2BOrderThreadService::class);
            if (!$chat instanceof B2BOrderThreadService) {
                return;
            }
            $thread = $chat->openOrCreate($orderRef, $customerId, $websiteId, $hangId);
            $body = (string)__(
                '尾款已确认改价：%{1} → %{2}（版本 %{3}）',
                (string)$previousBalance,
                (string)$newBalance,
                (string)$version,
            );
            $chat->send(
                (string)$thread['thread_id'],
                \Weline\B2B\Model\B2BOrderMessageRecord::ROLE_SYSTEM,
                $body,
            );
        } catch (\Throwable) {
            // Chat is optional relative to hang payable authority.
        }
    }

    private function assertNoPendingBalanceRevision(string $orderRef): void
    {
        $tp = $this->readTypePayload($orderRef);
        if ((bool)($tp['hang_revision_pending'] ?? false)) {
            throw new B2BConflictException(
                self::ERROR_INVALID_STATE,
                __('尾款改价待确认，暂不可完结尾款'),
                ['order_ref' => $orderRef],
            );
        }
    }

    private function readRevisionVersion(string $orderRef): int
    {
        return max(0, (int)($this->readTypePayload($orderRef)['hang_revision_version'] ?? 0));
    }

    /** @return array<string,mixed> */
    private function readTypePayload(string $orderRef): array
    {
        if (!class_exists(\Weline\Order\Api\OrderFacadeInterface::class)) {
            return [];
        }
        try {
            $orders = ObjectManager::getInstance(\Weline\Order\Api\OrderFacadeInterface::class);
            if (!$orders instanceof \Weline\Order\Api\OrderFacadeInterface) {
                return [];
            }

            return $orders->get($orderRef)->typePayload;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $patch */
    private function patchOrderRevision(string $orderRef, array $patch): void
    {
        if (!class_exists(\Weline\Order\Api\OrderFacadeInterface::class)) {
            return;
        }
        try {
            $orders = ObjectManager::getInstance(\Weline\Order\Api\OrderFacadeInterface::class);
            if ($orders instanceof \Weline\Order\Api\OrderFacadeInterface) {
                $orders->mergeTypePayload($orderRef, $patch);
            }
        } catch (\Throwable) {
            // Soft-fail when Order absent in hang-only fixtures.
        }
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

    /**
     * Storefront hub: hangs for one customer (website 0 matches any site).
     *
     * @return list<array<string,mixed>>
     */
    public function listForCustomer(string $customerId, int $websiteId = 0): array
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return [];
        }
        $out = [];
        if ($this->byHangId !== null) {
            foreach ($this->byHangId as $hang) {
                if ((string)$hang->customerId !== $customerId) {
                    continue;
                }
                if ($websiteId > 0 && $hang->websiteId > 0 && $hang->websiteId !== $websiteId) {
                    continue;
                }
                $out[] = $hang->toArray();
            }
            usort(
                $out,
                static fn (array $a, array $b): int => ((int)($b['updated_at_epoch'] ?? 0)) <=> ((int)($a['updated_at_epoch'] ?? 0)),
            );

            return $out;
        }
        try {
            $model = $this->newRecord();
            $model->clear()
                ->where(B2BOrderHangRecord::schema_fields_CUSTOMER_ID, $customerId)
                ->order(B2BOrderHangRecord::schema_fields_UPDATED_AT_EPOCH, 'DESC')
                ->select()
                ->fetch();
            foreach ($model->getItems() ?? [] as $row) {
                if (!$row instanceof B2BOrderHangRecord) {
                    continue;
                }
                $hang = $this->hydrate($row->getData());
                if ($websiteId > 0 && $hang->websiteId > 0 && $hang->websiteId !== $websiteId) {
                    continue;
                }
                $out[] = $hang->toArray();
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
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
        $payload = [
            'order_type' => 'tob',
            'type_payload' => $hang->typePayload(),
            'order_ref' => $hang->orderRef,
            'hang_id' => $hang->hangId,
            'hang_status' => $hang->hangStatus,
            'website_id' => $hang->websiteId,
        ];
        $this->eventPayloads[] = $payload;
        try {
            if (!class_exists(EventsManager::class)) {
                return;
            }
            $events = ObjectManager::getInstance(EventsManager::class);
            if ($events instanceof EventsManager) {
                $events->dispatch(self::EVENT_HANG_STATUS_CHANGED, $payload);
            }
        } catch (\Throwable) {
            // Soft-fail: hang persistence already succeeded.
        }
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
