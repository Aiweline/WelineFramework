<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Inventory\Api\Data\WarehouseAssignment;
use Weline\Inventory\Api\DefaultWarehouseResolverInterface;
use Weline\Inventory\Api\InventoryCapabilityInterface;
use Weline\Inventory\Api\InventoryConflictException;
use Weline\Inventory\Api\WarehouseInventoryCapabilityInterface;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Api\Data\CreateCheckoutGroupResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Shipping\Api\Quote\ShippingQuoteRequest;
use Weline\Shipping\Api\Quote\ShippingQuoteServiceInterface;
use Weline\Shipping\Service\ShippingQuoteConflictException;
use Weline\Tax\Api\CheckoutTaxAdvisorInterface;
use Weline\Tax\Api\TaxConflictException;

/**
 * Checkout V2：freeze → Shipping Quote → allocate → Tax（stub / engine）→ OrderFacade.
 * Client shipping/tax amounts are ignored（TEST-P2E-07 / P3B-002）.
 * 生产注入 CheckoutSessionStoreInterface 实现跨 HTTP/Worker 会话；单测 forTesting 用进程内数组。
 */
final class CheckoutGroupSubmitService
{
    public const ERROR_CURRENCY = 'checkout_currency_missing';
    public const ERROR_QUOTE_TOKEN = 'checkout_quote_token_conflict';
    public const ERROR_CLIENT_MONEY = 'checkout_client_money_rejected';
    public const ERROR_EMPTY = 'checkout_lines_empty';
    public const ERROR_TAX = 'checkout_tax_blocked';
    public const ERROR_CLIENT_FACT = 'checkout_client_fact_rejected';
    public const ERROR_IDENTITY = 'checkout_identity_conflict';
    public const ERROR_INVENTORY = 'checkout_inventory_unavailable';
    public const TAX_STUB_MODE = 'none';

    private int $quoteCalls = 0;

    private ?InventoryCapabilityInterface $inventory;
    private ?DefaultWarehouseResolverInterface $defaultWarehouseResolver;
    private ?WarehouseInventoryCapabilityInterface $warehouseInventory;

    public function __construct(
        private readonly ShippingQuoteServiceInterface $shippingQuotes,
        private readonly ShippingAllocationService $allocation,
        private readonly OrderFacadeInterface $orderFacade,
        private readonly CheckoutSessionStoreInterface $sessionStore,
        private readonly ?CheckoutTaxAdvisorInterface $taxAdvisor = null,
        ?InventoryCapabilityInterface $inventory = null,
        ?DefaultWarehouseResolverInterface $defaultWarehouseResolver = null,
        ?WarehouseInventoryCapabilityInterface $warehouseInventory = null,
        private readonly ?DatabaseTransactionRunnerInterface $transactions = null,
        private readonly ?ConnectionFactory $connectionFactory = null,
        private readonly bool $resolveRuntimeInventory = true,
    ) {
        $this->inventory = $inventory;
        $this->defaultWarehouseResolver = $defaultWarehouseResolver;
        $this->warehouseInventory = $warehouseInventory;
    }

    public static function forTesting(
        ShippingQuoteServiceInterface $shippingQuotes,
        ?OrderFacadeInterface $orderFacade = null,
        ?ShippingAllocationService $allocation = null,
        ?CheckoutTaxAdvisorInterface $taxAdvisor = null,
        ?InventoryCapabilityInterface $inventory = null,
        ?DefaultWarehouseResolverInterface $defaultWarehouseResolver = null,
        ?WarehouseInventoryCapabilityInterface $warehouseInventory = null,
    ): self {
        return new self(
            $shippingQuotes,
            $allocation ?? new ShippingAllocationService(),
            $orderFacade ?? \Weline\Order\Service\OrderFacade::forTesting(),
            new InMemoryCheckoutSessionStore(),
            taxAdvisor: $taxAdvisor,
            inventory: $inventory,
            defaultWarehouseResolver: $defaultWarehouseResolver,
            warehouseInventory: $warehouseInventory,
            resolveRuntimeInventory: false,
        );
    }

    public function taxAdvisor(): ?CheckoutTaxAdvisorInterface
    {
        return $this->taxAdvisor;
    }

    public function quoteCallCount(): int
    {
        return $this->quoteCalls;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $address
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $clientHints
     * @return array<string, mixed>
     */
    public function freezeAndQuote(
        array $lines,
        array $address,
        array $scope,
        string $serviceCode,
        string $currency,
        string $configVersion = '1',
        array $clientHints = [],
        ?int $customerId = null,
        string $cartHash = '',
    ): array {
        $this->rejectClientAuthority($clientHints);
        if ($lines === []) {
            throw new CheckoutV2ConflictException(self::ERROR_EMPTY, __('结账行不能为空'));
        }
        if (trim($currency) === '') {
            throw new CheckoutV2ConflictException(self::ERROR_CURRENCY, __('结账币种缺失'));
        }
        $currency = strtoupper(trim($currency));
        $activeConfigVersion = $this->shippingQuotes->activeConfigVersion();
        if ($configVersion !== '' && $configVersion !== $activeConfigVersion) {
            throw new CheckoutV2ConflictException(
                self::ERROR_QUOTE_TOKEN,
                __('运费配置版本已变更，请重新报价确认'),
                [
                    'request_config_version' => $configVersion,
                    'active_config_version' => $activeConfigVersion,
                ],
            );
        }
        $configVersion = $activeConfigVersion;

        $orders = $this->bucketBySplitKey($lines);
        $this->allocation->assertCompatible($orders);

        $shippableLines = [];
        foreach ($lines as $line) {
            if ((bool) ($line['requires_shipping'] ?? true)) {
                $shippableLines[] = $line;
            }
        }

        $req = new ShippingQuoteRequest(
            scope: $scope,
            address: $address,
            lines: $shippableLines,
            currency: $currency,
            configVersion: $configVersion,
            serviceCode: $serviceCode,
        );

        try {
            $this->quoteCalls++;
            $quote = $this->shippingQuotes->quote($req, $serviceCode);
        } catch (ShippingQuoteConflictException $e) {
            throw new CheckoutV2ConflictException($e->errorCode(), $e->getMessage(), $e->context(), $e);
        }

        $alloc = $this->allocation->allocate($orders, $quote->amountMinor);
        try {
            $tax = $this->taxAdvisor !== null
                ? $this->taxAdvisor->quoteTax($orders, $scope, $address, $currency)
                : [
                    'mode' => self::TAX_STUB_MODE,
                    'engine' => 'none',
                    'tax_amount_minor' => 0,
                    'note' => 'server_written_zero_tax',
                    'rule_schema_version' => '',
                    'rule_set_hash' => '',
                    'lines' => [],
                ];
        } catch (TaxConflictException $e) {
            throw new CheckoutV2ConflictException(
                $e->errorCode() === self::ERROR_TAX ? self::ERROR_TAX : $e->errorCode(),
                $e->getMessage(),
                $e->context(),
                $e,
            );
        }

        $token = 'qt_' . bin2hex(random_bytes(12));
        $payload = [
            'quote_token' => $token,
            'state' => \Weline\Checkout\Model\CheckoutSession::STATE_QUOTED,
            'currency' => $currency,
            'config_version' => $configVersion,
            'cart_hash' => $cartHash,
            'customer_id' => $customerId,
            'scope' => $scope,
            'address' => $address,
            'service_code' => $serviceCode,
            'orders' => $orders,
            'allocation' => $alloc,
            'quote' => $quote->toArray(),
            'tax' => $tax,
        ];
        $payload['request_hash'] = hash(
            'sha256',
            json_encode(
                $payload + ['shipping_request_hash' => $quote->requestHash],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: '',
        );
        $this->putSession($token, $payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $clientHints
     */
    public function submit(
        string $quoteToken,
        string $idempotencyKey,
        array $clientHints = [],
        ?int $customerId = null,
        ?string $expectedConfigVersion = null,
        ?string $expectedTaxRuleSetHash = null,
    ): CreateCheckoutGroupResult {
        $this->rejectClientAuthority($clientHints);
        $token = trim($quoteToken);
        if ($token === '') {
            throw new CheckoutV2ConflictException(
                self::ERROR_QUOTE_TOKEN,
                __('quote_token 不能为空'),
            );
        }
        $operation = fn (): CreateCheckoutGroupResult => $this->submitLocked(
            $token,
            trim($idempotencyKey),
            $customerId,
            $expectedConfigVersion,
            $expectedTaxRuleSetHash,
        );
        if (!$this->resolveRuntimeInventory && $this->transactions === null) {
            return $operation();
        }
        $transactions = $this->transactions
            ?? ObjectManager::getInstance(DatabaseTransactionRunnerInterface::class);
        $connection = $this->connectionFactory ?? ConnectionFactory::getInstance();

        return $transactions->run($connection, $operation);
    }

    private function submitLocked(
        string $token,
        string $idempotencyKey,
        ?int $customerId,
        ?string $expectedConfigVersion,
        ?string $expectedTaxRuleSetHash,
    ): CreateCheckoutGroupResult {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new CheckoutV2ConflictException(
                self::ERROR_QUOTE_TOKEN,
                __('idempotency_key 长度须为 1..128'),
            );
        }
        $session = $this->sessionStore->getForUpdate($token);
        if ($session === null) {
            throw new CheckoutV2ConflictException(
                self::ERROR_QUOTE_TOKEN,
                __('报价会话不存在或已失效'),
                ['quote_token' => $token],
            );
        }
        $state = (string)($session['state'] ?? \Weline\Checkout\Model\CheckoutSession::STATE_QUOTED);
        if ($state === \Weline\Checkout\Model\CheckoutSession::STATE_SUBMITTED) {
            if (!hash_equals((string)($session['idempotency_key'] ?? ''), $idempotencyKey)) {
                throw new CheckoutV2ConflictException(
                    self::ERROR_QUOTE_TOKEN,
                    __('报价会话已由其它幂等键提交'),
                    ['quote_token' => $token],
                );
            }

            return $this->submittedResult($session);
        }
        if ($state !== \Weline\Checkout\Model\CheckoutSession::STATE_QUOTED) {
            throw new CheckoutV2ConflictException(
                self::ERROR_QUOTE_TOKEN,
                __('报价会话正在提交，请使用相同幂等键重试'),
                ['quote_token' => $token, 'state' => $state],
            );
        }
        $frozenCustomerId = isset($session['customer_id']) && (int)$session['customer_id'] > 0
            ? (int)$session['customer_id']
            : null;
        $currentCustomerId = $customerId !== null && $customerId > 0 ? $customerId : null;
        if ($frozenCustomerId !== $currentCustomerId) {
            throw new CheckoutV2ConflictException(
                self::ERROR_IDENTITY,
                __('当前登录身份与报价会话不一致'),
            );
        }
        if ($expectedConfigVersion !== null && $expectedConfigVersion !== (string) $session['config_version']) {
            throw new CheckoutV2ConflictException(
                self::ERROR_QUOTE_TOKEN,
                __('配置版本已变更，请重新报价确认'),
                [
                    'session_config_version' => $session['config_version'],
                    'expected_config_version' => $expectedConfigVersion,
                ],
            );
        }
        $activeConfigVersion = $this->shippingQuotes->activeConfigVersion();
        if (!hash_equals((string)$session['config_version'], $activeConfigVersion)) {
            throw new CheckoutV2ConflictException(
                self::ERROR_QUOTE_TOKEN,
                __('运费配置版本已变更，请重新报价确认'),
                [
                    'session_config_version' => $session['config_version'],
                    'active_config_version' => $activeConfigVersion,
                ],
            );
        }

        $tax = is_array($session['tax'] ?? null) ? $session['tax'] : ['mode' => self::TAX_STUB_MODE, 'tax_amount_minor' => 0];
        if ($this->taxAdvisor !== null) {
            try {
                $this->taxAdvisor->assertRuleVersion(
                    $tax,
                    $session['orders'],
                    $session['scope'],
                    $session['address'],
                    (string) $session['currency'],
                    $expectedTaxRuleSetHash,
                );
            } catch (TaxConflictException $e) {
                throw new CheckoutV2ConflictException($e->errorCode(), $e->getMessage(), $e->context(), $e);
            }
        }

        $session['state'] = \Weline\Checkout\Model\CheckoutSession::STATE_SUBMITTING;
        $session['idempotency_key'] = $idempotencyKey;
        $this->putSession($token, $session);

        $websiteId = (int) ($session['scope']['website_id'] ?? 0);
        $storeId = (int) ($session['scope']['store_id'] ?? 0);
        $commandLines = [];
        $reservations = [];
        $inventory = $this->inventory();
        foreach ($session['orders'] as $order) {
            $split = (string) $order['split_key'];
            foreach ($order['items'] as $item) {
                $line = [
                    'line_uuid' => (string)($item['line_uuid'] ?? ''),
                    'name' => (string) $item['name'],
                    'sku' => (string) ($item['sku'] ?? ''),
                    'qty_minor' => (int) $item['qty_minor'],
                    'unit_price_minor' => (int) $item['unit_price_minor'],
                    'split_key' => $split,
                    'requires_shipping' => (bool) ($item['requires_shipping'] ?? true),
                    'offer_id' => $item['offer_id'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'tax_class_code' => $item['tax_class_code'] ?? 'standard',
                ];
                if ((bool)($item['requires_shipping'] ?? true)) {
                    $offerId = (int)($item['offer_id'] ?? 0);
                    if ($offerId <= 0 && $inventory !== null) {
                        throw new CheckoutV2ConflictException(
                            self::ERROR_INVENTORY,
                            __('需配送商品缺少可预占的 Offer ID'),
                            ['line_uuid' => $line['line_uuid']],
                        );
                    }
                    if ($inventory !== null) {
                        $reservationKey = 'checkout:' . hash(
                            'sha256',
                            $idempotencyKey . '|' . (string)$line['line_uuid'],
                        );
                        $reservationHash = hash(
                            'sha256',
                            json_encode([
                                'checkout_request_hash' => (string)$session['request_hash'],
                                'website_id' => $websiteId,
                                'store_id' => $storeId,
                                'offer_id' => $offerId,
                                'quantity_minor' => (int)$line['qty_minor'],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                        );
                        $reserved = $inventory->reserve(
                            $websiteId,
                            $storeId,
                            $offerId,
                            (int)$line['qty_minor'],
                            $reservationKey,
                            $reservationHash,
                        );
                        $line['reservation_uuid'] = $reserved->reservationUuid;
                        $reservationSnapshot = $reserved->toArray() + [
                            'line_uuid' => (string)$line['line_uuid'],
                            'offer_id' => $offerId,
                        ];
                        $assignment = $this->warehouseAssignment($websiteId, $storeId);
                        if ($assignment?->writerEnabled) {
                            $warehouseInventory = $this->warehouseInventory();
                            if ($warehouseInventory === null) {
                                throw new CheckoutV2ConflictException(
                                    self::ERROR_INVENTORY,
                                    __('Warehouse writer 已启用但仓维库存能力不可用'),
                                );
                            }
                            $assignmentKey = 'checkout:warehouse:' . hash(
                                'sha256',
                                $idempotencyKey . '|' . (string) $line['line_uuid'],
                            );
                            $assignmentHash = hash(
                                'sha256',
                                json_encode([
                                    'reservation_uuid' => $reserved->reservationUuid,
                                    'website_id' => $websiteId,
                                    'store_id' => $storeId,
                                    'warehouse_id' => $assignment->warehouseId,
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                            );
                            $warehouseResult = $warehouseInventory->assignReservationWarehouse(
                                $reserved->reservationUuid,
                                $websiteId,
                                $storeId,
                                $assignment->warehouseId,
                                $assignmentKey,
                                $assignmentHash,
                            );
                            $line['warehouse_id'] = $assignment->warehouseId;
                            $line['warehouse_source'] = 'warehouse';
                            $reservationSnapshot += [
                                'warehouse_id' => $assignment->warehouseId,
                                'warehouse_source' => 'warehouse',
                                'warehouse_replayed' => $warehouseResult->replayed,
                            ];
                        }
                        $reservations[] = $reservationSnapshot;
                    }
                }
                $commandLines[] = $line;
            }
        }

        $ownerShip = (int) ($session['allocation']['group_shipping_minor'] ?? 0);
        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: $idempotencyKey,
            requestHash: (string) $session['request_hash'],
            websiteId: $websiteId,
            storeId: $storeId,
            currency: (string) $session['currency'],
            customerId: $customerId,
            lines: $commandLines,
            shippingMethod: (string) $session['service_code'],
            shippingAmountMinor: $ownerShip,
            shippingAddress: $session['address'],
            options: [
                'tax_mode' => (string) ($tax['mode'] ?? self::TAX_STUB_MODE),
                'tax_amount_minor' => (int) ($tax['tax_amount_minor'] ?? 0),
                'tax_snapshot' => $tax,
                'quote_token' => $token,
                'shipping_quote' => $session['quote'],
                'owner_item_shipping_minor' => $session['allocation']['owner_item_shipping_minor'],
                'inventory_reservations' => $reservations,
            ],
        );

        $result = $this->orderFacade->create($cmd);
        $session['state'] = \Weline\Checkout\Model\CheckoutSession::STATE_SUBMITTED;
        $session['submitted_result'] = $result->toArray();
        $session['reservations'] = $reservations;
        $this->putSession($token, $session);

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function getSession(string $quoteToken): ?array
    {
        $token = trim($quoteToken);
        if ($token === '') {
            return null;
        }

        return $this->sessionStore->get($token);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function putSession(string $token, array $payload): void
    {
        $this->sessionStore->put($token, $payload);
    }

    private function forgetSession(string $token): void
    {
        $this->sessionStore->delete($token);
    }

    /** @param array<string, mixed> $clientHints */
    private function rejectClientAuthority(array $clientHints): void
    {
        foreach (['shipping_amount', 'shipping_amount_minor', 'tax_amount', 'tax_amount_minor', 'grand_total', 'grand_total_minor'] as $key) {
            if (array_key_exists($key, $clientHints)) {
                throw new CheckoutV2ConflictException(
                    self::ERROR_CLIENT_MONEY,
                    __('客户端金额字段被拒绝：%{1}', [$key]),
                    ['field' => $key],
                );
            }
        }
        foreach (['lines', 'scope', 'website_id', 'store_id', 'currency', 'config_version', 'customer_id'] as $key) {
            if (array_key_exists($key, $clientHints)) {
                throw new CheckoutV2ConflictException(
                    self::ERROR_CLIENT_FACT,
                    __('客户端交易事实字段被拒绝：%{1}', [$key]),
                    ['field' => $key],
                );
            }
        }
    }

    private function inventory(): ?InventoryCapabilityInterface
    {
        if ($this->inventory instanceof InventoryCapabilityInterface) {
            return $this->inventory;
        }
        if (!$this->resolveRuntimeInventory) {
            return null;
        }
        $resolved = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(InventoryCapabilityInterface::class);
        if (!$resolved instanceof InventoryCapabilityInterface) {
            throw new CheckoutV2ConflictException(
                self::ERROR_INVENTORY,
                __('库存能力不可用，结账已阻断'),
            );
        }

        return $this->inventory = $resolved;
    }

    private function warehouseAssignment(int $websiteId, int $storeId): ?WarehouseAssignment
    {
        if ($this->defaultWarehouseResolver instanceof DefaultWarehouseResolverInterface) {
            return $this->resolveWarehouseAssignment(
                $this->defaultWarehouseResolver,
                $websiteId,
                $storeId,
            );
        }
        if (!$this->resolveRuntimeInventory) {
            return null;
        }
        $resolved = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(DefaultWarehouseResolverInterface::class);
        if (!$resolved instanceof DefaultWarehouseResolverInterface) {
            return null;
        }
        $this->defaultWarehouseResolver = $resolved;

        return $this->resolveWarehouseAssignment($resolved, $websiteId, $storeId);
    }

    private function resolveWarehouseAssignment(
        DefaultWarehouseResolverInterface $resolver,
        int $websiteId,
        int $storeId,
    ): ?WarehouseAssignment
    {
        try {
            return $resolver->resolveDefault($websiteId, $storeId);
        } catch (InventoryConflictException $exception) {
            if ($exception->errorCode() === DefaultWarehouseResolverInterface::ERROR_MISSING) {
                return null;
            }

            throw $exception;
        }
    }

    private function warehouseInventory(): ?WarehouseInventoryCapabilityInterface
    {
        if ($this->warehouseInventory instanceof WarehouseInventoryCapabilityInterface) {
            return $this->warehouseInventory;
        }
        if (!$this->resolveRuntimeInventory) {
            return null;
        }
        $resolved = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(WarehouseInventoryCapabilityInterface::class);
        if (!$resolved instanceof WarehouseInventoryCapabilityInterface) {
            return null;
        }

        return $this->warehouseInventory = $resolved;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function submittedResult(array $session): CreateCheckoutGroupResult
    {
        $data = is_array($session['submitted_result'] ?? null)
            ? $session['submitted_result']
            : [];
        if (($data['checkout_group_uuid'] ?? '') === '') {
            throw new CheckoutV2ConflictException(
                self::ERROR_QUOTE_TOKEN,
                __('已提交报价会话缺少冻结结果'),
            );
        }

        return new CreateCheckoutGroupResult(
            checkoutGroupUuid: (string)$data['checkout_group_uuid'],
            orderUuids: array_values(array_map('strval', (array)($data['order_uuids'] ?? []))),
            currency: (string)($data['currency'] ?? $session['currency'] ?? ''),
            totals: is_array($data['totals'] ?? null) ? $data['totals'] : [],
            orders: is_array($data['orders'] ?? null) ? $data['orders'] : [],
            replayed: true,
            shippingChargeOwnerOrderUuid: isset($data['shipping_charge_owner_order_uuid'])
                ? (string)$data['shipping_charge_owner_order_uuid']
                : null,
        );
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function bucketBySplitKey(array $lines): array
    {
        $buckets = [];
        foreach ($lines as $i => $line) {
            $split = trim((string) ($line['split_key'] ?? 'default')) ?: 'default';
            $buckets[$split] ??= [
                'split_key' => $split,
                'requires_shipping' => false,
                'legal_entity' => (string) ($line['legal_entity'] ?? 'default'),
                'items' => [],
                'subtotal_minor' => 0,
            ];
            if (isset($line['legal_entity'])) {
                $buckets[$split]['legal_entity'] = (string) $line['legal_entity'];
            }
            $qty = (int) ($line['qty_minor'] ?? 1);
            $unit = (int) ($line['unit_price_minor'] ?? 0);
            $row = $qty * $unit;
            $requires = (bool) ($line['requires_shipping'] ?? true);
            $uuid = (string) ($line['line_uuid'] ?? ('line-' . $split . '-' . $i));
            $buckets[$split]['items'][] = [
                'line_uuid' => $uuid,
                'name' => (string) ($line['name'] ?? $uuid),
                'sku' => (string) ($line['sku'] ?? ''),
                'qty_minor' => $qty,
                'unit_price_minor' => $unit,
                'row_total_minor' => $row,
                'requires_shipping' => $requires,
                'offer_id' => $line['offer_id'] ?? null,
                'product_id' => $line['product_id'] ?? null,
                'tax_class_code' => (string) ($line['tax_class_code'] ?? 'standard'),
            ];
            $buckets[$split]['subtotal_minor'] += $row;
            if ($requires) {
                $buckets[$split]['requires_shipping'] = true;
            }
        }
        ksort($buckets);

        return array_values($buckets);
    }
}
