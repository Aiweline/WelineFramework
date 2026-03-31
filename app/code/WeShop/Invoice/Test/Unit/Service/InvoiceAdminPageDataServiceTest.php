<?php

declare(strict_types=1);

namespace WeShop\Invoice\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Invoice\Service\InvoiceAdminPageDataService;
use WeShop\Invoice\Service\InvoiceService;

class InvoiceAdminPageDataServiceTest extends TestCase
{
    public function testGetListDataSanitizesFiltersAndReturnsComposedPayload(): void
    {
        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->expects($this->once())
            ->method('isValidStatus')
            ->with('invalid')
            ->willReturn(false);
        $invoiceService->expects($this->once())
            ->method('getInvoices')
            ->with(2, 15, [
                'invoice_number' => 'INV-001',
                'order_increment_id' => 'WS000001',
            ])
            ->willReturn([
                'items' => [['invoice_id' => 1]],
                'pagination' => ['current' => 2],
            ]);
        $invoiceService->expects($this->once())
            ->method('getInvoiceSummary')
            ->willReturn(['total' => 1, 'pending' => 1, 'issued' => 0]);
        $invoiceService->expects($this->once())
            ->method('getStatusOptions')
            ->willReturn(['pending' => 'Pending', 'issued' => 'Issued']);

        $service = new InvoiceAdminPageDataService($invoiceService);
        $result = $service->getListData(2, 15, [
            'invoice_number' => ' INV-001 ',
            'order_increment_id' => ' WS000001 ',
            'status' => 'invalid',
        ]);

        $this->assertSame([['invoice_id' => 1]], $result['invoices']);
        $this->assertSame(['current' => 2], $result['pagination']);
        $this->assertSame(['total' => 1, 'pending' => 1, 'issued' => 0], $result['summary']);
        $this->assertSame([
            'invoice_number' => 'INV-001',
            'order_increment_id' => 'WS000001',
        ], $result['filters']);
    }

    public function testGetDetailDataThrowsWhenInvoiceNotFound(): void
    {
        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->expects($this->once())
            ->method('getInvoiceRecord')
            ->with(77)
            ->willReturn(null);

        $service = new InvoiceAdminPageDataService($invoiceService);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice not found.');
        $service->getDetailData(77);
    }
}
