<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\B2BOrder;
use WeShop\Order\Model\Order;
use Weline\Framework\Manager\ObjectManager;

class B2BOrderService
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly PaymentTermService $paymentTermService,
        private readonly ApprovalService $approvalService
    ) {
    }

    public function getByOrderId(int $orderId): ?B2BOrder
    {
        if ($orderId <= 0) {
            return null;
        }

        /** @var B2BOrder $row */
        $row = ObjectManager::getInstance(B2BOrder::class);
        $row->clear()
            ->where(B2BOrder::schema_fields_ORDER_ID, $orderId)
            ->limit(1);

        $rows = $row->select()->fetchArray();
        if (!\is_array($rows) || $rows === []) {
            return null;
        }

        $first = $rows[0];
        if (!\is_array($first)) {
            return null;
        }

        $id = (int) ($first[B2BOrder::schema_fields_ID] ?? $first['b2b_order_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $row->clear()->load($id);

        return $row->getId() ? $row : null;
    }

    /**
     * @return array{due_date: string, approval_status: string, payment_term_id: int|null}
     */
    public function resolveCheckoutContext(int $customerId, float $orderTotal, ?int $requestedTermId = null): array
    {
        $this->paymentTermService->ensureDefaultTerms();

        $account = $this->accountService->getOrCreateAccount($customerId);
        $termId = $requestedTermId ?: (int) ($account->getData(\WeShop\B2B\Model\Account::schema_fields_PAYMENT_TERM_ID) ?? 0);
        $term = $termId > 0 ? $this->paymentTermService->getTerm($termId) : null;

        $days = $this->paymentTermService->resolveTermDays(
            $term,
            (int) ($account->getData(\WeShop\B2B\Model\Account::schema_fields_CREDIT_PERIOD_DAYS) ?? 0)
        );

        $due = date('Y-m-d', strtotime('+' . max(0, $days) . ' days'));

        $autoLimit = (float) ($account->getData(\WeShop\B2B\Model\Account::schema_fields_AUTO_APPROVE_LIMIT) ?? 0);
        $approval = $this->approvalService->resolveInitialApprovalStatus($orderTotal, $autoLimit);
        if ($approval === ApprovalService::APPROVAL_PENDING) {
            throw new \InvalidArgumentException((string) __('This order amount exceeds your auto-approval limit. Please contact your account manager.'));
        }

        return [
            'due_date' => $due,
            'approval_status' => $approval,
            'payment_term_id' => $termId > 0 ? $termId : null,
        ];
    }

    public function createExtension(
        Order $order,
        int $customerId,
        float $creditAmount,
        string $dueDate,
        ?int $paymentTermId,
        string $approvalStatus
    ): B2BOrder {
        $orderId = (int) $order->getId();
        if ($orderId <= 0) {
            throw new \InvalidArgumentException((string) __('Invalid order for B2B extension.'));
        }

        $existing = $this->getByOrderId($orderId);
        if ($existing !== null) {
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        /** @var B2BOrder $b2b */
        $b2b = ObjectManager::getInstance(B2BOrder::class);
        $b2b->clearData()
            ->setData(B2BOrder::schema_fields_ORDER_ID, $orderId)
            ->setData(B2BOrder::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(B2BOrder::schema_fields_CREDIT_USED, round(max(0.0, $creditAmount), 2))
            ->setData(B2BOrder::schema_fields_PAYMENT_TERM_ID, $paymentTermId)
            ->setData(B2BOrder::schema_fields_DUE_DATE, $dueDate)
            ->setData(B2BOrder::schema_fields_INVOICE_STATUS, 'open')
            ->setData(B2BOrder::schema_fields_APPROVAL_STATUS, $approvalStatus)
            ->setData(B2BOrder::schema_fields_CREATED_AT, $now)
            ->setData(B2BOrder::schema_fields_UPDATED_AT, $now)
            ->save();

        return $b2b;
    }
}
