<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Manager\ObjectManager;

class Cancel extends BaseController
{
    public function __construct(
        private ?CustomerContextInterface $customerContext = null,
        private ?OrderService $orderService = null
    ) {
    }

    public function postIndex(): string
    {
        $orderId = (int) ($this->request->getPost('order_id') ?? 0);
        if ($orderId <= 0) {
            $this->getMessageManager()->addError(__('缺少订单 ID。'));
            $this->redirect('weshop/order/list');
            return '';
        }

        $customerId = (int) ($this->getCustomerContext()->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(__('请先登录。'));
            $this->redirect($this->getStorefrontLoginRoute());
            return '';
        }

        try {
            $checkResult = $this->getOrderService()->canCancelOrder($orderId, $customerId);

            if (empty($checkResult['can_cancel'])) {
                $this->getMessageManager()->addError($checkResult['reason'] ?? __('该订单无法取消。'));

                if (!empty($checkResult['require_return'])) {
                    $this->getMessageManager()->addWarning(__('请先为该订单提交退换货申请。'));
                    $this->redirect('rma/create', ['order_id' => $orderId]);
                    return '';
                }

                $this->redirect('weshop/order/list');
                return '';
            }

            $this->getOrderService()->cancelOrder($orderId, $customerId);

            if (!empty($checkResult['require_refund'])) {
                $this->getMessageManager()->addSuccess(__('订单已取消。退款将按您的支付方式规则处理。'));
            } else {
                $this->getMessageManager()->addSuccess(__('订单已取消。'));
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
