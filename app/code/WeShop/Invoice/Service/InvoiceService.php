<?php

declare(strict_types=1);

namespace WeShop\Invoice\Service;

use WeShop\Invoice\Model\Invoice;
use WeShop\Order\Model\Order;
use Weline\Framework\Manager\ObjectManager;

class InvoiceService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';

    public function __construct(
        private readonly ?Invoice $invoiceModel = null,
        private readonly ?Order $orderModel = null
    ) {
    }

    public function createInvoice(array $invoiceData): Invoice
    {
        $invoice = $this->newInvoiceModel();
        $invoice->clearData()
            ->setData(Invoice::schema_fields_ORDER_ID, (int) ($invoiceData['order_id'] ?? 0))
            ->setData(Invoice::schema_fields_INVOICE_NUMBER, (string) ($invoiceData['invoice_number'] ?? $this->generateInvoiceNumber()))
            ->setData(Invoice::schema_fields_AMOUNT, (float) ($invoiceData['amount'] ?? 0))
            ->setData(Invoice::schema_fields_STATUS, (string) ($invoiceData['status'] ?? self::STATUS_PENDING))
            ->setData(Invoice::schema_fields_CREATED_AT, (string) ($invoiceData['created_at'] ?? date('Y-m-d H:i:s')))
            ->save();

        return $invoice;
    }

    public function getInvoice(int $invoiceId): ?Invoice
    {
        $invoice = $this->newInvoiceModel();
        $invoice->load($invoiceId);

        return $invoice->getId() ? $invoice : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getInvoiceRecord(int $invoiceId): ?array
    {
        $invoice = $this->getInvoice($invoiceId);
        if (!$invoice) {
            return null;
        }

        $orderId = (int) ($invoice->getData(Invoice::schema_fields_ORDER_ID) ?? 0);
        $orderMap = $orderId > 0 ? $this->getOrderMap([], [$orderId]) : [];

        return $this->buildInvoiceRow([
            Invoice::schema_fields_ID => (int) $invoice->getId(),
            Invoice::schema_fields_ORDER_ID => $orderId,
            Invoice::schema_fields_INVOICE_NUMBER => (string) ($invoice->getData(Invoice::schema_fields_INVOICE_NUMBER) ?? ''),
            Invoice::schema_fields_AMOUNT => (float) ($invoice->getData(Invoice::schema_fields_AMOUNT) ?? 0),
            Invoice::schema_fields_STATUS => (string) ($invoice->getData(Invoice::schema_fields_STATUS) ?? self::STATUS_PENDING),
            Invoice::schema_fields_CREATED_AT => (string) ($invoice->getData(Invoice::schema_fields_CREATED_AT) ?? ''),
        ], $orderMap);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, pagination: array<string, mixed>}
     */
    public function getCustomerInvoices(int $customerId, int $page = 1, int $pageSize = 20): array
    {
        $orderMap = $this->getOrderMap([
            'customer_id' => $customerId,
        ]);
        if ($orderMap === []) {
            return [
                'items' => [],
                'total' => 0,
                'pagination' => [],
            ];
        }

        $invoice = $this->newInvoiceModel();
        $invoice->clear()
            ->where(Invoice::schema_fields_ORDER_ID, array_keys($orderMap), 'IN')
            ->order(Invoice::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        $rows = $invoice->select()->fetchArray();

        return [
            'items' => $this->mapInvoiceRows($rows, $orderMap),
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
        $orderMap = $this->getOrderMap([
            'customer_id' => $customerId,
        ]);
        if ($orderMap === []) {
            return [
                'total' => 0,
                'pending' => 0,
                'issued' => 0,
            ];
        }

        $invoice = $this->newInvoiceModel();
        $rows = $invoice->clear()
            ->where(Invoice::schema_fields_ORDER_ID, array_keys($orderMap), 'IN')
            ->select(Invoice::schema_fields_STATUS)
            ->fetchArray();

        return $this->collectSummary($rows);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, pagination: array<string, mixed>}
     */
    public function getInvoices(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $invoice = $this->newInvoiceModel();
        $invoice->clear();

        if (!empty($filters['status']) && $this->isValidStatus((string) $filters['status'])) {
            $invoice->where(Invoice::schema_fields_STATUS, (string) $filters['status']);
        }

        if (!empty($filters['invoice_number'])) {
            $invoice->where(Invoice::schema_fields_INVOICE_NUMBER, '%' . trim((string) ($filters['invoice_number'] ?? '')) . '%', 'LIKE');
        }

        $orderMap = $this->resolveAdminOrderMap($filters);
        if ($orderMap !== null) {
            if ($orderMap === []) {
                return [
                    'items' => [],
                    'total' => 0,
                    'pagination' => [],
                ];
            }

            $invoice->where(Invoice::schema_fields_ORDER_ID, array_keys($orderMap), 'IN');
        }

        $invoice->order(Invoice::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        $rows = $invoice->select()->fetchArray();
        if ($orderMap === null) {
            $orderIds = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $orderIds[] = (int) ($row[Invoice::schema_fields_ORDER_ID] ?? 0);
                }
            }
            $orderMap = $this->getOrderMap([], $orderIds);
        }

        return [
            'items' => $this->mapInvoiceRows($rows, $orderMap),
            'total' => (int) $invoice->getTotalCount(),
            'pagination' => (array) ($invoice->getPagination() ?? []),
        ];
    }

    /**
     * @return array{total:int,pending:int,issued:int}
     */
    public function getInvoiceSummary(): array
    {
        $invoice = $this->newInvoiceModel();
        $rows = $invoice->clear()
            ->select(Invoice::schema_fields_STATUS)
            ->fetchArray();

        return $this->collectSummary($rows);
    }

    public function issueInvoice(int $invoiceId): Invoice
    {
        $invoice = $this->getInvoice($invoiceId);
        if (!$invoice) {
            throw new \InvalidArgumentException((string) __('Invoice not found.'));
        }

        $invoice->setData(Invoice::schema_fields_STATUS, self::STATUS_ISSUED);
        if (!$invoice->getData(Invoice::schema_fields_CREATED_AT)) {
            $invoice->setData(Invoice::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
        $invoice->save();

        return $invoice;
    }

    public function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDING => (string) __('Pending'),
            self::STATUS_ISSUED => (string) __('Issued'),
        ];
    }

    public function isValidStatus(string $status): bool
    {
        return isset($this->getStatusOptions()[$status]);
    }

    protected function newInvoiceModel(): Invoice
    {
        return $this->invoiceModel ? clone $this->invoiceModel : ObjectManager::getInstance(Invoice::class);
    }

    protected function newOrderModel(): Order
    {
        return $this->orderModel ? clone $this->orderModel : ObjectManager::getInstance(Order::class);
    }

    protected function generateInvoiceNumber(): string
    {
        return 'INV' . date('YmdHis') . rand(1000, 9999);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>|null
     */
    protected function resolveAdminOrderMap(array $filters): ?array
    {
        if (!empty($filters['order_increment_id'])) {
            return $this->getOrderMap([
                'increment_id' => trim((string) ($filters['order_increment_id'] ?? '')),
            ]);
        }

        if (!empty($filters['order_id'])) {
            return $this->getOrderMap([], [(int) $filters['order_id']]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, int>|null $orderIds
     * @return array<int, array<string, mixed>>
     */
    protected function getOrderMap(array $filters = [], ?array $orderIds = null): array
    {
        $order = $this->newOrderModel();
        $order->clear();

        if (!empty($filters['customer_id'])) {
            $order->where(Order::schema_fields_customer_id, (int) $filters['customer_id']);
        }

        if (!empty($filters['increment_id'])) {
            $order->where(Order::schema_fields_increment_id, '%' . trim((string) ($filters['increment_id'] ?? '')) . '%', 'LIKE');
        }

        if ($orderIds !== null) {
            $orderIds = array_values(array_filter(array_unique(array_map('intval', $orderIds))));
            if ($orderIds === []) {
                return [];
            }
            $order->where(Order::schema_fields_ID, $orderIds, 'IN');
        }

        $rows = $order->select(Order::schema_fields_ID . ',' . Order::schema_fields_increment_id)
            ->fetchArray();

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

    /**
     * @param array<int, mixed> $rows
     * @param array<int, array<string, mixed>> $orderMap
     * @return array<int, array<string, mixed>>
     */
    protected function mapInvoiceRows(array $rows, array $orderMap): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = $this->buildInvoiceRow($row, $orderMap);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $orderMap
     * @return array<string, mixed>
     */
    protected function buildInvoiceRow(array $row, array $orderMap): array
    {
        $orderId = (int) ($row[Invoice::schema_fields_ORDER_ID] ?? $row['order_id'] ?? 0);
        $status = (string) ($row[Invoice::schema_fields_STATUS] ?? $row['status'] ?? self::STATUS_PENDING);
        $orderData = $orderMap[$orderId] ?? [];

        return [
            'invoice_id' => (int) ($row[Invoice::schema_fields_ID] ?? $row['invoice_id'] ?? 0),
            'order_id' => $orderId,
            'order_increment_id' => (string) ($row['order_increment_id'] ?? $orderData[Order::schema_fields_increment_id] ?? ''),
            'invoice_number' => (string) ($row[Invoice::schema_fields_INVOICE_NUMBER] ?? $row['invoice_number'] ?? ''),
            'amount' => (float) ($row[Invoice::schema_fields_AMOUNT] ?? $row['amount'] ?? 0),
            'status' => $status,
            'status_label' => $this->getStatusOptions()[$status] ?? ucfirst($status),
            'created_at' => (string) ($row[Invoice::schema_fields_CREATED_AT] ?? $row['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<int, mixed> $rows
     * @return array{total:int,pending:int,issued:int}
     */
    protected function collectSummary(array $rows): array
    {
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
            $status = (string) ($row[Invoice::schema_fields_STATUS] ?? self::STATUS_PENDING);
            if ($status === self::STATUS_ISSUED) {
                ++$summary['issued'];
                continue;
            }

            ++$summary['pending'];
        }

        return $summary;
    }
}
