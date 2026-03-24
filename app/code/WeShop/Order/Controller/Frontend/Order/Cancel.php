<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Manager\ObjectManager;

class Cancel extends BaseController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private ?CustomerContextInterface $customerContext = null,
        private ?OrderService $orderService = null
    ) {
    }

    public function postIndex(): string
    {
        $orderId = (int) ($this->request->getPost('order_id') ?? 0);
        if ($orderId <= 0) {
            $this->getMessageManager()->addError(__('Order ID is required.'));
            $this->redirect('weshop/order/list');
            return '';
        }

        $customerId = (int) ($this->getCustomerContext()->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(__('Please log in to continue.'));
            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        try {
            $checkResult = $this->getOrderService()->canCancelOrder($orderId, $customerId);

            if (empty($checkResult['can_cancel'])) {
                $this->getMessageManager()->addError($checkResult['reason'] ?? __('This order cannot be cancelled.'));

                if (!empty($checkResult['require_return'])) {
                    $this->getMessageManager()->addWarning(__('Please request a return for this order first.'));
                    $this->redirect('rma/create', ['order_id' => $orderId]);
                    return '';
                }

                $this->redirect('weshop/order/list');
                return '';
            }

            $this->getOrderService()->cancelOrder($orderId, $customerId);

            if (!empty($checkResult['require_refund'])) {
                $this->getMessageManager()->addSuccess(__('Order cancelled. Refund processing will follow your payment method rules.'));
            } else {
                $this->getMessageManager()->addSuccess(__('Order cancelled.'));
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        $this->redirect('weshop/order/list');
        return '';
    }

    private function getCustomerContext(): CustomerContextInterface
    {
        return $this->customerContext ??= ObjectManager::getInstance(CustomerContextInterface::class);
    }

    private function getOrderService(): OrderService
    {
        return $this->orderService ??= ObjectManager::getInstance(OrderService::class);
    }
}
