<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\Receivable;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Apply offline/B2B payments to open receivables.
 */
class B2BReceivablePaymentService
{
    public function __construct(
        private readonly CreditService $creditService,
        private readonly AccountService $accountService,
        private readonly ?OrderService $orderService = null
    ) {
    }

    public function applyPayment(int $receivableId, float $amount, ?string $note = null): Receivable
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException((string) __('Payment amount must be positive.'));
        }

        /** @var Receivable $rec */
        $rec = ObjectManager::getInstance(Receivable::class);
        $rec->load($receivableId);
        if (!$rec->getId()) {
            throw new \InvalidArgumentException((string) __('Receivable not found.'));
        }

        $total = (float) ($rec->getData(Receivable::schema_fields_AMOUNT) ?? 0);
        $paid = round((float) ($rec->getData(Receivable::schema_fields_PAID_AMOUNT) ?? 0) + $amount, 2);
        $customerId = (int) ($rec->getData(Receivable::schema_fields_CUSTOMER_ID) ?? 0);

        $status = ReceivableService::STATUS_PARTIAL;
        if ($paid + 0.00001 >= $total) {
            $paid = $total;
            $status = ReceivableService::STATUS_PAID;
        }

        $rec->setData(Receivable::schema_fields_PAID_AMOUNT, $paid)
            ->setData(Receivable::schema_fields_STATUS, $status)
            ->setData(Receivable::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        $this->accountService->adjustBalance($customerId, round($amount, 2));

        if ($status === ReceivableService::STATUS_PAID) {
            $this->creditService->releaseCredit($customerId, $total);
            $orderId = (int) ($rec->getData(Receivable::schema_fields_ORDER_ID) ?? 0);
            $orderService = $this->orderService ?? ObjectManager::getInstance(OrderService::class);
            if ($orderId > 0) {
                $orderService->updatePaymentStatus($orderId, OrderService::PAYMENT_STATUS_PAID);
            }
        }

        if ($note !== null && $note !== '') {
            // Reserved for payment audit trail extension
        }

        return $rec;
    }
}
