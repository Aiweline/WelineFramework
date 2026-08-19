<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Inventory\Api\DefaultWarehouseResolverInterface;
use Weline\Inventory\Api\InventoryConflictException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Api\Data\CreateCheckoutGroupResult;
use Weline\Order\Api\Data\CatalogSnapshot;
use Weline\Order\Api\Data\MoneySnapshot;
use Weline\Order\Api\Data\OrderPaidContext;
use Weline\Order\Api\Data\OrderPlan;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\Data\ScopeSnapshot;
use Weline\Order\Api\Data\ShippingSnapshot;
use Weline\Order\Api\Data\TaxSnapshot;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Order\Api\OrderPostPaymentHookInterface;
use Weline\Order\Model\CheckoutGroup;
use Weline\Order\Model\DisplayNumberRegistry;

/**
 * Order Facade（MOD-P2D-001/002/003）.
 *
 * - plan()：纯计算拆单 / 运费 owner（DEC-015），零写
 * - create()：唯一 writer；idempotency；不可变快照；kind 展示号；组不变式；失败整组回滚
 * - get()：按 Order UUID 只读投影
 *
 * forTesting() 内存账本；DB Model（CheckoutGroup/FulfillmentUnit/DisplayNumberRegistry）已 additive 就绪。
 */
final class OrderFacade implements OrderFacadeInterface
{
    public const STATUS_PENDING = 'pending';
    public const ERROR_HASH_CONFLICT = 'order_request_hash_conflict';
    public const ERROR_EMPTY_LINES = 'order_lines_empty';
    public const ERROR_NOT_FOUND = 'order_not_found';
    public const ERROR_INVALID_SCOPE = 'order_invalid_scope';
    public const ERROR_INVALID_COMMAND = 'order_command_invalid';
    public const ERROR_AMOUNT_OVERFLOW = 'order_amount_overflow';
    public const ERROR_COMMIT_FAILED = 'order_group_commit_failed';
    private const MAX_SCOPE_ID = 2_147_483_647;
    private const MAX_LINES = 10_000;

    /**
     * @var array{
     *   by_idem: array<string, array{request_hash:string,group_uuid:string}>,
     *   groups: array<string, array<string,mixed>>,
     *   orders: array<string, array<string,mixed>>,
     *   write_count: int
     * }|null
     */
    private ?array $memory = null;

    /** Optional legacy adapter (unused in memory mode). */
    private readonly ?OrderService $orderService;

    private readonly CheckoutGroupInvariant $invariant;

    private readonly DisplayNumberAllocator $displayNumbers;

    private readonly DisplayNumberLookup $displayLookup;

    private readonly OrderPostPaymentHookInterface $postPaymentHook;

    private readonly OrderWriterGuard $writerGuard;

    private ?OrderFacadeStoreInterface $dbStoreInstance;

    private readonly ?DefaultWarehouseResolverInterface $defaultWarehouseResolver;

    /** @var (\Closure(int $orderIndex): void)|null */
    private $failAfterOrderIndex = null;

    public function __construct(
        ?OrderService $orderService = null,
        bool $useMemory = false,
        ?CheckoutGroupInvariant $invariant = null,
        ?DisplayNumberAllocator $displayNumbers = null,
        ?DisplayNumberLookup $displayLookup = null,
        ?OrderPostPaymentHookInterface $postPaymentHook = null,
        ?OrderWriterGuard $writerGuard = null,
        ?OrderFacadeStoreInterface $dbStore = null,
        ?DefaultWarehouseResolverInterface $defaultWarehouseResolver = null,
    ) {
        $this->orderService = $orderService;
        $this->invariant = $invariant ?? new CheckoutGroupInvariant();
        $this->displayNumbers = $displayNumbers ?? ($useMemory
            ? DisplayNumberAllocator::forTesting()
            : new DisplayNumberAllocator(useMemory: false));
        $this->displayLookup = $displayLookup ?? new DisplayNumberLookup($this->displayNumbers);
        $this->postPaymentHook = $postPaymentHook
            ?? ($useMemory
                ? new NoopOrderPostPaymentHook()
                : ObjectManager::getInstance(OrderPostPaymentHookInterface::class));
        $this->writerGuard = $writerGuard ?? new OrderWriterGuard();
        $this->dbStoreInstance = $dbStore;
        $this->defaultWarehouseResolver = $defaultWarehouseResolver;
        if ($useMemory) {
            $this->memory = [
                'by_idem' => [],
                'groups' => [],
                'orders' => [],
                'write_count' => 0,
            ];
        }
    }

    public static function forTesting(
        ?DisplayNumberAllocator $displayNumbers = null,
        ?OrderWriterGuard $writerGuard = null,
        ?DefaultWarehouseResolverInterface $defaultWarehouseResolver = null,
    ): self {
        $allocator = $displayNumbers ?? DisplayNumberAllocator::forTesting();
        return new self(
            null,
            useMemory: true,
            displayNumbers: $allocator,
            displayLookup: new DisplayNumberLookup($allocator),
            writerGuard: $writerGuard ?? new OrderWriterGuard(),
            defaultWarehouseResolver: $defaultWarehouseResolver,
        );
    }

    public function displayLookup(): DisplayNumberLookup
    {
        return $this->displayLookup;
    }

    public function displayNumbers(): DisplayNumberAllocator
    {
        return $this->displayNumbers;
    }

    public function postPaymentHook(): OrderPostPaymentHookInterface
    {
        return $this->postPaymentHook;
    }

    public function writerGuard(): OrderWriterGuard
    {
        return $this->writerGuard;
    }

    /** @internal TEST-P2D-03：在写入第 N 张 Order 后抛错以验证回滚 */
    public function failAfterWritingOrderIndex(int $index): void
    {
        $this->failAfterOrderIndex = static function (int $orderIndex) use ($index): void {
            if ($orderIndex === $index) {
                throw new \RuntimeException('injected_dml_failure');
            }
        };
    }

    public function plan(CreateCheckoutGroupCommand $command): OrderPlan
    {
        $this->assertCommand($command);
        $planned = $this->buildPlannedOrders($command);
        $subtotal = 0;
        $shipping = 0;
        $tax = 0;
        foreach ($planned['orders'] as $o) {
            $subtotal = $this->checkedAdd($subtotal, (int)$o['subtotal_minor']);
            $shipping = $this->checkedAdd($shipping, (int)$o['shipping_amount_minor']);
            $tax = $this->checkedAdd($tax, (int)$o['tax_amount_minor']);
        }
        $grandTotal = $this->checkedAdd($this->checkedAdd($subtotal, $shipping), $tax);

        return new OrderPlan(
            currency: $command->currency,
            websiteId: $command->websiteId,
            storeId: $command->storeId,
            orders: $planned['orders'],
            totals: [
                'subtotal_minor' => $subtotal,
                'shipping_amount_minor' => $shipping,
                'tax_amount_minor' => $tax,
                'grand_total_minor' => $grandTotal,
                'order_count' => count($planned['orders']),
            ],
            shippingChargeOwnerIndex: $planned['owner_index'],
        );
    }

    public function create(CreateCheckoutGroupCommand $command): CreateCheckoutGroupResult
    {
        $this->assertCommand($command);
        $this->writerGuard->assertNewOrderWritable('website:' . $command->websiteId);
        if ($this->memory === null) {
            return $this->createDb($command);
        }

        $key = trim($command->idempotencyKey);
        $hash = trim($command->requestHash);
        $existing = $this->memory['by_idem'][$key] ?? null;
        if ($existing !== null) {
            if ((string)$existing['request_hash'] !== $hash) {
                throw new OrderFacadeConflictException(
                    self::ERROR_HASH_CONFLICT,
                    \__('Order Facade idempotency 冲突：key=%{1}', [$key]),
                    ['idempotency_key' => $key],
                );
            }
            $group = $this->memory['groups'][$existing['group_uuid']];
            return $this->resultFromGroup($group, replayed: true);
        }

        $plan = $this->plan($command);
        $writesBefore = $this->memory['write_count'];
        $snapshotOrders = $this->memory['orders'];
        $snapshotGroups = $this->memory['groups'];
        $snapshotIdem = $this->memory['by_idem'];
        $snapshotDisplay = $this->displayNumbers->all();

        try {
            $groupUuid = $this->newUuid();
            $orderUuids = [];
            $orderRows = [];
            $ownerUuid = null;

            foreach ($plan->orders as $idx => $planned) {
                $orderUuid = $this->newUuid();
                $orderUuids[] = $orderUuid;
                $isOwner = $plan->shippingChargeOwnerIndex === $idx;
                if ($isOwner) {
                    $ownerUuid = $orderUuid;
                }

                $displayRef = $this->displayNumbers->allocate(
                    $command->websiteId,
                    $command->storeId,
                    DisplayNumberRegistry::KIND_ORDER,
                    $orderUuid,
                );

                $money = (new MoneySnapshot(
                    currency: $command->currency,
                    subtotalMinor: (int)$planned['subtotal_minor'],
                    shippingAmountMinor: (int)$planned['shipping_amount_minor'],
                    taxAmountMinor: (int)$planned['tax_amount_minor'],
                    grandTotalMinor: (int)$planned['grand_total_minor'],
                ))->withComputedGrandTotal();
                $catalog = new CatalogSnapshot($planned['items']);
                $scope = new ScopeSnapshot($command->websiteId, $command->storeId, $command->currency);
                $tax = $this->taxSnapshotFromCommand(
                    $command,
                    (int) $planned['tax_amount_minor'],
                    $planned['items'],
                );
                $shipping = new ShippingSnapshot(
                    method: $command->shippingMethod,
                    amountMinor: (int)$planned['shipping_amount_minor'],
                    chargeOwnerOrderUuid: $isOwner ? $orderUuid : null,
                    address: $command->shippingAddress,
                );

                $row = [
                    'order_uuid' => $orderUuid,
                    'checkout_group_uuid' => $groupUuid,
                    'status' => self::STATUS_PENDING,
                    'group_status' => CheckoutGroup::STATUS_PENDING,
                    'currency' => $command->currency,
                    'website_id' => $command->websiteId,
                    'store_id' => $command->storeId,
                    'customer_id' => $command->customerId,
                    'items' => $planned['items'],
                    'money' => $money->toArray(),
                    'scope' => $scope->toArray(),
                    'snapshots' => [
                        'money' => $money->toArray(),
                        'catalog' => $catalog->toArray(),
                        'scope' => $scope->toArray(),
                        'tax' => $tax->toArray(),
                        'shipping' => $shipping->toArray(),
                    ],
                    'fulfillment_units' => $this->buildFulfillmentUnits(
                        $planned['items'],
                        $groupUuid,
                        $orderUuid,
                        $command->websiteId,
                        $command->storeId,
                    ),
                    'is_shipping_charge_owner' => $isOwner,
                    'split_key' => (string)$planned['split_key'],
                    'number_kind' => $displayRef->numberKind,
                    'display_number' => $displayRef->displayNumber,
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                ];
                $this->memory['orders'][$orderUuid] = $row;
                $orderRows[] = $row;

                if ($this->failAfterOrderIndex !== null) {
                    ($this->failAfterOrderIndex)($idx);
                }
            }

            $groupMoney = MoneySnapshot::fromArray([
                'currency' => $command->currency,
                'subtotal_minor' => $plan->totals['subtotal_minor'],
                'shipping_amount_minor' => $plan->totals['shipping_amount_minor'],
                'tax_amount_minor' => $plan->totals['tax_amount_minor'],
                'grand_total_minor' => $plan->totals['grand_total_minor'],
            ]);
            $groupShipping = new ShippingSnapshot(
                method: $command->shippingMethod,
                amountMinor: (int)$plan->totals['shipping_amount_minor'],
                chargeOwnerOrderUuid: $ownerUuid,
                address: $command->shippingAddress,
            );

            $this->invariant->assertMoneyConservation($orderRows, $plan->totals);
            $this->invariant->assertSingleShippingOwner(
                $orderRows,
                (int)$plan->totals['shipping_amount_minor'],
                $ownerUuid,
            );

            $group = [
                'checkout_group_uuid' => $groupUuid,
                'order_uuids' => $orderUuids,
                'currency' => $command->currency,
                'status' => CheckoutGroup::STATUS_PENDING,
                'totals' => $plan->totals,
                'orders' => $orderRows,
                'shipping_charge_owner_order_uuid' => $ownerUuid,
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'website_id' => $command->websiteId,
                'store_id' => $command->storeId,
                'snapshots' => [
                    'money' => $groupMoney->toArray(),
                    'scope' => (new ScopeSnapshot($command->websiteId, $command->storeId, $command->currency))->toArray(),
                    'tax' => $this->taxSnapshotFromCommand(
                        $command,
                        (int) $plan->totals['tax_amount_minor'],
                    )->toArray(),
                    'shipping' => $groupShipping->toArray(),
                ],
            ];
            $this->memory['groups'][$groupUuid] = $group;
            $this->memory['by_idem'][$key] = [
                'request_hash' => $hash,
                'group_uuid' => $groupUuid,
            ];
            $this->memory['write_count'] = $writesBefore + 1;

            return $this->resultFromGroup($group, replayed: false);
        } catch (\Throwable $e) {
            // TEST-P2D-03：整组回滚（含展示号 registry）
            $this->memory['orders'] = $snapshotOrders;
            $this->memory['groups'] = $snapshotGroups;
            $this->memory['by_idem'] = $snapshotIdem;
            $this->memory['write_count'] = $writesBefore;
            $this->displayNumbers->replaceMemory($snapshotDisplay);
            if ($e instanceof OrderFacadeConflictException) {
                throw $e;
            }
            throw new OrderFacadeConflictException(
                self::ERROR_COMMIT_FAILED,
                \__('CheckoutGroup 提交失败并已回滚：%{1}', [$e->getMessage()]),
                ['cause' => $e->getMessage()],
                $e,
            );
        } finally {
            $this->failAfterOrderIndex = null;
        }
    }

    /**
     * DB writer（TEST-P2E-05 成功 submit 落库）：
     * 与 memory 路径相同的拆单/快照/不变式，事务写 CheckoutGroup + Order + OrderItem；
     * 展示号 registry 由 Allocator 直写（失败按 entity 补偿释放）。
     */
    private function createDb(CreateCheckoutGroupCommand $command): CreateCheckoutGroupResult
    {
        $key = trim($command->idempotencyKey);
        $hash = trim($command->requestHash);
        $store = $this->dbStore();

        $existing = $store->findGroupByIdempotencyKey($key);
        if ($existing !== null) {
            if ((string)$existing['request_hash'] !== $hash) {
                throw new OrderFacadeConflictException(
                    self::ERROR_HASH_CONFLICT,
                    \__('Order Facade idempotency 冲突：key=%{1}', [$key]),
                    ['idempotency_key' => $key],
                );
            }
            return $this->resultFromGroup($existing, replayed: true);
        }

        $plan = $this->plan($command);
        $orderUuids = [];

        try {
            $groupUuid = $this->newUuid();
            $orderRows = [];
            $ownerUuid = null;

            foreach ($plan->orders as $idx => $planned) {
                $orderUuid = $this->newUuid();
                $orderUuids[] = $orderUuid;
                $isOwner = $plan->shippingChargeOwnerIndex === $idx;
                if ($isOwner) {
                    $ownerUuid = $orderUuid;
                }

                $displayRef = $this->displayNumbers->allocate(
                    $command->websiteId,
                    $command->storeId,
                    DisplayNumberRegistry::KIND_ORDER,
                    $orderUuid,
                );

                $money = (new MoneySnapshot(
                    currency: $command->currency,
                    subtotalMinor: (int)$planned['subtotal_minor'],
                    shippingAmountMinor: (int)$planned['shipping_amount_minor'],
                    taxAmountMinor: (int)$planned['tax_amount_minor'],
                ))->withComputedGrandTotal();
                $catalog = new CatalogSnapshot($planned['items']);
                $scope = new ScopeSnapshot($command->websiteId, $command->storeId, $command->currency);
                $tax = $this->taxSnapshotFromCommand(
                    $command,
                    (int) $planned['tax_amount_minor'],
                    $planned['items'],
                );
                $shipping = new ShippingSnapshot(
                    method: $command->shippingMethod,
                    amountMinor: (int)$planned['shipping_amount_minor'],
                    chargeOwnerOrderUuid: $isOwner ? $orderUuid : null,
                    address: $command->shippingAddress,
                );

                $orderRows[] = [
                    'order_uuid' => $orderUuid,
                    'checkout_group_uuid' => $groupUuid,
                    'status' => self::STATUS_PENDING,
                    'group_status' => CheckoutGroup::STATUS_PENDING,
                    'currency' => $command->currency,
                    'website_id' => $command->websiteId,
                    'store_id' => $command->storeId,
                    'customer_id' => $command->customerId,
                    'items' => $planned['items'],
                    'money' => $money->toArray(),
                    'scope' => $scope->toArray(),
                    'snapshots' => [
                        'money' => $money->toArray(),
                        'catalog' => $catalog->toArray(),
                        'scope' => $scope->toArray(),
                        'tax' => $tax->toArray(),
                        'shipping' => $shipping->toArray(),
                    ],
                    'fulfillment_units' => $this->buildFulfillmentUnits(
                        $planned['items'],
                        $groupUuid,
                        $orderUuid,
                        $command->websiteId,
                        $command->storeId,
                    ),
                    'is_shipping_charge_owner' => $isOwner,
                    'split_key' => (string)$planned['split_key'],
                    'number_kind' => $displayRef->numberKind,
                    'display_number' => $displayRef->displayNumber,
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                ];
            }

            $groupMoney = MoneySnapshot::fromArray([
                'currency' => $command->currency,
                'subtotal_minor' => $plan->totals['subtotal_minor'],
                'shipping_amount_minor' => $plan->totals['shipping_amount_minor'],
                'tax_amount_minor' => $plan->totals['tax_amount_minor'],
                'grand_total_minor' => $plan->totals['grand_total_minor'],
            ]);
            $groupShipping = new ShippingSnapshot(
                method: $command->shippingMethod,
                amountMinor: (int)$plan->totals['shipping_amount_minor'],
                chargeOwnerOrderUuid: $ownerUuid,
                address: $command->shippingAddress,
            );

            $this->invariant->assertMoneyConservation($orderRows, $plan->totals);
            $this->invariant->assertSingleShippingOwner(
                $orderRows,
                (int)$plan->totals['shipping_amount_minor'],
                $ownerUuid,
            );

            $group = [
                'checkout_group_uuid' => $groupUuid,
                'order_uuids' => $orderUuids,
                'currency' => $command->currency,
                'status' => CheckoutGroup::STATUS_PENDING,
                'totals' => $plan->totals,
                'orders' => $orderRows,
                'shipping_charge_owner_order_uuid' => $ownerUuid,
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'website_id' => $command->websiteId,
                'store_id' => $command->storeId,
                'snapshots' => [
                    'money' => $groupMoney->toArray(),
                    'scope' => (new ScopeSnapshot($command->websiteId, $command->storeId, $command->currency))->toArray(),
                    'tax' => $this->taxSnapshotFromCommand(
                        $command,
                        (int) $plan->totals['tax_amount_minor'],
                    )->toArray(),
                    'shipping' => $groupShipping->toArray(),
                ],
            ];

            $store->persist($group);

            return $this->resultFromGroup($group, replayed: false);
        } catch (\Throwable $e) {
            // 补偿释放本次已占用展示号；Group/Order/Item 由 store 事务回滚
            foreach ($orderUuids as $uuid) {
                try {
                    $this->displayNumbers->releaseForEntity($uuid);
                } catch (\Throwable) {
                    // 补偿失败不掩盖原始异常
                }
            }
            // A competitor may have committed the same idempotency key after
            // our initial read. Re-read after rollback/compensation so
            // same-hash requests converge to replay and different hashes keep
            // the stable conflict code.
            try {
                $existing = $store->findGroupByIdempotencyKey($key);
            } catch (\Throwable) {
                $existing = null;
            }
            if ($existing !== null) {
                if ((string)$existing['request_hash'] !== $hash) {
                    throw new OrderFacadeConflictException(
                        self::ERROR_HASH_CONFLICT,
                        \__('Order Facade idempotency 冲突：key=%{1}', [$key]),
                        ['idempotency_key' => $key],
                        $e,
                    );
                }

                return $this->resultFromGroup($existing, replayed: true);
            }
            if ($e instanceof OrderFacadeConflictException) {
                throw $e;
            }
            throw new OrderFacadeConflictException(
                self::ERROR_COMMIT_FAILED,
                \__('CheckoutGroup 提交失败并已回滚：%{1}', [$e->getMessage()]),
                ['cause' => $e->getMessage()],
                $e,
            );
        }
    }

    private function dbStore(): OrderFacadeStoreInterface
    {
        return $this->dbStoreInstance ??= new OrmOrderFacadeStore();
    }

    /**
     * Notify post-payment hooks（P2F）；does not mutate refund/invoice money paths.
     *
     * @param array<string, mixed> $context Extension metadata only; frozen
     *        identity/scope/money are always loaded from the Order read model.
     */
    public function notifyOrderPaid(string $orderUuid, array $context = []): void
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            throw new \InvalidArgumentException(\__('order_uuid 不能为空'));
        }
        $paidContext = OrderPaidContext::fromOrderRead(
            $this->get($orderUuid),
            $context,
        );
        $this->postPaymentHook->afterOrderPaid($paidContext);
    }

    public function get(string $orderUuid): OrderReadResult
    {
        $orderUuid = trim($orderUuid);
        $row = $this->memory === null
            ? $this->dbStore()->findOrder($orderUuid)
            : ($this->memory['orders'][$orderUuid] ?? null);
        if ($row === null) {
            throw new OrderFacadeConflictException(
                self::ERROR_NOT_FOUND,
                \__('Order 不存在：%{1}', [$orderUuid]),
                ['order_uuid' => $orderUuid],
            );
        }
        $tax = is_array($row['snapshots']['tax'] ?? null) && $row['snapshots']['tax'] !== []
            ? TaxSnapshot::fromArray($row['snapshots']['tax'])
            : TaxSnapshot::legacyFrozen(
                (int) ($row['money']['tax_amount_minor'] ?? 0),
                (string) $row['currency'],
                (int) $row['website_id'],
                (int) $row['store_id'],
            );

        return new OrderReadResult(
            orderUuid: (string)$row['order_uuid'],
            checkoutGroupUuid: (string)$row['checkout_group_uuid'],
            status: (string)$row['status'],
            currency: (string)$row['currency'],
            websiteId: (int)$row['website_id'],
            storeId: (int)$row['store_id'],
            items: $row['items'],
            money: $row['money'],
            scope: $row['scope'],
            tax: $tax->toArray(),
            shipping: is_array($row['snapshots']['shipping'] ?? null)
                ? $row['snapshots']['shipping']
                : [],
            isShippingChargeOwner: (bool)$row['is_shipping_charge_owner'],
            numberKind: (string)$row['number_kind'],
            displayNumber: $row['display_number'] ?? null,
            customerId: isset($row['customer_id']) ? (int)$row['customer_id'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function getGroup(string $checkoutGroupUuid): array
    {
        $group = $this->memory === null
            ? $this->dbStore()->findGroup(trim($checkoutGroupUuid))
            : ($this->memory['groups'][trim($checkoutGroupUuid)] ?? null);
        if ($group === null) {
            throw new OrderFacadeConflictException(
                self::ERROR_NOT_FOUND,
                \__('CheckoutGroup 不存在：%{1}', [$checkoutGroupUuid]),
            );
        }
        return $group;
    }

    /** @internal tests */
    public function writeCount(): int
    {
        return (int)($this->memory['write_count'] ?? 0);
    }

    /** @internal tests */
    public function groupCount(): int
    {
        return count($this->memory['groups'] ?? []);
    }

    /** @internal tests */
    public function orderCount(): int
    {
        return count($this->memory['orders'] ?? []);
    }

    public function invariant(): CheckoutGroupInvariant
    {
        return $this->invariant;
    }

    private function assertCommand(CreateCheckoutGroupCommand $command): void
    {
        if (
            $command->websiteId < 0
            || $command->websiteId > self::MAX_SCOPE_ID
            || $command->storeId < 0
            || $command->storeId > self::MAX_SCOPE_ID
        ) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_SCOPE,
                \__('website_id/store_id 超出有效范围'),
                ['website_id' => $command->websiteId, 'store_id' => $command->storeId],
            );
        }
        $key = trim($command->idempotencyKey);
        $hash = trim($command->requestHash);
        if ($key === '' || strlen($key) > 128) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('idempotency_key 长度须为 1..128'),
            );
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('request_hash 必须为小写 SHA-256'),
            );
        }
        if (!preg_match('/^[A-Z]{3}$/D', $command->currency)) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('currency 必须为三位大写货币代码'),
            );
        }
        if ($command->customerId !== null && $command->customerId < 0) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('customer_id 须 >=0'),
            );
        }
        if ($command->shippingAmountMinor < 0) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('shipping_amount_minor 须 >=0'),
            );
        }
        if ($command->lines === []) {
            throw new OrderFacadeConflictException(
                self::ERROR_EMPTY_LINES,
                \__('订单行不能为空'),
            );
        }
        if (count($command->lines) > self::MAX_LINES) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('订单行超过上限 %{1}', [self::MAX_LINES]),
            );
        }
        $taxAmountMinor = $command->options['tax_amount_minor'] ?? 0;
        if (!is_int($taxAmountMinor) || $taxAmountMinor < 0) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('tax_amount_minor 须为非负整数'),
            );
        }
        foreach ($command->lines as $i => $line) {
            if (!isset($line['name'], $line['qty_minor'], $line['unit_price_minor'])) {
                throw new OrderFacadeConflictException(
                    self::ERROR_INVALID_COMMAND,
                    \__('行 %{1} 缺少 name/qty_minor/unit_price_minor', [$i]),
                );
            }
            if (
                !is_string($line['name'])
                || trim($line['name']) === ''
                || strlen($line['name']) > 255
                || !is_int($line['qty_minor'])
                || !is_int($line['unit_price_minor'])
                || $line['qty_minor'] <= 0
                || $line['unit_price_minor'] < 0
            ) {
                throw new OrderFacadeConflictException(
                    self::ERROR_INVALID_COMMAND,
                    \__('行 %{1} 名称、数量或单价非法', [$i]),
                );
            }
            if (isset($line['sku']) && (!is_string($line['sku']) || strlen($line['sku']) > 100)) {
                throw new OrderFacadeConflictException(
                    self::ERROR_INVALID_COMMAND,
                    \__('行 %{1} SKU 非法', [$i]),
                );
            }
            if (
                isset($line['split_key'])
                && (!is_string($line['split_key']) || strlen(trim($line['split_key'])) > 64)
            ) {
                throw new OrderFacadeConflictException(
                    self::ERROR_INVALID_COMMAND,
                    \__('行 %{1} split_key 非法', [$i]),
                );
            }
            $this->checkedMultiply($line['qty_minor'], $line['unit_price_minor']);
        }
    }

    /**
     * @return array{orders:list<array<string,mixed>>,owner_index:int|null}
     */
    private function buildPlannedOrders(CreateCheckoutGroupCommand $command): array
    {
        $groupTax = (int) ($command->options['tax_amount_minor'] ?? 0);
        if ($groupTax < 0) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('税额不能为负数'),
            );
        }
        $rawTax = $command->options['tax_snapshot'] ?? null;
        $groupTaxSnapshot = is_array($rawTax)
            ? TaxSnapshot::fromArray($rawTax)
            : new TaxSnapshot();
        if ($groupTaxSnapshot->taxAmountMinor !== $groupTax) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('Tax 快照总额与订单命令不一致'),
                [
                    'snapshot_tax_amount_minor' => $groupTaxSnapshot->taxAmountMinor,
                    'command_tax_amount_minor' => $groupTax,
                ],
            );
        }

        $taxByLine = [];
        if ($groupTaxSnapshot->mode === 'engine') {
            if ($groupTaxSnapshot->engine === 'none'
                || preg_match('/^[a-f0-9]{64}$/D', $groupTaxSnapshot->ruleSetHash) !== 1
                || $groupTaxSnapshot->ruleSchemaVersion === ''
                || $groupTaxSnapshot->scopeKey === ''
                || $groupTaxSnapshot->jurisdictionKey === ''
                || $groupTaxSnapshot->currency !== $command->currency
                || $groupTaxSnapshot->websiteId !== $command->websiteId
                || $groupTaxSnapshot->storeId !== $command->storeId
            ) {
                throw new OrderFacadeConflictException(
                    self::ERROR_INVALID_COMMAND,
                    \__('Tax 引擎快照的规则或 Scope 元数据无效'),
                );
            }
            $lineTaxTotal = 0;
            foreach ($groupTaxSnapshot->lines as $line) {
                $lineId = trim((string) ($line['line_id'] ?? ''));
                $lineTax = (int) ($line['tax_amount_minor'] ?? -1);
                if ($lineId === '' || $lineTax < 0 || isset($taxByLine[$lineId])) {
                    throw new OrderFacadeConflictException(
                        self::ERROR_INVALID_COMMAND,
                        \__('Tax 快照行标识或税额无效'),
                        ['line_id' => $lineId],
                    );
                }
                $lineTaxTotal = $this->checkedAdd($lineTaxTotal, $lineTax);
                $taxByLine[$lineId] = $line;
            }
            if ($lineTaxTotal !== $groupTax) {
                throw new OrderFacadeConflictException(
                    self::ERROR_INVALID_COMMAND,
                    \__('Tax 逐行税额与 Group 税额不守恒'),
                    ['line_tax_total' => $lineTaxTotal, 'group_tax_total' => $groupTax],
                );
            }
        } elseif ($groupTax !== 0
            || $groupTaxSnapshot->engine !== 'none'
            || $groupTaxSnapshot->lines !== []
        ) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('none Tax 快照必须为零税且不得包含引擎行'),
            );
        }

        $buckets = [];
        $seenCommandLines = [];
        foreach ($command->lines as $line) {
            $split = trim((string)($line['split_key'] ?? 'default'));
            if ($split === '') {
                $split = 'default';
            }
            $buckets[$split] ??= [
                'split_key' => $split,
                'items' => [],
                'subtotal_minor' => 0,
                'requires_shipping' => false,
            ];
            $lineTotal = $this->checkedMultiply(
                (int)$line['qty_minor'],
                (int)$line['unit_price_minor'],
            );
            $lineUuid = trim((string) ($line['line_uuid'] ?? ''));
            $lineTax = 0;
            $lineTaxSnapshot = new TaxSnapshot(
                currency: $command->currency,
                websiteId: $command->websiteId,
                storeId: $command->storeId,
            );
            if ($groupTaxSnapshot->mode === 'engine') {
                if ($lineUuid === ''
                    || isset($seenCommandLines[$lineUuid])
                    || !isset($taxByLine[$lineUuid])
                ) {
                    throw new OrderFacadeConflictException(
                        self::ERROR_INVALID_COMMAND,
                        \__('订单行与 Tax 快照行无法一一对应'),
                        ['line_uuid' => $lineUuid],
                    );
                }
                $seenCommandLines[$lineUuid] = true;
                $taxLine = $taxByLine[$lineUuid];
                unset($taxByLine[$lineUuid]);
                $lineTax = (int) $taxLine['tax_amount_minor'];
                $lineTaxSnapshot = new TaxSnapshot(
                    taxAmountMinor: $lineTax,
                    mode: $groupTaxSnapshot->mode,
                    note: $groupTaxSnapshot->note,
                    ruleSchemaVersion: $groupTaxSnapshot->ruleSchemaVersion,
                    ruleSetHash: $groupTaxSnapshot->ruleSetHash,
                    engine: $groupTaxSnapshot->engine,
                    lines: [$taxLine],
                    jurisdictionKey: $groupTaxSnapshot->jurisdictionKey,
                    currency: $groupTaxSnapshot->currency,
                    scopeKey: $groupTaxSnapshot->scopeKey,
                    websiteId: $groupTaxSnapshot->websiteId,
                    storeId: $groupTaxSnapshot->storeId,
                );
            }
            $buckets[$split]['items'][] = [
                'line_uuid' => $lineUuid !== '' ? $lineUuid : null,
                'offer_id' => $line['offer_id'] ?? null,
                'product_id' => $line['product_id'] ?? null,
                'sku' => $line['sku'] ?? null,
                'name' => (string)$line['name'],
                'qty_minor' => (int)$line['qty_minor'],
                'unit_price_minor' => (int)$line['unit_price_minor'],
                'row_total_minor' => $lineTotal,
                'requires_shipping' => (bool)($line['requires_shipping'] ?? true),
                'reservation_uuid' => $line['reservation_uuid'] ?? null,
                'tax_class_code' => $line['tax_class_code'] ?? 'standard',
                'tax_amount_minor' => $lineTax,
                'tax_snapshot' => $lineTaxSnapshot->toArray(),
            ];
            $buckets[$split]['subtotal_minor'] = $this->checkedAdd(
                (int)$buckets[$split]['subtotal_minor'],
                $lineTotal,
            );
            $buckets[$split]['tax_amount_minor'] = $this->checkedAdd(
                (int)($buckets[$split]['tax_amount_minor'] ?? 0),
                $lineTax,
            );
            if ((bool)($line['requires_shipping'] ?? true)) {
                $buckets[$split]['requires_shipping'] = true;
            }
        }
        if ($taxByLine !== []) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('Tax 快照包含订单中不存在的行'),
                ['line_ids' => array_keys($taxByLine)],
            );
        }

        // Stable order of split keys
        ksort($buckets);
        $orders = [];
        $ownerIndex = null;
        foreach (array_values($buckets) as $idx => $bucket) {
            $ship = 0;
            $isOwner = false;
            if ($bucket['requires_shipping'] && $ownerIndex === null) {
                $ownerIndex = $idx;
                $isOwner = true;
                $ship = max(0, $command->shippingAmountMinor);
            }
            $orderTax = (int) ($bucket['tax_amount_minor'] ?? 0);
            $orders[] = [
                'split_key' => $bucket['split_key'],
                'items' => $bucket['items'],
                'subtotal_minor' => $bucket['subtotal_minor'],
                'shipping_amount_minor' => $ship,
                'tax_amount_minor' => $orderTax,
                'grand_total_minor' => $this->checkedAdd(
                    $this->checkedAdd((int)$bucket['subtotal_minor'], $ship),
                    $orderTax,
                ),
                'requires_shipping' => $bucket['requires_shipping'],
                'is_shipping_charge_owner' => $isOwner,
            ];
        }

        $allocatedTax = 0;
        foreach ($orders as $order) {
            $allocatedTax = $this->checkedAdd($allocatedTax, (int) $order['tax_amount_minor']);
        }
        if ($allocatedTax !== $groupTax) {
            throw new OrderFacadeConflictException(
                self::ERROR_INVALID_COMMAND,
                \__('Order Tax 分配与 Group 税额不守恒'),
                ['allocated_tax' => $allocatedTax, 'group_tax' => $groupTax],
            );
        }

        return ['orders' => $orders, 'owner_index' => $ownerIndex];
    }

    private function checkedMultiply(int $left, int $right): int
    {
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new OrderFacadeConflictException(
                self::ERROR_AMOUNT_OVERFLOW,
                \__('订单金额乘法溢出'),
            );
        }

        return $left * $right;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new OrderFacadeConflictException(
                self::ERROR_AMOUNT_OVERFLOW,
                \__('订单金额加法溢出'),
            );
        }

        return $left + $right;
    }

    /** @param array<string, mixed> $group */
    private function resultFromGroup(array $group, bool $replayed): CreateCheckoutGroupResult
    {
        return new CreateCheckoutGroupResult(
            checkoutGroupUuid: (string)$group['checkout_group_uuid'],
            orderUuids: $group['order_uuids'],
            currency: (string)$group['currency'],
            totals: $group['totals'],
            orders: array_map(static function (array $o): array {
                return [
                    'order_uuid' => $o['order_uuid'],
                    'split_key' => $o['split_key'],
                    'money' => $o['money'],
                    'is_shipping_charge_owner' => $o['is_shipping_charge_owner'],
                    'status' => $o['status'],
                    'number_kind' => $o['number_kind'] ?? DisplayNumberRegistry::KIND_ORDER,
                    'display_number' => $o['display_number'] ?? null,
                ];
            }, $group['orders']),
            replayed: $replayed,
            shippingChargeOwnerOrderUuid: $group['shipping_charge_owner_order_uuid'] ?? null,
        );
    }

    /**
     * @param list<array<string,mixed>>|null $items
     */
    private function taxSnapshotFromCommand(
        CreateCheckoutGroupCommand $command,
        int $amountMinor,
        ?array $items = null,
    ): TaxSnapshot
    {
        $raw = $command->options['tax_snapshot'] ?? null;
        if (is_array($raw)) {
            $snap = TaxSnapshot::fromArray($raw);
            if ($items === null && $snap->taxAmountMinor === $amountMinor) {
                return $snap;
            }
            $lineIds = [];
            foreach ($items ?? [] as $item) {
                $lineId = trim((string) ($item['line_uuid'] ?? ''));
                if ($lineId !== '') {
                    $lineIds[$lineId] = true;
                }
            }
            $lines = [];
            $lineTaxTotal = 0;
            foreach ($snap->lines as $line) {
                $lineId = (string) ($line['line_id'] ?? '');
                if (!isset($lineIds[$lineId])) {
                    continue;
                }
                $lines[] = $line;
                $lineTaxTotal = $this->checkedAdd(
                    $lineTaxTotal,
                    (int) ($line['tax_amount_minor'] ?? 0),
                );
            }
            if ($lineTaxTotal !== $amountMinor) {
                throw new OrderFacadeConflictException(
                    self::ERROR_INVALID_COMMAND,
                    \__('Order Tax 快照与已分配税额不一致'),
                    ['snapshot_tax' => $lineTaxTotal, 'order_tax' => $amountMinor],
                );
            }

            return new TaxSnapshot(
                taxAmountMinor: $amountMinor,
                mode: $snap->mode,
                note: $snap->note,
                ruleSchemaVersion: $snap->ruleSchemaVersion,
                ruleSetHash: $snap->ruleSetHash,
                engine: $snap->engine,
                lines: $lines,
                jurisdictionKey: $snap->jurisdictionKey,
                currency: $snap->currency,
                scopeKey: $snap->scopeKey,
                websiteId: $snap->websiteId,
                storeId: $snap->storeId,
            );
        }
        $mode = (string) ($command->options['tax_mode'] ?? 'stub_zero');
        if ($amountMinor === 0) {
            return new TaxSnapshot(
                mode: $mode === 'none' ? 'stub_zero' : $mode,
                currency: $command->currency,
                websiteId: $command->websiteId,
                storeId: $command->storeId,
            );
        }

        throw new OrderFacadeConflictException(
            self::ERROR_INVALID_COMMAND,
            \__('非零税额必须提供完整 Tax 快照'),
        );
    }

    /**
     * P2D-002 creates one pending fulfillment stub per physical split Order.
     * Warehouse assignment and partial-fulfillment behavior remain owned by P3.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function buildFulfillmentUnits(
        array $items,
        string $groupUuid,
        string $orderUuid,
        int $websiteId,
        int $storeId,
    ): array
    {
        $qtyMinor = 0;
        $allocations = [];
        foreach ($items as $item) {
            if (empty($item['requires_shipping'])) {
                continue;
            }
            $itemQty = (int)($item['qty_minor'] ?? 0);
            $qtyMinor = $this->checkedAdd($qtyMinor, $itemQty);
            $allocations[] = [
                'offer_id' => (int)($item['offer_id'] ?? 0),
                'qty_minor' => $itemQty,
            ];
        }
        if ($qtyMinor === 0) {
            return [];
        }
        $warehouseId = null;
        $warehouseSource = null;
        if ($this->defaultWarehouseResolver instanceof DefaultWarehouseResolverInterface) {
            try {
                $assignment = $this->defaultWarehouseResolver->resolveDefault(
                    $websiteId,
                    $storeId,
                );
                $warehouseId = $assignment->warehouseId;
                $warehouseSource = $assignment->writerEnabled
                    ? WarehouseFulfillmentService::SOURCE_WAREHOUSE
                    : WarehouseFulfillmentService::SOURCE_LEGACY_DEFAULT;
            } catch (InventoryConflictException $exception) {
                if ($exception->errorCode() !== DefaultWarehouseResolverInterface::ERROR_MISSING) {
                    throw $exception;
                }
            }
        }

        return [[
            'fulfillment_unit_uuid' => $this->newUuid(),
            'order_uuid' => $orderUuid,
            'checkout_group_uuid' => $groupUuid,
            'status' => \Weline\Order\Model\FulfillmentUnit::STATUS_PENDING,
            'warehouse_id' => $warehouseId,
            'warehouse_source' => $warehouseSource,
            'allocations' => $allocations,
            'qty_minor' => $qtyMinor,
            'fulfilled_qty_minor' => 0,
            'fulfillment_version' => 0,
        ]];
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
