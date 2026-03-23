<?php

declare(strict_types=1);

namespace WeShop\Payment\Controller\Frontend\Payment;

use WeShop\Order\Service\OrderService;
use WeShop\Payment\Service\PaymentService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class Process extends FrontendController
{
    public function index(): string
    {
        try {
            $orderId = (int) ($this->request->getParam('order_id') ?? 0);
            $paymentMethod = (string) ($this->request->getParam('payment_method') ?? '');

            if ($orderId <= 0) {
                throw new \InvalidArgumentException((string) __('Order ID is required.'));
            }

            $order = $this->getOrderService()->getOrder($orderId);
            if ($order === null) {
                throw new \InvalidArgumentException((string) __('Order does not exist.'));
            }

            if ($paymentMethod === '') {
                throw new \InvalidArgumentException((string) __('Payment method is required.'));
            }

            return $this->fetchJson([
                'success' => true,
                'message' => __('Payment processing initialized.'),
                'data' => $this->getPaymentService()->processPayment($order, $paymentMethod, $this->readPaymentData()),
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function post(): string
    {
        return $this->index();
    }

    protected function getPaymentService(): PaymentService
    {
        return ObjectManager::getInstance(PaymentService::class);
    }

    protected function getOrderService(): OrderService
    {
        return ObjectManager::getInstance(OrderService::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function readPaymentData(): array
    {
        $payment = $this->request->getParam('payment') ?? [];

        return \is_array($payment) ? $payment : [];
    }
}
