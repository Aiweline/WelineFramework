<?php

declare(strict_types=1);

namespace WeShop\Invoice\Service;

class InvoiceAdminPageDataService
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {
    }

    public function getListData(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $sanitizedFilters = $this->sanitizeFilters($filters);
        $result = $this->invoiceService->getInvoices($page, $pageSize, $sanitizedFilters);

        return [
            'invoices' => $result['items'] ?? [],
            'summary' => $this->invoiceService->getInvoiceSummary(),
            'filters' => $sanitizedFilters,
            'pagination' => $result['pagination'] ?? [],
            'statusOptions' => $this->invoiceService->getStatusOptions(),
        ];
    }

    public function getDetailData(int $invoiceId): array
    {
        $invoice = $this->invoiceService->getInvoiceRecord($invoiceId);
        if ($invoice === null) {
            throw new \InvalidArgumentException('Invoice not found.');
        }

        return [
            'invoice' => $invoice,
            'statusOptions' => $this->invoiceService->getStatusOptions(),
            'canIssue' => ($invoice['status'] ?? '') !== InvoiceService::STATUS_ISSUED,
        ];
    }

    private function sanitizeFilters(array $filters): array
    {
        $sanitized = [];

        if (!empty($filters['invoice_number'])) {
            $sanitized['invoice_number'] = trim((string) ($filters['invoice_number'] ?? ''));
        }

        if (!empty($filters['order_increment_id'])) {
            $sanitized['order_increment_id'] = trim((string) ($filters['order_increment_id'] ?? ''));
        }

        if (!empty($filters['status']) && $this->invoiceService->isValidStatus((string) $filters['status'])) {
            $sanitized['status'] = (string) $filters['status'];
        }

        return $sanitized;
    }
}
