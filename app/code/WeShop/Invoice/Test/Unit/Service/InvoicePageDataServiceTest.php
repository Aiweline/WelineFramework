<?php

declare(strict_types=1);

namespace WeShop\Invoice\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Invoice\Service\InvoicePageDataService;
use WeShop\Invoice\Service\InvoiceService;

class InvoicePageDataServiceTest extends TestCase
{
    public function testBuildMapsInvoicesAndSummaryData(): void
    {
        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->expects($this->once())
            ->method('getCustomerInvoices')
            ->with(9, 2, 10)
            ->willReturn([
                'items' => [
                    [
                        'invoice_id' => 1,
                        'order_id' => 88,
                        'order_increment_id' => 'W202603240001',
                        'invoice_number' => 'INV202603240001',
                        'amount' => '90.50',
                        'status' => 'issued',
                        'created_at' => '2026-03-24 10:00:00',
                    ],
                    [
                        'invoice_id' => 2,
                        'order_id' => 89,
                        'order_increment_id' => 'W202603240002',
                        'invoice_number' => 'INV202603240002',
                        'amount' => '40.00',
                        'status' => 'pending',
                        'created_at' => '2026-03-24 10:05:00',
                    ],
                ],
                'total' => 12,
                'pagination' => ['current' => 2],
            ]);
        $invoiceService->expects($this->once())
            ->method('getCustomerInvoiceSummary')
            ->with(9)
            ->willReturn([
                'total' => 12,
                'issued' => 7,
                'pending' => 5,
            ]);

        $service = new InvoicePageDataService($invoiceService);
        $result = $service->build(9, 2, 10);

        $this->assertSame(12, $result['invoice_count']);
        $this->assertSame(7, $result['invoice_issued_count']);
        $this->assertSame(5, $result['invoice_pending_count']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(2, $result['page_count']);
        $this->assertTrue($result['has_previous']);
        $this->assertFalse($result['has_next']);
        $this->assertSame('INV202603240001', $result['invoices'][0]['invoice_number']);
    }
}
