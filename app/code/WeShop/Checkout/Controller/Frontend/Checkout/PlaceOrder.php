<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Checkout\Service\CheckoutService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 下单控制器
 */
class PlaceOrder extends FrontendController
{
    /**
     * 下单
     */
    public function index(): string
    {
        try {
            /** @var CustomerSession $customerSession */
            $customerSession = ObjectManager::getInstance(CustomerSession::class);
            $customer = $customerSession->getCustomer();
            
            if (!$customer || !$customer->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('请先登录')]);
            }
            
            $orderData = [
                'customer_id' => $customer->getId(),
                'shipping_address_id' => (int)($this->request->getParam('shipping_address_id') ?? 0),
                'billing_address_id' => (int)($this->request->getParam('billing_address_id') ?? 0),
                'shipping_method' => $this->request->getParam('shipping_method') ?? '',
                'payment_method' => $this->request->getParam('payment_method') ?? '',
                'total' => (float)($this->request->getParam('total') ?? 0),
            ];
            
            /** @var CheckoutService $checkoutService */
            $checkoutService = ObjectManager::getInstance(CheckoutService::class);
            $order = $checkoutService->placeOrder($orderData);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('订单创建成功'),
                'data' => [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
