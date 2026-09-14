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
use Weline\Marketing\Api\Quote\DiscountQuote;
use Weline\Marketing\Api\Quote\DiscountQuoteRequest;
use Weline\Marketing\Api\Quote\DiscountQuoteServiceInterface;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Service\DiscountValidationService;
use Weline\Order\Api\Data\CreateCheckoutGroupResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Shipping\Api\Quote\ShippingQuoteRequest;
use Weline\Shipping\Api\Quote\ShippingQuoteServiceInterface;
use Weline\Shipping\Api\Quote\SplitShippingQuoteServiceInterface;
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
    public const ERROR_CLIENT_DISCOUNT = 'checkout_client_discount_rejected';
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
    private ?DiscountQuoteServiceInterface $discountQuotes;
    private ?SplitShippingQuoteServiceInterface $splitShippingQuotes;

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
        ?DiscountQuoteServiceInterface $discountQuotes = null,
        private readonly bool $resolveRuntimeDiscount = true,
        ?SplitShippingQuoteServiceInterface $splitShippingQuotes = null,
        private readonly bool $resolveRuntimeSplitShipping = true,
    ) {
        $this->inventory = $inventory;
        $this->defaultWarehouseResolver = $defaultWarehouseResolver;
        $this->warehouseInventory = $warehouseInventory;
        $this->discountQuotes = $discountQuotes;
        $this->splitShippingQuotes = $splitShippingQuotes;
    }

    public static function forTesting(
        ShippingQuoteServiceInterface $shippingQuotes,
        ?OrderFacadeInterface $orderFacade = null,
        ?ShippingAllocationService $allocation = null,
        ?CheckoutTaxAdvisorInterface $taxAdvisor = null,
        ?InventoryCapabilityInterface $inventory = null,
        ?DefaultWarehouseResolverInterface $defaultWarehouseResolver = null,
        ?WarehouseInventoryCapabilityInterface $warehouseInventory = null,
        ?DiscountQuoteServiceInterface $discountQuotes = null,
        ?SplitShippingQuoteServiceInterface $splitShippingQuotes = null,
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
            discountQuotes: $discountQuotes,
            resolveRuntimeDiscount: false,
            splitShippingQuotes: $splitShippingQuotes,
            resolveRuntimeSplitShipping: false,
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
        ?string $couponCode = null,
        ?string $paymentMethod = null,
        ?array $billingAddress = null,
        string $cartType = 'toc',
    ): array {
        $this->rejectClientAuthority($clientHints);
        if ($lines === []) {
            throw new CheckoutV2ConflictException(self::ERROR_EMPTY, __('结账行不能为空'));
        }
        if (trim($currency) === '') {
            throw new CheckoutV2ConflictException(self::ERROR_CURRENCY, __('结账币种缺失'));
        }
        $currency = strtoupper(trim($currency));
        $cartType = strtolower(trim($cartType)) ?: 'toc';
        $discountsBanned = $this->discountsBannedForCartType($cartType);
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

        $lines = $this->applyFulfillmentSplitKeys($lines, $scope);
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

        $splitPackages = [];
        $quoteArray = null;
        $amountMinor = 0;
        $requestHash = '';
        try {
            $this->quoteCalls++;
            $splitQuotes = $this->splitShippingQuotes();
            if ($splitQuotes !== null && $shippableLines !== []) {
                $split = $splitQuotes->quoteSplit($req, $serviceCode);
                $clientHash = trim((string)($clientHints['shipping_request_hash'] ?? $clientHints['request_hash'] ?? ''));
                if ($clientHash !== '' && !hash_equals($split->requestHash, $clientHash)) {
                    throw new CheckoutV2ConflictException(
                        self::ERROR_QUOTE_TOKEN,
                        __('运费报价已被篡改，请重新选择配送方案'),
                        [
                            'expected_hash_prefix' => substr($split->requestHash, 0, 12),
                        ],
                    );
                }
                $amountMinor = $split->totalAmountMinor;
                $requestHash = $split->requestHash;
                $splitPackages = $split->packages;
                $quoteArray = $split->toArray();
                // 兼容旧 allocate/会话字段形状
                $quote = new \Weline\Shipping\Api\Quote\ShippingQuote(
                    quoteId: $split->quoteId !== '' ? $split->quoteId : ('sq_' . bin2hex(random_bytes(6))),
                    serviceCode: $split->familyCode,
                    amountMinor: $split->totalAmountMinor,
                    currency: $split->currency,
                    currencyPrecision: $split->currencyPrecision,
                    configVersion: $split->configVersion,
                    requestHash: $split->requestHash,
                    isFree: $split->isFree,
                    freeReason: $split->freeReason,
                    expiresAt: gmdate('c', time() + 1800),
                );
            } else {
                $quote = $this->shippingQuotes->quote($req, $serviceCode);
                $amountMinor = $quote->amountMinor;
                $requestHash = $quote->requestHash;
                $quoteArray = $quote->toArray();
            }
        } catch (ShippingQuoteConflictException $e) {
            throw new CheckoutV2ConflictException($e->errorCode(), $e->getMessage(), $e->context(), $e);
        }

        $quoteArray = is_array($quoteArray) ? $quoteArray : $quote->toArray();
        try {
            $rateMeta = $this->shippingQuotes->listOptions(new ShippingQuoteRequest(
                scope: $scope,
                address: $address,
                lines: $shippableLines,
                currency: $currency,
                configVersion: '',
                serviceCode: $serviceCode,
            ));
            foreach ($rateMeta as $opt) {
                if (!is_array($opt)) {
                    continue;
                }
                if (trim((string)($opt['service_code'] ?? '')) !== $serviceCode) {
                    continue;
                }
                foreach (['incoterm', 'duty_notice'] as $k) {
                    if (!empty($opt[$k])) {
                        $quoteArray[$k] = $opt[$k];
                    }
                }
                break;
            }
        } catch (\Throwable) {
            // soft: commerce notice optional
        }

        $overlay = [
            'lines' => $lines,
            'address' => $address,
            'scope' => $scope,
            'currency' => $currency,
            'service_code' => $serviceCode,
            'amount_minor' => $amountMinor,
            'request_hash' => $requestHash,
            'quote_array' => is_array($quoteArray) ? $quoteArray : $quote->toArray(),
            'split_packages' => $splitPackages,
            'error' => null,
            'applied' => false,
        ];
        $overlayEvents = $this->events();
        if ($overlayEvents !== null) {
            $overlayEvents->dispatch('Weline_Checkout::checkout::shipping_quote::overlay', $overlay);
        }
        if (!empty($overlay['error'])) {
            throw new CheckoutV2ConflictException(
                self::ERROR_QUOTE_TOKEN,
                __('货源运费暂不可用，请稍后重试'),
                [
                    'error' => (string)$overlay['error'],
                    'failure_mode' => (string)($overlay['failure_mode'] ?? 'block_checkout'),
                ],
            );
        }
        if (!empty($overlay['degraded'])) {
            $quoteArray = is_array($overlay['quote_array'] ?? null)
                ? $overlay['quote_array']
                : (is_array($quoteArray) ? $quoteArray : $quote->toArray());
            $quoteArray['dropship_freight_degraded'] = true;
            $quoteArray['dropship_freight_degrade_reason'] = (string)($overlay['degrade_reason'] ?? 'dropship_freight_unavailable');
        }
        if (!empty($overlay['applied'])) {
            $amountMinor = max(0, (int)$overlay['amount_minor']);
            $splitPackages = is_array($overlay['split_packages'] ?? null)
                ? $overlay['split_packages']
                : $splitPackages;
            $quoteArray = is_array($overlay['quote_array'] ?? null)
                ? $overlay['quote_array']
                : $quoteArray;
            $quote = new \Weline\Shipping\Api\Quote\ShippingQuote(
                quoteId: $quote->quoteId,
                serviceCode: $quote->serviceCode,
                amountMinor: $amountMinor,
                currency: $quote->currency,
                currencyPrecision: $quote->currencyPrecision,
                configVersion: $quote->configVersion,
                requestHash: $quote->requestHash,
                isFree: $amountMinor === 0 ? $quote->isFree : false,
                freeReason: $amountMinor === 0 ? $quote->freeReason : '',
                expiresAt: $quote->expiresAt,
                scopeVersion: $quote->scopeVersion,
                ruleVersion: $quote->ruleVersion,
            );
        }

        $alloc = $this->allocation->allocate($orders, $amountMinor);
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

        $discountPayload = null;
        $effectiveCoupon = $discountsBanned ? null : $couponCode;
        $discountQuotes = $discountsBanned ? null : $this->discountQuoteService();
        if ($discountQuotes !== null) {
            $discountRequest = new DiscountQuoteRequest(
                scope: $scope,
                address: $address,
                lines: $lines,
                orders: $orders,
                currency: $currency,
                currencyPrecision: 2,
                customerId: $customerId,
                shippingAmountMinor: $quote->amountMinor,
                couponCode: $effectiveCoupon,
                paymentMethod: $paymentMethod,
                cartHash: $cartHash,
            );
            $discountQuote = $discountQuotes->quote($discountRequest);
            $discountPayload = $discountQuote->toArray();
        }

        $goodsSubtotal = $this->ordersSubtotalMinor($orders);
        $taxMinor = (int)($tax['tax_amount_minor'] ?? 0);
        $goodsSubtotalTaxed = $goodsSubtotal + $taxMinor;
        $discountMinorPreview = is_array($discountPayload)
            ? (int)($discountPayload['amount_minor'] ?? 0)
            : 0;
        $baseBeforeCod = max(
            0,
            $goodsSubtotal + $amountMinor + $taxMinor - $discountMinorPreview,
        );
        $codFeeMinor = $this->resolveCodFeeMinor(
            trim((string)($paymentMethod ?? '')),
            $baseBeforeCod,
        );
        $shippingCommerce = $this->buildShippingCommerceSnapshot(
            $amountMinor,
            is_array($quoteArray) ? $quoteArray : $quote->toArray(),
            $scope,
        );
        $depositRatioBps = 3000;
        $depositAmountMinor = $discountsBanned
            ? intdiv($goodsSubtotalTaxed * $depositRatioBps, 10000)
            : 0;
        $balanceAmountMinor = $discountsBanned
            ? max(0, $goodsSubtotalTaxed - $depositAmountMinor) + (int)($alloc['group_shipping_minor'] ?? 0)
            : 0;

        $token = 'qt_' . bin2hex(random_bytes(12));
        $payload = [
            'quote_token' => $token,
            'state' => \Weline\Checkout\Model\CheckoutSession::STATE_QUOTED,
            'currency' => $currency,
            'config_version' => $configVersion,
            'cart_hash' => $cartHash,
            'customer_id' => $customerId,
            'cart_type' => $cartType,
            'order_type' => $cartType,
            'discounts_banned' => $discountsBanned,
            'scope' => $scope,
            'address' => $address,
            'billing_address' => $billingAddress !== null && $billingAddress !== [] ? $billingAddress : $address,
            'service_code' => $serviceCode,
            'lines' => $lines,
            'orders' => $orders,
            'allocation' => $alloc,
            'quote' => array_merge(is_array($quoteArray) ? $quoteArray : $quote->toArray(), [
                // Split quote exposes total_amount_minor; discount validateToken reads amount_minor.
                'amount_minor' => $amountMinor,
                'packages' => $splitPackages,
                'family_code' => is_array($quoteArray) ? (string)($quoteArray['family_code'] ?? $serviceCode) : $serviceCode,
            ]),
            'shipping_packages' => $splitPackages,
            'shipping_commerce' => $shippingCommerce,
            'cod_fee_amount_minor' => $codFeeMinor,
            'totals' => [
                'subtotal_minor' => $goodsSubtotal,
                'shipping_amount_minor' => $amountMinor,
                'tax_amount_minor' => $taxMinor,
                'discount_amount_minor' => $discountMinorPreview,
                'cod_fee_amount_minor' => $codFeeMinor,
                'grand_total_minor' => $baseBeforeCod + $codFeeMinor,
            ],
            'tax' => $tax,
            'discount' => $discountPayload,
            'coupon_code' => $discountsBanned ? '' : strtoupper(trim((string)($couponCode ?? ''))),
            'payment_method' => trim((string)($paymentMethod ?? '')),
            'deposit' => $discountsBanned ? [
                'deposit_ratio_bps' => $depositRatioBps,
                'goods_subtotal_taxed_minor' => $goodsSubtotalTaxed,
                'deposit_amount_minor' => $depositAmountMinor,
                'balance_amount_minor' => $balanceAmountMinor,
                'shipping_in_deposit' => false,
            ] : null,
            'b2b_credit' => null,
        ];
        $enrich = [
            'payload' => $payload,
            'customer_id' => $customerId,
            'discounts_banned' => $discountsBanned,
            'deposit_amount_minor' => $depositAmountMinor,
            'currency' => $currency,
            'scope' => $scope,
            'cart_type' => strtolower(trim((string)($payload['cart_type'] ?? $payload['order_type'] ?? 'toc'))) ?: 'toc',
        ];
        $events = $this->events();
        if ($events !== null) {
            $events->dispatch('Weline_Checkout::checkout::freeze_quote::enrich', $enrich);
            if (is_array($enrich['payload'] ?? null)) {
                $payload = $enrich['payload'];
            }
        }
        $payload['request_hash'] = hash(
            'sha256',
            json_encode(
                $payload + [
                    'shipping_request_hash' => $quote->requestHash,
                    'discount_request_hash' => is_array($discountPayload) ? ($discountPayload['request_hash'] ?? '') : '',
                ],
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
        ?string $paymentMethod = null,
        ?int $b2bCreditApplyMinor = null,
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
            $paymentMethod,
            $b2bCreditApplyMinor,
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
        ?string $paymentMethod = null,
        ?int $b2bCreditApplyMinor = null,
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

        $this->assertSessionDiscountQuote($session, $paymentMethod);
        $session = $this->applyAssetDiscountToSession($session, $customerId, $b2bCreditApplyMinor);

        $session['state'] = \Weline\Checkout\Model\CheckoutSession::STATE_SUBMITTING;
        $session['idempotency_key'] = $idempotencyKey;
        $resolvedPaymentMethod = strtolower(trim((string)($paymentMethod ?? $session['payment_method'] ?? '')));
        if ($resolvedPaymentMethod !== '') {
            $session['payment_method'] = $resolvedPaymentMethod;
        }
        $this->putSession($token, $session);

        $websiteId = (int) ($session['scope']['website_id'] ?? 0);
        $storeId = (int) ($session['scope']['store_id'] ?? 0);
        $cartType = strtolower(trim((string)($session['cart_type'] ?? $session['order_type'] ?? 'toc'))) ?: 'toc';
        $discountsBanned = (bool)($session['discounts_banned'] ?? $this->discountsBannedForCartType($cartType));
        $deferInventory = $discountsBanned; // tob: no reserve until deposit paid
        $commandLines = [];
        $reservations = [];
        $inventory = $deferInventory ? null : $this->inventory();
        foreach ($session['orders'] as $order) {
            $split = (string) $order['split_key'];
            foreach ($order['items'] as $item) {
                $line = array_merge([
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
                ], $this->pricingChromeFromLine($item));
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
        $discount = $discountsBanned ? [] : (is_array($session['discount'] ?? null) ? $session['discount'] : []);
        $discountMinor = $discountsBanned ? 0 : (int)($discount['amount_minor'] ?? 0);
        $deposit = is_array($session['deposit'] ?? null) ? $session['deposit'] : [];
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
                'discount_amount_minor' => $discountMinor,
                'discount_snapshot' => $discount,
                'quote_token' => $token,
                'shipping_quote' => $session['quote'],
                'owner_item_shipping_minor' => $session['allocation']['owner_item_shipping_minor'],
                'inventory_reservations' => $reservations,
                'billing_address' => is_array($session['billing_address'] ?? null)
                    ? $session['billing_address']
                    : $session['address'],
                'cart_type' => $cartType,
                'order_type' => $cartType,
                'discounts_banned' => $discountsBanned,
                'deposit' => $deposit,
                'defer_inventory' => $deferInventory,
                'payment_method' => $resolvedPaymentMethod,
                'cod_fee_amount_minor' => max(0, (int)($session['cod_fee_amount_minor'] ?? 0)),
                'shipping_commerce' => is_array($session['shipping_commerce'] ?? null)
                    ? $session['shipping_commerce']
                    : [],
                'type_payload' => is_array($session['b2b_credit_type_payload'] ?? null)
                    ? $session['b2b_credit_type_payload']
                    : [],
                'type_payload_by_split' => is_array($session['b2b_credit_by_split'] ?? null)
                    ? $session['b2b_credit_by_split']
                    : [],
            ],
        );

        $result = $this->orderFacade->create($cmd);
        $discountQuotes = $discountsBanned ? null : $this->discountQuoteService();
        if ($discountQuotes !== null && is_array($session['discount'] ?? null)) {
            $couponCode = (string)($session['coupon_code'] ?? '');
            if ($couponCode !== '') {
                $discountRequest = new DiscountQuoteRequest(
                    scope: $session['scope'],
                    address: $session['address'],
                    lines: $this->flattenOrderLines($session['orders']),
                    orders: $session['orders'],
                    currency: (string)$session['currency'],
                    customerId: $customerId,
                    shippingAmountMinor: (int)($session['quote']['amount_minor']
                        ?? $session['quote']['total_amount_minor']
                        ?? 0),
                    couponCode: $couponCode,
                    cartHash: (string)($session['cart_hash'] ?? ''),
                );
                $discountQuote = new DiscountQuote(
                    discountQuoteToken: (string)($session['discount']['discount_quote_token'] ?? ''),
                    amountMinor: (int)($session['discount']['amount_minor'] ?? 0),
                    currency: (string)($session['discount']['currency'] ?? $session['currency']),
                    currencyPrecision: (int)($session['discount']['currency_precision'] ?? 2),
                    requestHash: (string)($session['discount']['request_hash'] ?? ''),
                    lines: is_array($session['discount']['lines'] ?? null) ? $session['discount']['lines'] : [],
                    appliedRuleIds: is_array($session['discount']['applied_rule_ids'] ?? null) ? $session['discount']['applied_rule_ids'] : [],
                    couponCode: $couponCode,
                    actionPayloads: is_array($session['discount']['action_payloads'] ?? null) ? $session['discount']['action_payloads'] : [],
                    freeShipping: !empty($session['discount']['free_shipping']),
                    shippingDiscountMinor: (int)($session['discount']['shipping_discount_minor'] ?? 0),
                );
                $orderUuid = (string)($result->orderUuids[0] ?? '');
                $discountQuotes->redeemCoupon($couponCode, [
                    'customer_id' => $customerId,
                    'order_id' => $orderUuid,
                    'subtotal' => $this->ordersSubtotalMajor($session['orders']),
                ], $discountQuote);
            }
        }
        if ($discountsBanned && $cartType === 'tob') {
            $session['hang_orders'] = $this->createTobHangOrders($result, $session, $customerId, $websiteId);
        }
        $session['state'] = \Weline\Checkout\Model\CheckoutSession::STATE_SUBMITTED;
        $session['submitted_result'] = $result->toArray();
        $session['reservations'] = $reservations;
        $this->putSession($token, $session);

        return $result;
    }

    /**
     * Optional B2B hang creation — no hard dependency when module absent.
     *
     * @param array<string,mixed> $session
     * @return list<array<string,mixed>>
     */
    private function createTobHangOrders(
        CreateCheckoutGroupResult $result,
        array $session,
        ?int $customerId,
        int $websiteId,
    ): array {
        if (!class_exists(\Weline\B2B\Service\B2BHangOrderService::class)) {
            return [];
        }
        try {
            $hang = ObjectManager::getInstance(\Weline\B2B\Service\B2BHangOrderService::class);
            if (!$hang instanceof \Weline\B2B\Service\B2BHangOrderService) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        $deposit = is_array($session['deposit'] ?? null) ? $session['deposit'] : [];
        $created = [];
        foreach ($result->orders as $orderRow) {
            $orderUuid = (string)($orderRow['order_uuid'] ?? '');
            if ($orderUuid === '') {
                continue;
            }
            $money = is_array($orderRow['money'] ?? null) ? $orderRow['money'] : [];
            $goods = (int)($money['subtotal_minor'] ?? 0) + (int)($money['tax_amount_minor'] ?? 0);
            if ($goods <= 0 && isset($deposit['goods_subtotal_taxed_minor'])) {
                $goods = (int)$deposit['goods_subtotal_taxed_minor'];
            }
            $shipping = (int)($money['shipping_amount_minor'] ?? 0);
            $isOwner = (bool)($orderRow['is_shipping_charge_owner'] ?? false);
            try {
                $created[] = $hang->createAwaitingDeposit([
                    'order_ref' => $orderUuid,
                    'customer_id' => (string)($customerId ?? ''),
                    'website_id' => $websiteId,
                    'goods_subtotal_taxed_minor' => $goods,
                    'shipping_amount_minor' => $shipping,
                    'is_shipping_owner' => $isOwner,
                    'deposit_ratio_bps' => (int)($deposit['deposit_ratio_bps'] ?? 3000),
                    'token_ids' => is_array($session['b2b_token_ids'] ?? null)
                        ? $session['b2b_token_ids']
                        : [],
                ]);
            } catch (\Throwable) {
                // fail soft for optional hang path in retail-capable installs
            }
        }

        return $created;
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
        foreach ([
            'shipping_amount',
            'shipping_amount_minor',
            'tax_amount',
            'tax_amount_minor',
            'discount_amount',
            'discount_amount_minor',
            'grand_total',
            'grand_total_minor',
        ] as $key) {
            if (array_key_exists($key, $clientHints)) {
                throw new CheckoutV2ConflictException(
                    str_starts_with($key, 'discount_') ? self::ERROR_CLIENT_DISCOUNT : self::ERROR_CLIENT_MONEY,
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

    /**
     * 万能结账经事件读取资产折扣贡献（Payment 只提供策略，业务模块 Observer 回写 session）。
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private function applyAssetDiscountToSession(array $session, ?int $customerId, ?int $applyMinor): array
    {
        $eventData = [
            'session' => $session,
            'customer_id' => $customerId,
            'apply_minor' => $applyMinor,
        ];
        $events = $this->events();
        if ($events !== null) {
            $events->dispatch('Weline_Checkout::checkout::asset_discount::apply', $eventData);
            if (is_array($eventData['session'] ?? null)) {
                return $eventData['session'];
            }
        }

        return $session;
    }

    private function events(): ?\Weline\Framework\Event\EventsManager
    {
        try {
            $events = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);

            return $events instanceof \Weline\Framework\Event\EventsManager ? $events : null;
        } catch (\Throwable) {
            return null;
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
     * Preserve Cart freeze deal chrome into order/session line snapshots.
     *
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function pricingChromeFromLine(array $line): array
    {
        $unit = max(0, (int)($line['unit_price_minor'] ?? 0));
        $compare = max(0, (int)($line['compare_at_minor'] ?? 0));
        $label = trim((string)($line['campaign_label'] ?? ''));
        $url = trim((string)($line['campaign_url'] ?? ''));
        $hasDeal = !empty($line['has_deal']) || ($compare > $unit && $unit > 0);
        $out = [];
        if ($compare > 0) {
            $out['compare_at_minor'] = $compare;
        }
        if ($hasDeal) {
            $out['has_deal'] = true;
        }
        if ($label !== '') {
            $out['campaign_label'] = $label;
        }
        if ($url !== '') {
            $out['campaign_url'] = $url;
        }
        $options = $line['options'] ?? null;
        if (\is_array($options) && $options !== []) {
            $out['options'] = $options;
        }
        $image = trim((string)($line['image'] ?? $line['image_url'] ?? $line['image_src'] ?? ''));
        if ($image !== '') {
            $out['image'] = $image;
        }

        return $out;
    }

    /**
     * Inventory 只读分仓：给可发货行打上 split_key=wh:{id}（确定性）。
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function applyFulfillmentSplitKeys(array $lines, array $scope): array
    {
        if (!$this->resolveRuntimeInventory && $this->splitShippingQuotes === null) {
            // 单测默认不触发真实 Inventory 规划，避免污染既有 forTesting 路径
            return $lines;
        }
        if (!interface_exists(\Weline\Inventory\Api\FulfillmentSplitPlanInterface::class)) {
            return $lines;
        }
        try {
            /** @var \Weline\Inventory\Api\FulfillmentSplitPlanInterface $plan */
            $plan = ObjectManager::getInstance(\Weline\Inventory\Api\FulfillmentSplitPlanInterface::class);
            $packages = $plan->planPackages(
                (int)($scope['website_id'] ?? 0),
                (int)($scope['store_id'] ?? 0),
                $lines,
            );
        } catch (\Throwable) {
            return $lines;
        }
        if ($packages === []) {
            return $lines;
        }
        $byUuid = [];
        $bySkuOffer = [];
        foreach ($packages as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $splitKey = (string)($pkg['split_key'] ?? '');
            if ($splitKey === '') {
                continue;
            }
            foreach (($pkg['lines'] ?? []) as $pkgLine) {
                if (!is_array($pkgLine)) {
                    continue;
                }
                $uuid = trim((string)($pkgLine['line_uuid'] ?? ''));
                if ($uuid !== '') {
                    $byUuid[$uuid] = $splitKey;
                }
                $sku = trim((string)($pkgLine['sku'] ?? ''));
                $offer = (int)($pkgLine['offer_id'] ?? $pkgLine['product_offer_id'] ?? 0);
                $bySkuOffer[$sku . '|' . $offer] = $splitKey;
            }
        }
        $out = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (!(bool)($line['requires_shipping'] ?? true)) {
                $out[] = $line;
                continue;
            }
            $uuid = trim((string)($line['line_uuid'] ?? ''));
            $sku = trim((string)($line['sku'] ?? ''));
            $offer = (int)($line['offer_id'] ?? $line['product_offer_id'] ?? 0);
            if ($uuid !== '' && isset($byUuid[$uuid])) {
                $line['split_key'] = $byUuid[$uuid];
            } elseif (isset($bySkuOffer[$sku . '|' . $offer])) {
                $line['split_key'] = $bySkuOffer[$sku . '|' . $offer];
            }
            $out[] = $line;
        }

        return $out;
    }

    private function splitShippingQuotes(): ?SplitShippingQuoteServiceInterface
    {
        if ($this->splitShippingQuotes instanceof SplitShippingQuoteServiceInterface) {
            return $this->splitShippingQuotes;
        }
        if (!$this->resolveRuntimeSplitShipping) {
            return null;
        }
        if (!interface_exists(SplitShippingQuoteServiceInterface::class)) {
            return null;
        }
        try {
            $svc = ObjectManager::getInstance(SplitShippingQuoteServiceInterface::class);

            return $svc instanceof SplitShippingQuoteServiceInterface ? $svc : null;
        } catch (\Throwable) {
            return null;
        }
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
            $buckets[$split]['items'][] = array_merge([
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
            ], $this->pricingChromeFromLine($line));
            $buckets[$split]['subtotal_minor'] += $row;
            if ($requires) {
                $buckets[$split]['requires_shipping'] = true;
            }
        }
        ksort($buckets);

        return array_values($buckets);
    }

    private function discountQuoteService(): ?DiscountQuoteServiceInterface
    {
        if ($this->discountQuotes instanceof DiscountQuoteServiceInterface) {
            return $this->discountQuotes;
        }
        if (!$this->resolveRuntimeDiscount) {
            return null;
        }
        $resolved = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(DiscountQuoteServiceInterface::class);
        if (!$resolved instanceof DiscountQuoteServiceInterface) {
            return null;
        }

        return $this->discountQuotes = $resolved;
    }

    /**
     * @param list<array<string, mixed>> $orders
     * @return list<array<string, mixed>>
     */
    private function flattenOrderLines(array $orders): array
    {
        $lines = [];
        foreach ($orders as $order) {
            foreach ($order['items'] ?? [] as $item) {
                $lines[] = $item;
            }
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $orders
     */
    private function ordersSubtotalMajor(array $orders): float
    {
        $minor = 0;
        foreach ($orders as $order) {
            $minor += (int)($order['subtotal_minor'] ?? 0);
        }

        return $minor / 100;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function assertSessionDiscountQuote(array $session, ?string $paymentMethod): void
    {
        $cartType = strtolower(trim((string)($session['cart_type'] ?? $session['order_type'] ?? 'toc'))) ?: 'toc';
        if ((bool)($session['discounts_banned'] ?? false) || $this->discountsBannedForCartType($cartType)) {
            if ((int)($session['discount']['amount_minor'] ?? 0) > 0
                || trim((string)($session['coupon_code'] ?? '')) !== ''
            ) {
                throw new CheckoutV2ConflictException(
                    self::ERROR_CLIENT_DISCOUNT,
                    __('批发结账禁止优惠与券'),
                    ['cart_type' => $cartType],
                );
            }

            return;
        }

        $discountQuotes = $this->discountQuoteService();
        if ($discountQuotes === null || !is_array($session['discount'] ?? null)) {
            return;
        }

        $couponCode = (string)($session['coupon_code'] ?? '');
        $discount = $session['discount'];
        // freezeQuote 时可能尚未选定支付方式；校验折扣 token 须用冻结时的 paymentMethod，
        // 否则 submit 换 PayPal 等会误报「折扣报价已失效」。支付相关优惠另在下方校验。
        $frozenPaymentMethod = trim((string)($session['payment_method'] ?? $discount['payment_method'] ?? '')) ?: null;
        $discountLines = is_array($session['lines'] ?? null) && $session['lines'] !== []
            ? $session['lines']
            : $this->flattenOrderLines($session['orders']);
        $discountRequest = new DiscountQuoteRequest(
            scope: $session['scope'],
            address: $session['address'],
            lines: $discountLines,
            orders: $session['orders'],
            currency: (string)$session['currency'],
            customerId: isset($session['customer_id']) ? (int)$session['customer_id'] : null,
            shippingAmountMinor: (int)($session['quote']['amount_minor']
                ?? $session['quote']['total_amount_minor']
                ?? 0),
            couponCode: $couponCode !== '' ? $couponCode : null,
            paymentMethod: $frozenPaymentMethod,
            cartHash: (string)($session['cart_hash'] ?? ''),
        );
        $discountQuote = new DiscountQuote(
            discountQuoteToken: (string)($discount['discount_quote_token'] ?? ''),
            amountMinor: (int)($discount['amount_minor'] ?? 0),
            currency: (string)($discount['currency'] ?? $session['currency']),
            currencyPrecision: (int)($discount['currency_precision'] ?? 2),
            requestHash: (string)($discount['request_hash'] ?? ''),
            lines: is_array($discount['lines'] ?? null) ? $discount['lines'] : [],
            appliedRuleIds: is_array($discount['applied_rule_ids'] ?? null) ? $discount['applied_rule_ids'] : [],
            couponCode: $couponCode,
            actionPayloads: is_array($discount['action_payloads'] ?? null) ? $discount['action_payloads'] : [],
            freeShipping: !empty($discount['free_shipping']),
            shippingDiscountMinor: (int)($discount['shipping_discount_minor'] ?? 0),
        );

        if (!$discountQuotes->validateToken($discountRequest, $discountQuote)) {
            throw new CheckoutV2ConflictException(
                self::ERROR_QUOTE_TOKEN,
                __('折扣报价已失效，请重新报价确认'),
                ['discount_quote_token' => $discountQuote->discountQuoteToken],
            );
        }

        $resolvedPayment = trim((string)($paymentMethod ?? $session['payment_method'] ?? ''));
        $actionPayloads = $discountQuote->actionPayloads;
        $hasDiscountEffect = (int)$discountQuote->amountMinor > 0
            || (int)$discountQuote->shippingDiscountMinor > 0
            || $discountQuote->freeShipping;
        // No monetary / shipping effect: action payloads may still list rule types from a
        // session coupon the UI cleared — do not block payment compatibility on ghost actions.
        if ($resolvedPayment === '' || $actionPayloads === [] || !$hasDiscountEffect) {
            return;
        }

        $validation = ObjectManager::getInstance(DiscountValidationService::class);
        foreach ($actionPayloads as $payload) {
            $actionCode = (string)($payload['type'] ?? '');
            if ($actionCode === '') {
                continue;
            }
            if (!$validation->validateDiscountForPayment($resolvedPayment, $actionCode)) {
                throw new CheckoutV2ConflictException(
                    self::ERROR_CLIENT_DISCOUNT,
                    __('当前支付方式「%{1}」不支持已选优惠方式：%{2}', [$resolvedPayment, $actionCode]),
                    ['payment_method' => $resolvedPayment, 'action_code' => $actionCode],
                );
            }
        }
    }

    /** @param list<array<string,mixed>> $orders */
    private function ordersSubtotalMinor(array $orders): int
    {
        $total = 0;
        foreach ($orders as $order) {
            $total += (int)($order['subtotal_minor'] ?? 0);
        }

        return $total;
    }

    private function discountsBannedForCartType(string $cartType): bool
    {
        $code = strtolower(trim($cartType)) ?: 'toc';
        if ($code === 'tob') {
            return true;
        }
        try {
            if (class_exists(\Weline\Order\Service\OrderCalculatorGate::class)) {
                $calcGate = ObjectManager::getInstance(\Weline\Order\Service\OrderCalculatorGate::class);
                if ($calcGate instanceof \Weline\Order\Service\OrderCalculatorGate) {
                    return !$calcGate->allowsPriceChangingCalculators($code);
                }
            }
        } catch (\Throwable) {
            // fall through — toc default
        }

        return false;
    }

    private function resolveCodFeeMinor(string $paymentMethod, int $baseBeforeCod): int
    {
        return (new CheckoutCodFeeApplier())->apply($paymentMethod, $baseBeforeCod)['cod_fee_amount_minor'];
    }

    /**
     * @param array<string, mixed> $quoteArray
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildShippingCommerceSnapshot(int $amountMinor, array $quoteArray, array $scope): array
    {
        $out = [];
        try {
            /** @var \Weline\Shipping\Service\SplitShipmentShippingService $splitSvc */
            $splitSvc = ObjectManager::getInstance(\Weline\Shipping\Service\SplitShipmentShippingService::class);
            $out = $splitSvc->buildCheckoutSnapshot($amountMinor, [
                'website_id' => (int)($scope['website_id'] ?? 0),
                'scope_type' => 'website',
                'scope_id' => (int)($scope['website_id'] ?? 0),
            ]);
        } catch (\Throwable) {
            $out = [];
        }
        foreach (['incoterm', 'duty_notice'] as $key) {
            $val = trim((string)($quoteArray[$key] ?? ''));
            if ($val !== '') {
                $out[$key] = $val;
            }
        }

        return $out;
    }
}
