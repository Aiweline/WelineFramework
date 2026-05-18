<?php

declare(strict_types=1);

namespace WeShop\Promotion\Controller\Frontend\Coupon;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Promotion\Service\CartCouponSessionService;

class Apply extends BaseController
{
    public function __construct(
        private readonly CartCouponSessionService $cartCouponSessionService,
        private readonly CustomerContextInterface $customerContext
    ) {
    }

    public function index(): string
    {
        return $this->post();
    }

    public function post(): string
    {
        $code = trim((string) (
            $this->request->body('code')
            ?? $this->request->getPost('code')
            ?? $this->request->getParam('code')
            ?? ''
        ));

        if ($code === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Coupon code is required.'),
            ]);
        }

        $orderTotal = (float) (
            $this->request->body('order_total')
            ?? $this->request->getPost('order_total')
            ?? $this->request->getParam('order_total')
            ?? 0
        );

        $customerId = (int) ($this->customerContext->getUserId() ?? 0);

        try {
            $result = $this->cartCouponSessionService->applyCoupon($code, $customerId, $orderTotal);
        } catch (\Throwable $exception) {
            return $this->fetchJson([
                'success' => false,
                'message' => $exception->getMessage() ?: (string) __('Coupon is not available.'),
            ]);
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('Coupon applied successfully.'),
            'data' => [
                'discount' => (float) ($result['discount'] ?? 0),
                'coupon_code' => (string) ($result['coupon_code'] ?? $code),
            ],
        ]);
    }
}
