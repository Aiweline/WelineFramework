<?php

declare(strict_types=1);

namespace WeShop\Invoice\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Invoice\Model\Invoice;
use WeShop\Order\Model\Order;

/**
 * 发票服务
 */
class InvoiceService
{
    /**
     * 创建发票
     * 
     * @param array $invoiceData 发票数据
     * @return Invoice
     */
    public function createInvoice(array $invoiceData): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = ObjectManager::getInstance(Invoice::class);
        
        // 生成发票号
        $invoiceNumber = $this->generateInvoiceNumber();
        
        $invoice->clearData()
            ->setData('order_id', $invoiceData['order_id'] ?? 0)
            ->setData('invoice_number', $invoiceNumber)
            ->setData('amount', $invoiceData['amount'] ?? 0)
            ->setData('status', 'pending')
            ->save();
        
        return $invoice;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, pagination: array<string, mixed>}
     */
    public function getCustomerInvoices(int $customerId, int $page = 1, int $pageSize = 20): array
    {
        $orderMap = $this->getCustomerOrderMap($customerId);
        if (!$orderMap) {
            return [
                'items' => [],
                'total' => 0,
                'pagination' => [],
            ];
        }

        $orderIds = array_keys($orderMap);

        /** @var Invoice $invoice */
        $invoice = ObjectManager::getInstance(Invoice::class);
        $invoice->clear()
            ->where(Invoice::schema_fields_ORDER_ID, $orderIds, 'IN')
            ->order(Invoice::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        $rows = $invoice->select()->fetchArray();
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $orderId = (int) ($row[Invoice::schema_fields_ORDER_ID] ?? 0);
            $orderData = $orderMap[$orderId] ?? [];

            $items[] = [
                'invoice_id' => (int) ($row[Invoice::schema_fields_ID] ?? 0),
                'order_id' => $orderId,
                'order_increment_id' => (string) ($orderData[Order::schema_fields_increment_id] ?? ''),
                'invoice_number' => (string) ($row[Invoice::schema_fields_INVOICE_NUMBER] ?? ''),
                'amount' => (float) ($row[Invoice::schema_fields_AMOUNT] ?? 0),
                'status' => (string) ($row[Invoice::schema_fields_STATUS] ?? 'pending'),
                'created_at' => (string) ($row[Invoice::schema_fields_CREATED_AT] ?? ''),
            ];
        }

        return [
            'items' => $items,
            'total' => (int) $invoice->getTotalCount(),
            'pagination' => (array) ($invoice->getPagination() ?? []),
        ];
    }

    public function getCustomerInvoiceCount(int $customerId): int
    {
        return (int) ($this->getCustomerInvoiceSummary($customerId)['total'] ?? 0);
    }

    /**
     * @return array{total:int,pending:int,issued:int}
     */
    public function getCustomerInvoiceSummary(int $customerId): array
    {
        $orderMap = $this->getCustomerOrderMap($customerId);
        if (!$orderMap) {
            return [
                'total' => 0,
                'pending' => 0,
                'issued' => 0,
            ];
        }

        /** @var Invoice $invoice */
        $invoice = ObjectManager::getInstance(Invoice::class);
        $rows = $invoice->clear()
            ->where(Invoice::schema_fields_ORDER_ID, array_keys($orderMap), 'IN')
            ->select([Invoice::schema_fields_STATUS])
            ->fetchArray();

        $summary = [
            'total' => 0,
            'pending' => 0,
            'issued' => 0,
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            ++$summary['total'];
            $status = (string) ($row[Invoice::schema_fields_STATUS] ?? 'pending');
            if ($status === 'issued') {
                ++$summary['issued'];
                continue;
            }

            ++$summary['pending'];
        }

        return $summary;
    }
    
    /**
     * 生成发票号
     * 
     * @return string
     */
    protected function generateInvoiceNumber(): string
    {
        return 'INV' . date('YmdHis') . rand(1000, 9999);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getCustomerOrderMap(int $customerId): array
    {
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $rows = $order->clear()
            ->where(Order::schema_fields_customer_id, $customerId)
            ->select([
                Order::schema_fields_ID,
                Order::schema_fields_increment_id,
            ])->fetchArray();

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderId = (int) ($row[Order::schema_fields_ID] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $map[$orderId] = $row;
        }

        return $map;
    }
}
