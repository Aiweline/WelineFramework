<?php

declare(strict_types=1);

namespace WeShop\B2B\Payment\Provider;

use WeShop\B2B\Service\ApprovalService;
use WeShop\B2B\Service\B2BOrderService;
use WeShop\B2B\Service\CreditService;
use WeShop\B2B\Service\ReceivableService;
use WeShop\Order\Model\Order;
use WeShop\Payment\Interface\PaymentProviderInterface;
use WeShop\Payment\Provider\ProviderContextHelperTrait;
use Weline\Framework\Manager\ObjectManager;

class CreditAccount implements PaymentProviderInterface
{
    use ProviderContextHelperTrait;

    public function processPayment(Order $order, array $paymentData = [], array $context = []): array
    {
        $customerId = (int) ($order->getData(Order::schema_fields_customer_id) ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Invalid customer for B2B credit payment.'));
        }

        $amount = (float) $this->readOrderAmount($order, $paymentData);
        $termId = (int) ($paymentData['b2b_payment_term_id'] ?? 0);

        $creditService = ObjectManager::getInstance(CreditService::class);
        $b2bOrderService = ObjectManager::getInstance(B2BOrderService::class);
        $receivableService = ObjectManager::getInstance(ReceivableService::class);

        $ctx = $b2bOrderService->resolveCheckoutContext($customerId, $amount, $termId > 0 ? $termId : null);

        try {
            $creditService->reserveCredit($customerId, $amount);
            $b2bOrderService->createExtension(
                $order,
                $customerId,
                $amount,
                $ctx['due_date'],
                $ctx['payment_term_id'],
                ApprovalService::APPROVAL_AUTO
            );
            $receivableService->createFromCreditOrder(
                $order,
                $customerId,
                $amount,
                $ctx['due_date']
            );
        } catch (\Throwable $exception) {
            $creditService->releaseCredit($customerId, $amount);
            throw $exception;
        }

        return [
            'status' => 'pending',
            'requires_action' => false,
            'redirect_url' => '',
            'instructions' => (string) __('This order is placed on your B2B credit account. Please settle the invoice before the due date.'),
            'order_number' => $this->readOrderNumber($order),
            'amount' => $amount,
            'currency' => $this->resolveCurrency($context, $paymentData),
            'due_date' => $ctx['due_date'],
        ];
    }

    public function handleCallback(array $callbackData, array $context = []): bool
    {
        return true;
    }

    public function queryPaymentStatus(string $orderNumber, array $context = []): string
    {
        return 'pending';
    }
}
