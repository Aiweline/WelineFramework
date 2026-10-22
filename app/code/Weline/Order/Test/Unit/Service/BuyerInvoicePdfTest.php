<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\BuyerInvoiceDocument;
use Weline\Order\Service\BuyerInvoiceDownloadService;
use Weline\Order\Service\BuyerInvoiceException;
use Weline\Order\Service\BuyerInvoicePdfRenderer;
use Weline\Order\Service\BuyerInvoiceSettings;

final class BuyerInvoicePdfTest extends TestCase
{
    public function testDownloadStaysOffUntilEnabledAndCompanyNameIsFilled(): void
    {
        self::assertFalse(BuyerInvoiceSettings::offered('0', '长安汉服'));
        self::assertFalse(BuyerInvoiceSettings::offered('1', '  '));
        self::assertTrue(BuyerInvoiceSettings::offered('1', '长安汉服'));
        self::assertSame('CNY 12.50', BuyerInvoiceDocument::money(1250, 'cny'));
    }

    public function testPlainInvoiceNamesTheSellerAndInvoiceNumber(): void
    {
        $document = new BuyerInvoiceDocument(
            invoiceNumber: 'INV-ABC',
            issuedAt: '2026-10-22 10:00:00',
            orderNumber: 'SO-1',
            currency: 'CNY',
            sellerName: '长安汉服',
            sellerTaxId: 'TAX-1',
            sellerAddress: '西安',
            sellerPhone: '',
            sellerEmail: '',
            buyerName: '张三',
            buyerContact: 'buyer@example.com',
            buyerAddress: '',
            lines: [[
                'name' => '齐胸襦裙',
                'qty' => '1',
                'price' => 'CNY 12.50',
                'total' => 'CNY 12.50',
            ]],
            taxLabel: 'CNY 0.00',
            totalLabel: 'CNY 12.50',
        );
        $text = implode("\n", $document->plainLines());
        self::assertStringContainsString('INV-ABC', $text);
        self::assertStringContainsString('长安汉服', $text);
        self::assertStringContainsString('齐胸襦裙', $text);
    }

    public function testAnotherCustomerCannotDownload(): void
    {
        $service = new BuyerInvoiceDownloadService();
        $this->expectException(BuyerInvoiceException::class);
        $this->expectExceptionMessage('You can only download your own invoice');
        $service->assertCustomerOwns(7, 8);
    }

    public function testPdfWrapsAJpegPage(): void
    {
        $image = imagecreatetruecolor(8, 8);
        self::assertNotFalse($image);
        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        imagejpeg($image, $stream, 90);
        imagedestroy($image);
        rewind($stream);
        $jpeg = stream_get_contents($stream);
        fclose($stream);
        self::assertIsString($jpeg);

        $pdf = BuyerInvoicePdfRenderer::compose([$jpeg]);
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('/DCTDecode', $pdf);
        self::assertStringEndsWith("%%EOF", $pdf);
    }

    public function testMissingFontRefusesToDraw(): void
    {
        $renderer = new BuyerInvoicePdfRenderer([]);
        $document = new BuyerInvoiceDocument(
            invoiceNumber: 'INV-1',
            issuedAt: '2026-10-22',
            orderNumber: 'SO-1',
            currency: 'CNY',
            sellerName: '长安汉服',
            sellerTaxId: '',
            sellerAddress: '',
            sellerPhone: '',
            sellerEmail: '',
            buyerName: '张三',
            buyerContact: '',
            buyerAddress: '',
            lines: [],
            taxLabel: 'CNY 0.00',
            totalLabel: 'CNY 1.00',
        );
        $this->expectException(BuyerInvoiceException::class);
        $this->expectExceptionMessage('The server has no Chinese font for invoices');
        $renderer->render($document);
    }
}
