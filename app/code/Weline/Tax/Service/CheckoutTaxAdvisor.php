<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Tax\Api\CheckoutTaxAdvisorInterface;
use Weline\Tax\Api\TaxEngineInterface;

/**
 * Checkout 侧税务顾问（P3B-002）：off/shadow 不改变成交金额；allowlist/on 服务端算税。
 * 价内税目录：国内不价外加收；跨境默认走目的地进口税/关税预估，避免销售税与进口 VAT 双计。
 * 引擎故障且无同版本 LKG → 阻断（缺税则 NO_RULE 对销售税 fail-soft 为 0，不卡死结账）。
 */
final class CheckoutTaxAdvisor implements CheckoutTaxAdvisorInterface
{
    public const CAPABILITY = 'tax';
    public const ERROR_BLOCKED = 'checkout_tax_blocked';
    public const ERROR_RULE_VERSION = 'checkout_tax_rule_version_conflict';
    public const NOTE_PRICES_INCLUDE_TAX = 'prices_include_tax_no_extra_charge';
    public const NOTE_NO_MATCHING_RULE = 'no_matching_tax_rule_soft_zero';

    private readonly CommerceRolloutGateInterface $rollout;
    private readonly TaxLkgStore $lkg;
    private readonly TaxScopeConfig $scopeConfig;
    private readonly TaxDestinationCheckoutPolicy $destinationPolicy;

    public function __construct(
        private readonly TaxEngine $engine = new TaxEngine(),
        ?CommerceRolloutGateInterface $rollout = null,
        ?TaxLkgStore $lkg = null,
        ?TaxScopeConfig $scopeConfig = null,
        ?TaxDestinationCheckoutPolicy $destinationPolicy = null,
    ) {
        $this->rollout = $rollout ?? self::newRollout();
        $this->lkg = $lkg ?? new TaxLkgStore();
        $this->scopeConfig = $scopeConfig ?? new TaxScopeConfig();
        $this->destinationPolicy = $destinationPolicy ?? new TaxDestinationCheckoutPolicy();
    }

    public static function forTestingStub(): self
    {
        $gate = self::newTestingRollout();
        $gate->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);

        return new self(
            TaxEngine::forTesting(),
            $gate,
            TaxLkgStore::forTesting(),
            TaxScopeConfig::forTesting(['prices_include_tax' => true]),
        );
    }

    public static function forTestingActive(
        ?TaxLkgStore $lkg = null,
        bool $pricesIncludeTax = true,
        string $collectSalesTaxCountries = '',
    ): self {
        $lkg ??= TaxLkgStore::forTesting();
        $engine = TaxEngine::forTesting($lkg);
        $gate = self::newTestingRollout();
        $gate->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_ALLOWLIST, ['website:0']);

        return new self(
            $engine,
            $gate,
            $lkg,
            TaxScopeConfig::forTesting([
                'enabled' => true,
                'prices_include_tax' => $pricesIncludeTax,
                'default_jurisdiction' => 'CN|',
                'collect_sales_tax_countries' => $collectSalesTaxCountries,
            ]),
        );
    }

    public function engine(): TaxEngine
    {
        return $this->engine;
    }

    public function rollout(): CommerceRolloutGateInterface
    {
        return $this->rollout;
    }

    public function lkg(): ?TaxLkgStore
    {
        return $this->lkg;
    }

    public function isEffectivelyOn(int $websiteId, int $storeId = 0, int $channelId = 0): bool
    {
        $mode = $this->rollout->mode(self::CAPABILITY);
        if ($mode === CommerceRolloutGateInterface::MODE_OFF
            || $mode === CommerceRolloutGateInterface::MODE_SHADOW
        ) {
            return false;
        }

        $subject = $storeId >= 1 && $channelId >= 1
            ? TaxRolloutGate::tupleKey($websiteId, $storeId, $channelId)
            : 'website:' . $websiteId;

        return $this->rollout->isEffectivelyOn(self::CAPABILITY, $subject);
    }

    /**
     * @param list<array<string, mixed>> $orders bucketed checkout orders
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $address
     * @param array<string, mixed> $shippingContext
     * @return array<string, mixed>
     */
    public function quoteTax(
        array $orders,
        array $scope,
        array $address,
        string $currency,
        array $shippingContext = [],
    ): array {
        $websiteId = (int) ($scope['website_id'] ?? 0);
        $storeId = (int) ($scope['store_id'] ?? 0);
        $channelId = (int) ($scope['channel_id'] ?? 0);
        $policy = $this->resolveDestinationPolicy($websiteId, $storeId, $address, $scope, $shippingContext);

        if (!$this->isEffectivelyOn($websiteId, $storeId, $channelId)) {
            $rolloutMode = $this->rollout->mode(self::CAPABILITY);
            $sales = [
                'mode' => 'none',
                'engine' => 'none',
                'tax_amount_minor' => 0,
                'note' => $rolloutMode === CommerceRolloutGateInterface::MODE_SHADOW
                    ? 'mode_shadow_no_write'
                    : 'mode_off_stub',
                'rule_schema_version' => '',
                'rule_set_hash' => '',
                'lines' => [],
                'jurisdiction_key' => $this->jurisdictionFromAddress($address),
                'currency' => strtoupper(trim($currency)),
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
                'scope_key' => '',
                'destination_policy' => $policy,
            ];

            return $this->mergeDutyEstimate($sales, $orders, $scope, $address, $currency, $shippingContext, $policy);
        }

        if (!$policy['charge_sales_tax']) {
            $sales = [
                'mode' => (string)$policy['profile'],
                'engine' => 'none',
                'tax_amount_minor' => 0,
                'note' => (string)$policy['note'],
                'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
                'rule_set_hash' => '',
                'lines' => [],
                'jurisdiction_key' => $this->jurisdictionFromAddress($address),
                'currency' => strtoupper(trim($currency)),
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
                'scope_key' => '',
                'prices_include_tax' => (bool)($policy['prices_include_tax'] ?? false),
                'destination_policy' => $policy,
            ];

            return $this->mergeDutyEstimate($sales, $orders, $scope, $address, $currency, $shippingContext, $policy);
        }

        $request = $this->request($orders, $scope, $address, $currency);
        $jurisdiction = (string) $request['jurisdiction_key'];

        try {
            $result = $this->engine->calculate($request);
        } catch (TaxConflictException $e) {
            if ($e->errorCode() === TaxEngineInterface::ERROR_NO_RULE) {
                $sales = [
                    'mode' => 'policy_soft_zero',
                    'engine' => 'none',
                    'tax_amount_minor' => 0,
                    'note' => self::NOTE_NO_MATCHING_RULE,
                    'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
                    'rule_set_hash' => '',
                    'lines' => [],
                    'jurisdiction_key' => $jurisdiction,
                    'currency' => strtoupper(trim($currency)),
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                    'channel_id' => $channelId,
                    'scope_key' => '',
                    'destination_policy' => $policy,
                ];

                return $this->mergeDutyEstimate($sales, $orders, $scope, $address, $currency, $shippingContext, $policy);
            }
            try {
                $current = $this->engine->ruleSetSnapshot($request);
                $hash = (string) ($current['rule_set_hash'] ?? '');
                $scopeKey = (string) ($current['scope_key'] ?? '');
                $fallback = $this->lkg->readVerified(
                    TaxEngine::SCHEMA_VERSION,
                    $hash,
                    $scopeKey,
                );
                if ($fallback === null) {
                    throw new TaxConflictException(
                        self::ERROR_BLOCKED,
                        __('税务不可用且无同版本同 Scope 的 LKG，新结账已阻断'),
                        [
                            'cause' => $e->errorCode(),
                            'jurisdiction' => $jurisdiction,
                            'rule_set_hash' => $hash,
                            'scope_key' => $scopeKey,
                        ],
                        $e,
                    );
                }
                $result = TaxEngine::fromSnapshot($fallback)->calculate($request);
                $result['source'] = TaxEngine::SOURCE_LKG;
            } catch (TaxConflictException $fallbackError) {
                if ($fallbackError->errorCode() === self::ERROR_BLOCKED) {
                    throw $fallbackError;
                }
                throw new TaxConflictException(
                    self::ERROR_BLOCKED,
                    __('税务不可用且同版本 LKG 无法安全回放，新结账已阻断'),
                    [
                        'cause' => $e->errorCode(),
                        'fallback_cause' => $fallbackError->errorCode(),
                        'jurisdiction' => $jurisdiction,
                    ],
                    $fallbackError,
                );
            }
        }

        $sales = [
            'mode' => 'engine',
            'engine' => (string) ($result['source'] ?? TaxEngine::SOURCE_ENGINE),
            'tax_amount_minor' => (int) $result['tax_amount_minor'],
            'note' => 'server_calculated_tax',
            'rule_schema_version' => (string) $result['rule_schema_version'],
            'rule_set_hash' => (string) $result['rule_set_hash'],
            'lines' => $result['lines'],
            'jurisdiction_key' => (string) $result['jurisdiction_key'],
            'currency' => (string) $result['currency'],
            'website_id' => (int) $result['website_id'],
            'store_id' => (int) $result['store_id'],
            'channel_id' => $channelId,
            'scope_key' => (string) $result['scope_key'],
            'destination_policy' => $policy,
        ];

        return $this->mergeDutyEstimate($sales, $orders, $scope, $address, $currency, $shippingContext, $policy);
    }

    /**
     * Duty estimates are independent of sales-tax rollout (off/shadow still charge DDU).
     *
     * @param array<string,mixed> $sales
     * @param list<array<string,mixed>> $orders
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $address
     * @param array<string,mixed> $shippingContext
     * @return array<string,mixed>
     */
    /**
     * @param array<string,mixed>|null $policy
     */
    private function mergeDutyEstimate(
        array $sales,
        array $orders,
        array $scope,
        array $address,
        string $currency,
        array $shippingContext,
        ?array $policy = null,
    ): array {
        $goodsMinor = isset($shippingContext['goods_subtotal_minor'])
            ? max(0, (int)$shippingContext['goods_subtotal_minor'])
            : $this->ordersGoodsMinor($orders);
        $shippingMinor = max(0, (int)($shippingContext['shipping_amount_minor'] ?? 0));
        $dest = (string)($address['country_code'] ?? $address['country'] ?? '');
        $origin = (string)(
            $shippingContext['origin_country']
            ?? $scope['origin_country']
            ?? $scope['seller_country']
            ?? 'CN'
        );
        $policy ??= $sales['destination_policy'] ?? null;
        $estimate = (new DutyEstimateService())->estimate([
            'goods_subtotal_minor' => $goodsMinor,
            'shipping_amount_minor' => $shippingMinor,
            'destination_country' => $dest,
            'origin_country' => $origin,
            'duty_notice' => (string)($shippingContext['duty_notice'] ?? ''),
            'currency' => $currency,
            'charge_customs_duty' => is_array($policy) ? (bool)($policy['charge_customs_duty'] ?? true) : true,
            'charge_import_vat' => is_array($policy) ? (bool)($policy['charge_import_vat'] ?? true) : true,
        ]);

        $salesTax = max(0, (int)($sales['tax_amount_minor'] ?? 0));
        $dutyCharged = max(0, (int)$estimate['charged_minor']);
        $lines = is_array($sales['lines'] ?? null) ? $sales['lines'] : [];
        foreach ($estimate['lines'] as $line) {
            $lines[] = $line;
        }

        $sales['tax_amount_minor'] = $salesTax + $dutyCharged;
        $sales['sales_tax_amount_minor'] = $salesTax;
        $sales['duty_amount_minor'] = (int)$estimate['duty_amount_minor'];
        $sales['import_tax_amount_minor'] = (int)$estimate['import_tax_amount_minor'];
        $sales['duty_charged_minor'] = $dutyCharged;
        $sales['duty_notice'] = (string)$estimate['duty_notice'];
        $sales['duty_estimate_reason'] = (string)$estimate['reason'];
        $sales['lines'] = $lines;
        if (is_array($policy)) {
            $sales['destination_policy'] = $policy;
            $sales['destination_tax_profile'] = (string)($policy['profile'] ?? '');
        }
        // OrderFacade rejects mode=none with non-zero tax/lines; promote to policy/duty mode.
        if ($dutyCharged > 0) {
            $mode = (string)($sales['mode'] ?? '');
            if ($mode === 'none' || $mode === 'stub_zero' || $mode === '') {
                $profile = is_array($policy) ? trim((string)($policy['profile'] ?? '')) : '';
                $sales['mode'] = $profile !== '' ? $profile : 'duty_estimate';
            }
            if ((string)($sales['note'] ?? '') === 'mode_off_stub') {
                $sales['note'] = 'mode_off_stub_plus_duty_estimate';
            }
        }

        return $sales;
    }

    /**
     * @param list<array<string,mixed>> $orders
     */
    private function ordersGoodsMinor(array $orders): int
    {
        $sum = 0;
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $items = $order['items'] ?? $order['lines'] ?? null;
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $sum += max(0, (int)($item['row_total_minor'] ?? 0));
            }
        }

        return $sum;
    }

    /**
     * @param array<string, mixed> $sessionTax
     * @param list<array<string,mixed>> $orders
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $address
     */
    public function assertRuleVersion(
        array $sessionTax,
        array $orders,
        array $scope,
        array $address,
        string $currency,
        ?string $expectedRuleSetHash,
    ): void {
        $got = (string) ($sessionTax['rule_set_hash'] ?? '');
        if ($expectedRuleSetHash !== null && !hash_equals($got, $expectedRuleSetHash)) {
            throw new TaxConflictException(
                self::ERROR_RULE_VERSION,
                __('税务规则版本已变更，请重新报价'),
                [
                    'session_rule_set_hash' => $got,
                    'expected_rule_set_hash' => $expectedRuleSetHash,
                ],
            );
        }
        if ((string) ($sessionTax['mode'] ?? '') !== 'engine') {
            return;
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $got) !== 1) {
            throw new TaxConflictException(
                self::ERROR_RULE_VERSION,
                __('税务报价缺少有效规则版本，请重新报价'),
            );
        }

        try {
            $request = $this->request($orders, $scope, $address, $currency);
            $live = $this->engine->ruleSetSnapshot($request);
        } catch (TaxConflictException $e) {
            throw new TaxConflictException(
                self::ERROR_BLOCKED,
                __('税务当前规则版本不可验证，新结账已阻断'),
                ['cause' => $e->errorCode()],
                $e,
            );
        }
        $liveHash = (string) ($live['rule_set_hash'] ?? '');
        $liveScopeKey = (string) ($live['scope_key'] ?? '');
        $sessionScopeKey = (string) ($sessionTax['scope_key'] ?? '');
        if (!hash_equals($got, $liveHash)
            || $sessionScopeKey === ''
            || !hash_equals($sessionScopeKey, $liveScopeKey)
        ) {
            throw new TaxConflictException(
                self::ERROR_RULE_VERSION,
                __('税务规则版本已变更，请重新报价'),
                [
                    'session_rule_set_hash' => $got,
                    'live_rule_set_hash' => $liveHash,
                    'session_scope_key' => $sessionScopeKey,
                    'live_scope_key' => $liveScopeKey,
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $billingAddress
     */
    public function validateTaxIdentity(array $identity, array $billingAddress): void
    {
        $service = new BuyerTaxIdentityService();
        $desc = $service->describeForAddress($billingAddress, $identity, false);
        if ($desc['errors'] !== []) {
            throw new TaxConflictException(
                'checkout_tax_identity_invalid',
                __('买家税号无效，请检查后重试'),
                ['errors' => $desc['errors']],
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    private function request(array $orders, array $scope, array $address, string $currency): array
    {
        $lines = [];
        $seen = [];
        foreach ($orders as $order) {
            $items = $order['items'] ?? null;
            if (!is_array($items)) {
                throw new TaxConflictException(
                    self::ERROR_BLOCKED,
                    __('税务报价行结构无效，新结账已阻断'),
                );
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new TaxConflictException(
                        self::ERROR_BLOCKED,
                        __('税务报价行结构无效，新结账已阻断'),
                    );
                }
                $lineId = trim((string) ($item['line_uuid'] ?? ''));
                if ($lineId === '' || isset($seen[$lineId])) {
                    throw new TaxConflictException(
                        self::ERROR_BLOCKED,
                        __('税务报价行标识缺失或重复，新结账已阻断'),
                        ['line_id' => $lineId],
                    );
                }
                $seen[$lineId] = true;
                $lines[] = [
                    'line_id' => $lineId,
                    'tax_class_code' => (string) ($item['tax_class_code'] ?? 'standard'),
                    'taxable_amount_minor' => (int) ($item['row_total_minor'] ?? 0),
                ];
            }
        }
        if ($lines === []) {
            throw new TaxConflictException(
                self::ERROR_BLOCKED,
                __('税务报价行不能为空，新结账已阻断'),
            );
        }

        return [
            'website_id' => (int) ($scope['website_id'] ?? 0),
            'store_id' => (int) ($scope['store_id'] ?? 0),
            'currency' => strtoupper(trim($currency)),
            'jurisdiction_key' => $this->jurisdictionFromAddress($address),
            'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
            'lines' => $lines,
        ];
    }

    /**
     * @param array<string, mixed> $address
     */
    private function jurisdictionFromAddress(array $address): string
    {
        $country = strtoupper(trim((string) ($address['country_code'] ?? $address['country'] ?? 'CN')));
        $regionCode = strtoupper(trim((string) (
            $address['region_code']
            ?? $address['province_code']
            ?? $address['state_code']
            ?? ''
        )));
        $regionName = strtoupper(trim((string) (
            $address['region']
            ?? $address['province']
            ?? $address['state']
            ?? ''
        )));
        $region = '';
        if (preg_match('/^[A-Z]{2}$/', $regionCode) === 1) {
            $region = $regionCode;
        } elseif (preg_match('/^[A-Z]{2}$/', $regionName) === 1) {
            $region = $regionName;
        } elseif ($country === 'US') {
            $region = $this->normalizeUsRegionAlias($regionCode !== '' ? $regionCode : $regionName);
        } else {
            $region = $regionCode !== '' ? $regionCode : $regionName;
        }

        return $country . '|' . $region;
    }

    private function normalizeUsRegionAlias(string $region): string
    {
        $region = strtoupper(trim($region));
        if ($region === '' || preg_match('/^[A-Z]{2}$/', $region) === 1) {
            return $region;
        }
        // Common storefront labels → ISO2 (seed rules use US|CA etc.).
        static $aliases = [
            'CALIFORNIA' => 'CA',
            'NEW YORK' => 'NY',
            '纽约州' => 'NY',
            'TEXAS' => 'TX',
            'FLORIDA' => 'FL',
            'WASHINGTON' => 'WA',
            'ILLINOIS' => 'IL',
            'PENNSYLVANIA' => 'PA',
            'OHIO' => 'OH',
            'GEORGIA' => 'GA',
        ];

        return $aliases[$region] ?? $region;
    }

    /**
     * @param array<string,mixed> $address
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $shippingContext
     * @return array{
     *   profile:string,
     *   charge_sales_tax:bool,
     *   charge_customs_duty:bool,
     *   charge_import_vat:bool,
     *   note:string,
     *   destination_country:string,
     *   origin_country:string,
     *   prices_include_tax:bool
     * }
     */
    private function resolveDestinationPolicy(
        int $websiteId,
        int $storeId,
        array $address,
        array $scope,
        array $shippingContext,
    ): array {
        $pricesIncludeTax = true;
        $collectCountries = null;
        $defaultJurisdiction = 'CN|';
        try {
            $resolved = $this->scopeConfig->resolve(max(0, $websiteId), max(0, $storeId));
            $pricesIncludeTax = $this->boolValue($resolved['prices_include_tax'] ?? true);
            $collectCountries = $resolved['collect_sales_tax_countries'] ?? null;
            $defaultJurisdiction = (string)($resolved['default_jurisdiction'] ?? 'CN|');
        } catch (\Throwable) {
            // Keep inclusive-safe defaults.
        }

        $dest = (string)($address['country_code'] ?? $address['country'] ?? '');
        $origin = (string)(
            $shippingContext['origin_country']
            ?? $scope['origin_country']
            ?? $scope['seller_country']
            ?? ''
        );
        if ($origin === '') {
            $origin = (string)(explode('|', $defaultJurisdiction, 2)[0] ?? 'CN') ?: 'CN';
        }

        $policy = $this->destinationPolicy->resolve($dest, $origin, $pricesIncludeTax, $collectCountries);
        $policy['prices_include_tax'] = $pricesIncludeTax;

        return $policy;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function newRollout(): CommerceRolloutGateInterface
    {
        return new TaxRolloutGate();
    }

    private static function newTestingRollout(): CommerceRolloutGateInterface
    {
        $gate = ObjectManager::create(CommerceRolloutGateInterface::class, [], false);
        if (!$gate instanceof CommerceRolloutGateInterface) {
            throw new \LogicException('CommerceRolloutGateInterface binding is unavailable');
        }

        return $gate;
    }
}
