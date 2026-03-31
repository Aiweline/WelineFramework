<?php

declare(strict_types=1);

namespace WeShop\Invoice\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Invoice\Model\Invoice;
use WeShop\Invoice\Service\InvoiceService;

class InvoiceServiceTest extends TestCase
{
    public function testGetCustomerInvoiceCountReturnsSummaryTotal(): void
    {
        $service = new class extends InvoiceService {
            public function __construct()
            {
            }

            public function getCustomerInvoiceSummary(int $customerId): array
            {
                return ['total' => 7, 'pending' => 3, 'issued' => 4];
            }
        };

        $this->assertSame(7, $service->getCustomerInvoiceCount(12));
    }

    public function testIssueInvoiceThrowsWhenInvoiceDoesNotExist(): void
    {
        $service = new class extends InvoiceService {
            public function __construct()
            {
            }

            public function getInvoice(int $invoiceId): ?Invoice
            {
                return null;
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice not found.');
        $service->issueInvoice(99);
    }

    public function testIssueInvoiceMarksStatusIssuedAndPersists(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getData')
            ->willReturnCallback(static fn(string $key): mixed => match ($key) {
                Invoice::schema_fields_CREATED_AT => '',
                default => null,
            });
        $invoice->expects($this->exactly(2))
            ->method('setData')
            ->willReturnSelf();
        $invoice->expects($this->once())->method('save');

        $service = new class($invoice) extends InvoiceService {
            public function __construct(private readonly Invoice $invoice)
            {
            }

            public function getInvoice(int $invoiceId): ?Invoice
            {
                return $this->invoice;
            }
        };

        $this->assertSame($invoice, $service->issueInvoice(100));
    }
}
