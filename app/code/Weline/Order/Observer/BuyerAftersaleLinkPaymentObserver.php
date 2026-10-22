<?php

declare(strict_types=1);

namespace Weline\Order\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\BuyerAftersaleRequest;
use Weline\Order\Model\OrderItem;
use Weline\Order\Service\BuyerAftersaleConfig;
use Weline\Order\Service\OrderRefundCoordinator;

/**
 * 只处理买家售后支付联动事件。
 * 再次核对两闸与方案后，才调用订单退款协调。失败写回 error_code，不抛出。
 */
class BuyerAftersaleLinkPaymentObserver implements ObserverInterface
{
    public function __construct(
        private readonly ?BuyerAftersaleConfig $config = null,
        private readonly ?OrderRefundCoordinator $coordinator = null,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }
        if (!$this->gatesOpen()) {
            return;
        }
        if (!$this->resolutionAllowsLink($data)) {
            return;
        }
        $this->requestCoordinatorRefund($data);
    }

    /**
     * 可替换的协调入口。闸关闭或方案不符时不会进入。
     *
     * @param array<string, mixed> $data
     */
    protected function requestCoordinatorRefund(array $data): void
    {
        try {
            $orderUuid = (string)($data['order_uuid'] ?? '');
            $lines = $this->orderLines($orderUuid);
            if ($lines === []) {
                $this->rememberPaymentError($data, 'buyer_aftersale_items_required');
                return;
            }
            $result = $this->coordinator()->requestRefund(
                $orderUuid,
                'buyer-aftersale:' . (string)($data['request_uuid'] ?? ''),
                0,
                $lines,
                0,
                (string)($data['reason_code'] ?? ''),
            );
            if (!\is_array($result) || !($result['ok'] ?? false)) {
                $code = \is_array($result) ? trim((string)($result['error_code'] ?? '')) : '';
                $this->rememberPaymentError(
                    $data,
                    $code !== '' ? $code : 'buyer_aftersale_payment_link_failed',
                );
            }
        } catch (\Throwable $e) {
            $this->rememberPaymentError($data, 'buyer_aftersale_payment_link_failed');
            w_log_error('BuyerAftersaleLinkPaymentObserver: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolutionAllowsLink(array $data): bool
    {
        $resolution = (string)($data['resolution'] ?? '');
        $status = (string)($data['status'] ?? '');
        if ($resolution === BuyerAftersaleRequest::RESOLUTION_RETURN
            && $status === BuyerAftersaleRequest::STATUS_RETURN_RECEIVED
        ) {
            return true;
        }

        return $resolution === BuyerAftersaleRequest::RESOLUTION_REFUND_ONLY
            && $status === BuyerAftersaleRequest::STATUS_APPROVED;
    }

    private function gatesOpen(): bool
    {
        try {
            return $this->config()->isPaymentLinkActive();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function rememberPaymentError(array $data, string $errorCode): void
    {
        $requestUuid = trim((string)($data['request_uuid'] ?? ''));
        if ($requestUuid === '' || $errorCode === '') {
            return;
        }
        try {
            /** @var BuyerAftersaleRequest $row */
            $row = ObjectManager::getInstance(BuyerAftersaleRequest::class, [], false);
            $row->clear()
                ->where(BuyerAftersaleRequest::schema_fields_REQUEST_UUID, $requestUuid)
                ->find()
                ->fetch();
            if (!$row->getId()) {
                return;
            }
            $row->setData(BuyerAftersaleRequest::schema_fields_PAYMENT_ERROR_CODE, $errorCode);
            $row->setData(BuyerAftersaleRequest::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
            $row->save();
        } catch (\Throwable $e) {
            w_log_error('BuyerAftersaleLinkPaymentObserver persist error: ' . $e->getMessage());
        }
    }

    /**
     * @return list<array{item_uuid:string,qty_minor:int}>
     */
    private function orderLines(string $orderUuid): array
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return [];
        }
        try {
            /** @var OrderItem $item */
            $item = ObjectManager::getInstance(OrderItem::class, [], false);
            $rows = $item->clear()
                ->where(OrderItem::schema_fields_ORDER_UUID, $orderUuid)
                ->select()
                ->fetchArray();
        } catch (\Throwable $e) {
            w_log_error('BuyerAftersaleLinkPaymentObserver lines: ' . $e->getMessage());
            return [];
        }
        if (!\is_array($rows)) {
            return [];
        }
        $lines = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $itemUuid = trim((string)($row[OrderItem::schema_fields_ITEM_UUID] ?? ''));
            $qty = (int)($row[OrderItem::schema_fields_QTY_MINOR] ?? 0);
            if ($itemUuid === '' || $qty <= 0) {
                continue;
            }
            $lines[] = [
                'item_uuid' => $itemUuid,
                'qty_minor' => $qty,
            ];
        }

        return $lines;
    }

    private function config(): BuyerAftersaleConfig
    {
        return $this->config ?? new BuyerAftersaleConfig();
    }

    private function coordinator(): OrderRefundCoordinator
    {
        if ($this->coordinator instanceof OrderRefundCoordinator) {
            return $this->coordinator;
        }

        /** @var OrderRefundCoordinator $coordinator */
        $coordinator = ObjectManager::getInstance(OrderRefundCoordinator::class);

        return $coordinator;
    }
}
