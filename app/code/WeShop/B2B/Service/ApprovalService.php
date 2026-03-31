<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\ApprovalLog;
use WeShop\B2B\Model\B2BOrder;
use Weline\Framework\Manager\ObjectManager;

class ApprovalService
{
    public const APPROVAL_NONE = 'none';
    public const APPROVAL_AUTO = 'auto';
    public const APPROVAL_PENDING = 'pending_admin';

    public function resolveInitialApprovalStatus(float $orderTotal, float $autoApproveLimit): string
    {
        if ($autoApproveLimit <= 0) {
            return self::APPROVAL_AUTO;
        }

        if ($orderTotal > $autoApproveLimit + 0.00001) {
            return self::APPROVAL_PENDING;
        }

        return self::APPROVAL_AUTO;
    }

    public function logDecision(int $orderId, string $action, ?int $approverId, ?string $comment = null): void
    {
        /** @var ApprovalLog $log */
        $log = ObjectManager::getInstance(ApprovalLog::class);
        $log->clearData()
            ->setData(ApprovalLog::schema_fields_ORDER_ID, $orderId)
            ->setData(ApprovalLog::schema_fields_APPROVER_ID, $approverId)
            ->setData(ApprovalLog::schema_fields_ACTION, $action)
            ->setData(ApprovalLog::schema_fields_COMMENT, $comment ?? '')
            ->setData(ApprovalLog::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    public function markOrderApproved(int $orderId, int $approverId): void
    {
        $b2b = $this->loadB2BOrder($orderId);
        if ($b2b === null) {
            return;
        }

        $b2b->setData(B2BOrder::schema_fields_APPROVAL_STATUS, self::APPROVAL_AUTO)
            ->setData(B2BOrder::schema_fields_APPROVER_ID, $approverId)
            ->setData(B2BOrder::schema_fields_APPROVED_AT, date('Y-m-d H:i:s'))
            ->setData(B2BOrder::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        $this->logDecision($orderId, 'approve', $approverId, null);
    }

    public function markOrderRejected(int $orderId, int $approverId, string $comment): void
    {
        $b2b = $this->loadB2BOrder($orderId);
        if ($b2b === null) {
            return;
        }

        $b2b->setData(B2BOrder::schema_fields_APPROVAL_STATUS, 'rejected')
            ->setData(B2BOrder::schema_fields_APPROVER_ID, $approverId)
            ->setData(B2BOrder::schema_fields_APPROVED_AT, date('Y-m-d H:i:s'))
            ->setData(B2BOrder::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        $this->logDecision($orderId, 'reject', $approverId, $comment);
    }

    private function loadB2BOrder(int $orderId): ?B2BOrder
    {
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
        $id = (int) ($first[B2BOrder::schema_fields_ID] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $row->clear()->load($id);

        return $row->getId() ? $row : null;
    }
}
