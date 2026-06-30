<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\AvailabilityResult;
use Weline\Payment\Api\Data\PayableSnapshot;
use Weline\Payment\Model\PaymentMethod;

class PayablePaymentEligibilityService
{
    public const CONFIG_ALLOWED_PAYABLE_TYPES = 'allowed_payable_types';
    public const CONFIG_BLOCKED_PAYABLE_TYPES = 'blocked_payable_types';
    public const CONFIG_REQUIRE_AUTHENTICATED_ACTOR = 'require_authenticated_actor';
    public const CONFIG_ALLOWED_ACTOR_TYPES = 'allowed_actor_types';
    public const CONFIG_ALLOWED_ACTOR_IDS = 'allowed_actor_ids';
    public const CONFIG_SUPPORTED_CURRENCIES = 'supported_currencies';
    public const CONFIG_SUPPORTED_COUNTRIES = 'supported_countries';
    public const CONFIG_MIN_AMOUNT_MINOR = 'min_amount_minor';
    public const CONFIG_MAX_AMOUNT_MINOR = 'max_amount_minor';
    public const CONFIG_REQUIRED_BUSINESS_TAGS = 'required_business_tags';
    public const CONFIG_BLOCKED_BUSINESS_TAGS = 'blocked_business_tags';

    /**
     * @param array<string, mixed> $runtimeConfig
     * @param array<string, mixed> $capabilities
     */
    public function evaluate(
        PaymentMethod $paymentMethod,
        PayableSnapshot $snapshot,
        ?Actor $actor = null,
        array $runtimeConfig = [],
        array $capabilities = []
    ): AvailabilityResult {
        $config = array_replace($paymentMethod->getConfigData(), $runtimeConfig);
        $methodCode = (string) $paymentMethod->getData(PaymentMethod::schema_fields_CODE);

        $blockedPayableTypes = $this->normalizeList($config[self::CONFIG_BLOCKED_PAYABLE_TYPES] ?? []);
        if ($this->matchesAny($snapshot->getPayableType(), $blockedPayableTypes)) {
            return $this->unavailable('payable_type_blocked', __('该支付方式不允许当前可支付对象。'), $methodCode);
        }

        $allowedPayableTypes = $this->normalizeList($config[self::CONFIG_ALLOWED_PAYABLE_TYPES] ?? ['*']);
        if (!$this->matchesAny($snapshot->getPayableType(), $allowedPayableTypes, true)) {
            return $this->unavailable('payable_type_not_allowed', __('该支付方式未开放给当前可支付对象。'), $methodCode);
        }

        if (!empty($config[self::CONFIG_REQUIRE_AUTHENTICATED_ACTOR]) && !$this->hasActorIdentity($actor)) {
            return $this->unavailable('payer_authentication_required', __('该支付方式要求付款人先登录或完成身份识别。'), $methodCode);
        }

        $allowedActorTypes = $this->normalizeList($config[self::CONFIG_ALLOWED_ACTOR_TYPES] ?? ['*']);
        if (!$this->matchesAny($actor?->getActorType() ?? '', $allowedActorTypes, true)) {
            return $this->unavailable('payer_type_not_allowed', __('当前付款人类型不能使用该支付方式。'), $methodCode);
        }

        $allowedActorIds = $this->normalizeList($config[self::CONFIG_ALLOWED_ACTOR_IDS] ?? []);
        if ($allowedActorIds !== [] && !$this->matchesAny($actor?->getActorId() ?? '', $allowedActorIds)) {
            return $this->unavailable('payer_not_allowed', __('当前付款人不能使用该支付方式。'), $methodCode);
        }

        $amountMinor = $snapshot->getAmountMinor();
        $minAmountMinor = $this->nullableInt($config[self::CONFIG_MIN_AMOUNT_MINOR] ?? null);
        if ($minAmountMinor !== null && $amountMinor < $minAmountMinor) {
            return $this->unavailable('amount_below_minimum', __('当前金额低于该支付方式最低可支付金额。'), $methodCode);
        }

        $maxAmountMinor = $this->nullableInt($config[self::CONFIG_MAX_AMOUNT_MINOR] ?? null);
        if ($maxAmountMinor !== null && $amountMinor > $maxAmountMinor) {
            return $this->unavailable('amount_above_maximum', __('当前金额超过该支付方式最高可支付金额。'), $methodCode);
        }

        $supportedCurrencies = $this->normalizeList(
            $config[self::CONFIG_SUPPORTED_CURRENCIES]
                ?? $config['currencies']
                ?? $capabilities[self::CONFIG_SUPPORTED_CURRENCIES]
                ?? $capabilities['currencies']
                ?? [],
            true
        );
        if ($supportedCurrencies !== [] && !$this->matchesAny($snapshot->getCurrencyCode(), $supportedCurrencies)) {
            return $this->unavailable('currency_not_supported', __('该支付方式不支持当前支付货币。'), $methodCode);
        }

        $countryCode = strtoupper(trim($snapshot->getString(PayableSnapshot::FIELD_COUNTRY_CODE)));
        $supportedCountries = $this->normalizeList(
            $config[self::CONFIG_SUPPORTED_COUNTRIES]
                ?? $config['countries']
                ?? $capabilities[self::CONFIG_SUPPORTED_COUNTRIES]
                ?? $capabilities['countries']
                ?? [],
            true
        );
        if ($countryCode !== '' && $supportedCountries !== [] && !$this->matchesAny($countryCode, $supportedCountries)) {
            return $this->unavailable('country_not_supported', __('该支付方式不支持当前国家或地区。'), $methodCode);
        }

        $businessTags = $this->normalizeList($snapshot->getArray('business_tags'));
        $blockedTags = $this->normalizeList($config[self::CONFIG_BLOCKED_BUSINESS_TAGS] ?? []);
        if ($this->intersects($businessTags, $blockedTags)) {
            return $this->unavailable('business_tag_blocked', __('当前业务标签不能使用该支付方式。'), $methodCode);
        }

        $requiredTags = $this->normalizeList($config[self::CONFIG_REQUIRED_BUSINESS_TAGS] ?? []);
        if ($requiredTags !== [] && !$this->intersects($businessTags, $requiredTags)) {
            return $this->unavailable('business_tag_required', __('当前业务对象缺少该支付方式要求的业务标签。'), $methodCode);
        }

        return AvailabilityResult::fromArray([
            AvailabilityResult::FIELD_AVAILABLE => true,
            AvailabilityResult::FIELD_SORT_WEIGHT => (int) ($config['sort_weight'] ?? 0),
            AvailabilityResult::FIELD_REQUIRES_TERMS => !empty($config['requires_terms']),
            'method_code' => $methodCode,
        ]);
    }

    /**
     * @param array<string, mixed> $runtimeConfig
     * @param array<string, mixed> $capabilities
     */
    public function assertAvailable(
        PaymentMethod $paymentMethod,
        PayableSnapshot $snapshot,
        ?Actor $actor = null,
        array $runtimeConfig = [],
        array $capabilities = []
    ): void {
        $result = $this->evaluate($paymentMethod, $snapshot, $actor, $runtimeConfig, $capabilities);
        if (!$result->isAvailable()) {
            throw new \RuntimeException((string) ($result->getDisabledReasonText() ?: $result->getDisabledReasonCode() ?: 'payment_method_not_available'));
        }
    }

    private function hasActorIdentity(?Actor $actor): bool
    {
        return $actor !== null
            && trim($actor->getActorType()) !== ''
            && trim($actor->getActorId()) !== '';
    }

    /**
     * @return string[]
     */
    private function normalizeList(mixed $value, bool $uppercase = false): array
    {
        if (\is_string($value)) {
            $value = preg_split('/\s*,\s*/', trim($value)) ?: [];
        }

        if (!\is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $item = $item['value'] ?? $item['code'] ?? '';
            }
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            $items[] = $uppercase ? strtoupper($item) : strtolower($item);
        }

        return array_values(array_unique($items));
    }

    /**
     * @param string[] $needles
     */
    private function matchesAny(string $value, array $needles, bool $allowWildcard = false): bool
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return false;
        }
        if ($allowWildcard && \in_array('*', $needles, true)) {
            return true;
        }

        return \in_array($value, $needles, true) || \in_array(strtoupper($value), $needles, true);
    }

    /**
     * @param string[] $left
     * @param string[] $right
     */
    private function intersects(array $left, array $right): bool
    {
        if ($left === [] || $right === []) {
            return false;
        }

        return array_intersect($left, $right) !== [];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw new \InvalidArgumentException('payment_amount_limit_must_be_integer_minor_unit');
    }

    private function unavailable(string $code, string $text, string $methodCode): AvailabilityResult
    {
        return AvailabilityResult::fromArray([
            AvailabilityResult::FIELD_AVAILABLE => false,
            AvailabilityResult::FIELD_DISABLED_REASON_CODE => $code,
            AvailabilityResult::FIELD_DISABLED_REASON_TEXT => $text,
            'method_code' => $methodCode,
        ]);
    }
}
