<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Api\Quote\DiscountQuote;
use Weline\Marketing\Api\Quote\DiscountQuoteRequest;
use Weline\Marketing\Api\Quote\DiscountQuoteServiceInterface;
use Weline\Marketing\Model\Rule\Rule;

final class DiscountQuoteService implements DiscountQuoteServiceInterface
{
    public function __construct(
        private readonly DiscountQuoteContextBuilder $contextBuilder,
        private readonly CouponService $coupons,
        private readonly RuleEngine $ruleEngine,
    ) {
    }

    public function activeConfigVersion(): string
    {
        return '1';
    }

    public function quote(DiscountQuoteRequest $request): DiscountQuote
    {
        $context = $this->contextBuilder->build($request);
        $precision = max(0, $request->currencyPrecision);
        $divisor = 10 ** $precision;

        $appliedRuleIds = [];
        $actionPayloads = [];
        $detailLines = [];
        $discountMajor = 0.0;
        $shippingDiscountMajor = 0.0;
        $freeShipping = false;
        $couponCode = strtoupper(trim((string)($request->couponCode ?? '')));

        foreach ($this->loadAutomaticRules() as $rule) {
            $result = $this->ruleEngine->applyRule($rule, $context);
            if ($result === null) {
                continue;
            }
            $appliedRuleIds[] = (int)$rule->getId();
            $discountMajor += (float)($result['discount_amount'] ?? 0);
            $shippingDiscountMajor += (float)($result['shipping_discount'] ?? 0);
            $freeShipping = $freeShipping || !empty($result['free_shipping']);
            $actionPayloads = array_merge($actionPayloads, $this->extractActionPayloads($rule));
            $detailLines[] = [
                'rule_id' => (int)$rule->getId(),
                'rule_name' => (string)$rule->getData(Rule::schema_fields_NAME),
                'source' => 'automatic',
                'discount_amount' => (float)($result['discount_amount'] ?? 0),
            ];
            if ($rule->getData(Rule::schema_fields_IS_STOP_PROCESSING)) {
                break;
            }
        }

        if ($couponCode !== '') {
            $validation = $this->coupons->validateCoupon($couponCode, $context);
            if ($validation !== null) {
                $rule = $validation['rule'] ?? null;
                if ($rule instanceof Rule && $rule->isActive()) {
                    $result = $this->ruleEngine->applyRule($rule, $context);
                    if ($result !== null) {
                        $appliedRuleIds[] = (int)$rule->getId();
                        $discountMajor += (float)($result['discount_amount'] ?? 0);
                        $shippingDiscountMajor += (float)($result['shipping_discount'] ?? 0);
                        $freeShipping = $freeShipping || !empty($result['free_shipping']);
                        $actionPayloads = array_merge($actionPayloads, $this->extractActionPayloads($rule));
                        $detailLines[] = [
                            'rule_id' => (int)$rule->getId(),
                            'rule_name' => (string)$rule->getData(Rule::schema_fields_NAME),
                            'source' => 'coupon',
                            'coupon_code' => $couponCode,
                            'discount_amount' => (float)($result['discount_amount'] ?? 0),
                        ];
                    }
                }
            }
        }

        $amountMinor = (int) round($discountMajor * $divisor);
        $shippingDiscountMinor = (int) round($shippingDiscountMajor * $divisor);
        $requestHash = $request->requestHash();
        $token = 'dqt_' . substr(hash('sha256', $requestHash . '|' . $amountMinor . '|' . $couponCode), 0, 24);

        return new DiscountQuote(
            discountQuoteToken: $token,
            amountMinor: max(0, $amountMinor),
            currency: strtoupper(trim($request->currency)),
            currencyPrecision: $precision,
            requestHash: $requestHash,
            lines: $detailLines,
            appliedRuleIds: array_values(array_unique($appliedRuleIds)),
            couponCode: $couponCode,
            actionPayloads: $actionPayloads,
            freeShipping: $freeShipping,
            shippingDiscountMinor: max(0, $shippingDiscountMinor),
        );
    }

    public function validateToken(DiscountQuoteRequest $request, DiscountQuote $quote): bool
    {
        if (!hash_equals($quote->requestHash, $request->requestHash())) {
            return false;
        }

        $expected = $this->quote($request);

        return hash_equals($expected->discountQuoteToken, $quote->discountQuoteToken)
            && $expected->amountMinor === $quote->amountMinor;
    }

    public function redeemCoupon(string $couponCode, array $context, DiscountQuote $quote): void
    {
        $code = strtoupper(trim($couponCode));
        if ($code === '' || $quote->couponCode === '' || !hash_equals($quote->couponCode, $code)) {
            return;
        }

        $context['order_id'] = $context['order_id'] ?? null;
        $this->coupons->useCoupon($code, $context);
    }

    /** @return list<Rule> */
    private function loadAutomaticRules(): array
    {
        /** @var Rule $model */
        $model = ObjectManager::getInstance(Rule::class);
        $model->where(Rule::schema_fields_RULE_TYPE, Rule::RULE_TYPE_AUTOMATIC)
            ->where(Rule::schema_fields_STATUS, Rule::STATUS_ACTIVE)
            ->order(Rule::schema_fields_PRIORITY, 'DESC');

        return $model->select()->fetch()->getItems();
    }

    /** @return list<array<string, mixed>> */
    private function extractActionPayloads(Rule $rule): array
    {
        $actions = $rule->getActions();
        if ($actions === []) {
            return [];
        }
        if (isset($actions['type'])) {
            $actions = [$actions];
        }

        $payloads = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = (string)($action['type'] ?? '');
            if ($type === '') {
                continue;
            }
            $payloads[] = ['type' => $type] + $action;
        }

        return $payloads;
    }
}
