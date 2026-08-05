<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Tax\Api\CheckoutTaxAdvisorInterface;
use Weline\Tax\Api\TaxEngineInterface;

/**
 * Checkout 侧税务顾问（P3B-002）：off/shadow 不改变成交金额；allowlist/on 服务端算税。
 * 引擎故障且无同版本 LKG → 阻断（绝不回零）。
 */
final class CheckoutTaxAdvisor implements CheckoutTaxAdvisorInterface
{
    public const CAPABILITY = 'tax';
    public const ERROR_BLOCKED = 'checkout_tax_blocked';
    public const ERROR_RULE_VERSION = 'checkout_tax_rule_version_conflict';

    private readonly CommerceRolloutGateInterface $rollout;
    private readonly TaxLkgStore $lkg;

    public function __construct(
        private readonly TaxEngine $engine = new TaxEngine(),
        ?CommerceRolloutGateInterface $rollout = null,
        ?TaxLkgStore $lkg = null,
    ) {
        $this->rollout = $rollout ?? self::newRollout();
        $this->lkg = $lkg ?? new TaxLkgStore();
    }

    public static function forTestingStub(): self
    {
        $gate = self::newTestingRollout();
        $gate->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);

        return new self(TaxEngine::forTesting(), $gate, TaxLkgStore::forTesting());
    }

    public static function forTestingActive(?TaxLkgStore $lkg = null): self
    {
        $lkg ??= TaxLkgStore::forTesting();
        $engine = TaxEngine::forTesting($lkg);
        $gate = self::newTestingRollout();
        $gate->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_ALLOWLIST, ['website:0']);

        return new self($engine, $gate, $lkg);
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
     * @return array<string, mixed>
     */
    public function quoteTax(
        array $orders,
        array $scope,
        array $address,
        string $currency,
    ): array {
        $websiteId = (int) ($scope['website_id'] ?? 0);
        $storeId = (int) ($scope['store_id'] ?? 0);
        $channelId = (int) ($scope['channel_id'] ?? 0);
        if (!$this->isEffectivelyOn($websiteId, $storeId, $channelId)) {
            $rolloutMode = $this->rollout->mode(self::CAPABILITY);
            return [
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
            ];
        }

        $request = $this->request($orders, $scope, $address, $currency);
        $jurisdiction = (string) $request['jurisdiction_key'];

        try {
            $result = $this->engine->calculate($request);
        } catch (TaxConflictException $e) {
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

        return [
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
        ];
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
        $country = strtoupper(trim((string) ($address['country'] ?? $address['country_code'] ?? 'CN')));
        $region = strtoupper(trim((string) ($address['region'] ?? $address['region_code'] ?? '')));

        return $country . '|' . $region;
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
