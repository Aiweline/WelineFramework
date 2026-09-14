<?php

declare(strict_types=1);

namespace Weline\Marketing\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Marketing\Api\Quote\DiscountQuoteServiceInterface;
use Weline\Marketing\Service\MarketingCheckoutCouponSession;

final class MarketingQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly MarketingCheckoutCouponSession $couponSession,
        private readonly RuntimeProviderResolver $providers,
    ) {
    }

    public function getProviderName(): string
    {
        return 'marketing';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        try {
            $quotes = $this->discountQuotes();
            if ($quotes === null) {
                return ['success' => false, 'message' => (string)__('营销报价服务不可用')];
            }

            return match ($operation) {
                'validateCoupon' => $this->couponSession->validateCoupon($params, $quotes),
                'quoteDiscount' => $this->couponSession->quoteDiscount($params, $quotes),
                'applyCoupon' => $this->couponSession->applyCoupon(
                    (string)($params['coupon_code'] ?? $params['code'] ?? ''),
                    $quotes,
                    $params,
                ),
                'removeCoupon' => $this->couponSession->removeCoupon($params),
                'getCoupon' => $this->couponSession->getCoupon($params),
                default => throw new \InvalidArgumentException((string)__('营销接口不支持操作：%{1}', [$operation])),
            };
        } catch (\InvalidArgumentException $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        } catch (\Throwable) {
            return ['success' => false, 'message' => (string)__('营销服务暂时不可用，请稍后再试。')];
        }
    }

    public function getDescriptor(): array
    {
        $quoteParams = $this->quoteParams();

        return [
            'provider' => 'marketing',
            'name' => (string)__('万能优惠规则'),
            'description' => (string)__('优惠券校验、折扣报价与会话绑定。'),
            'module' => 'Weline_Marketing',
            'operations' => [
                [
                    'name' => 'validateCoupon',
                    'frontend' => true,
                    'mode' => 'read',
                    'params' => $quoteParams,
                ],
                [
                    'name' => 'quoteDiscount',
                    'frontend' => true,
                    'mode' => 'read',
                    'params' => $quoteParams,
                ],
                [
                    'name' => 'getCoupon',
                    'frontend' => true,
                    'mode' => 'read',
                    'params' => [
                        'cart_type' => ['type' => 'string', 'max_length' => 16],
                        'selling_mode' => ['type' => 'string', 'max_length' => 16],
                    ],
                ],
                [
                    'name' => 'applyCoupon',
                    'frontend' => true,
                    'mode' => 'write',
                    'params' => $this->couponCodeParams() + $this->quoteParams(),
                ],
                [
                    'name' => 'removeCoupon',
                    'frontend' => true,
                    'mode' => 'write',
                    'params' => [
                        'cart_type' => ['type' => 'string', 'max_length' => 16],
                        'selling_mode' => ['type' => 'string', 'max_length' => 16],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function couponCodeParams(): array
    {
        return [
            'coupon_code' => ['type' => 'string', 'max_length' => 64],
            'code' => ['type' => 'string', 'max_length' => 64],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function quoteParams(): array
    {
        return [
            'coupon_code' => ['type' => 'string', 'max_length' => 64],
            'code' => ['type' => 'string', 'max_length' => 64],
            'lines' => ['type' => 'array', 'max_items' => 100],
            'scope' => ['type' => 'array', 'max_items' => 7],
            'address' => ['type' => 'array', 'max_items' => 20],
            'orders' => ['type' => 'array', 'max_items' => 20],
            'currency' => ['type' => 'string', 'max_length' => 8],
            'currency_precision' => ['type' => 'int', 'min' => 0, 'max' => 4],
            'shipping_amount_minor' => ['type' => 'int', 'min' => 0],
            'payment_method' => ['type' => 'string', 'max_length' => 64],
            'cart_hash' => ['type' => 'string', 'max_length' => 128],
            'cart_type' => ['type' => 'string', 'max_length' => 16],
            'selling_mode' => ['type' => 'string', 'max_length' => 16],
            'guest_token' => ['type' => 'string', 'max_length' => 64],
        ];
    }

    private function discountQuotes(): ?DiscountQuoteServiceInterface
    {
        $resolved = $this->providers->resolve(DiscountQuoteServiceInterface::class);

        return $resolved instanceof DiscountQuoteServiceInterface ? $resolved : null;
    }
}
