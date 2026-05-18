<?php
declare(strict_types=1);

namespace WeShop\Promotion\Extends\Module\Weline_Framework\Query;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Promotion\Service\CartCouponSessionService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class PromotionQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly CartCouponSessionService $cartCouponSessionService,
        private readonly CustomerContextInterface $customerContext
    ) {
    }

    public function getProviderName(): string
    {
        return 'promotion';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'applyCoupon' => $this->applyCoupon($params),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported promotion provider operation: %{1}', $operation)
            ),
        };
    }

    private function applyCoupon(array $params): array
    {
        $code = trim((string)($params['code'] ?? ''));
        if ($code === '') {
            return [
                'success' => false,
                'message' => (string)__('Coupon code is required.'),
            ];
        }

        $customerId = (int)($this->customerContext->getUserId() ?? 0);
        $orderTotal = (float)($params['order_total'] ?? 0);

        try {
            $result = $this->cartCouponSessionService->applyCoupon($code, $customerId, $orderTotal);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage() ?: (string)__('Coupon is not available.'),
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('Coupon applied successfully.'),
            'data' => [
                'discount' => (float)($result['discount'] ?? 0),
                'coupon_code' => (string)($result['coupon_code'] ?? $code),
            ],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'promotion',
            'name' => __('Promotion Query'),
            'description' => __('Provides frontend promotion operations through the worker API.'),
            'module' => 'WeShop_Promotion',
            'operations' => [
                [
                    'name' => 'applyCoupon',
                    'description' => __('Frontend coupon apply operation.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'code' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'order_total' => ['type' => 'float', 'required' => false, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Apply cart coupon',
                ],
            ],
        ];
    }
}
