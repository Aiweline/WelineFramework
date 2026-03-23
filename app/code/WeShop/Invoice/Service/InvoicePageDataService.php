<?php

declare(strict_types=1);

namespace WeShop\Invoice\Service;

class InvoicePageDataService
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId, int $page = 1, int $pageSize = 20): array
    {
        $result = $this->invoiceService->getCustomerInvoices($customerId, $page, $pageSize);
        $summary = $this->invoiceService->getCustomerInvoiceSummary($customerId);
        $items = $this->mapInvoices((array) ($result['items'] ?? []));
        $total = max(0, (int) ($summary['total'] ?? $result['total'] ?? 0));
        $pageCount = max(1, (int) ceil($total / max(1, $pageSize)));

        return [
            'invoices' => $items,
            'invoice_count' => $total,
            'invoice_pending_count' => (int) ($summary['pending'] ?? 0),
            'invoice_issued_count' => (int) ($summary['issued'] ?? 0),
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'has_previous' => $page > 1,
            'has_next' => $page < $pageCount,
            'pagination' => (array) ($result['pagination'] ?? []),
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapInvoices(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $mapped[] = [
                'invoice_id' => (int) ($item['invoice_id'] ?? 0),
                'order_id' => (int) ($item['order_id'] ?? 0),
                'order_increment_id' => (string) ($item['order_increment_id'] ?? ''),
                'invoice_number' => (string) ($item['invoice_number'] ?? ''),
                'amount' => (float) ($item['amount'] ?? 0),
                'status' => (string) ($item['status'] ?? 'pending'),
                'created_at' => (string) ($item['created_at'] ?? ''),
            ];
        }

        return $mapped;
    }
}
