<?php

declare(strict_types=1);

namespace WeShop\Invoice\Test\Unit\Controller\Backend\Invoice;

use PHPUnit\Framework\TestCase;
use WeShop\Invoice\Controller\Backend\Invoice\Issue;
use WeShop\Invoice\Model\Invoice;
use WeShop\Invoice\Service\InvoiceService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;

class IssueTest extends TestCase
{
    public function testPostRejectsMissingInvoiceId(): void
    {
        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->expects($this->never())->method('issueInvoice');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->isType('string'));

        $controller = $this->getMockBuilder(Issue::class)
            ->setConstructorArgs([$invoiceService])
            ->onlyMethods(['redirect', 'getMessageManager', 'getUrl'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'back_url' => '*/backend/invoice',
                    'invoice_id' => 0,
                    default => $default,
                };
            });
        $this->setRequest($controller, $request);
        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('getUrl')
            ->with('*/backend/invoice')
            ->willReturn('*/backend/invoice');

        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/backend/invoice');

        $this->assertSame('', $controller->post());
    }

    public function testPostIssuesInvoiceAndRedirectsToDetailPage(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getId')->willReturn(19);

        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->expects($this->once())
            ->method('issueInvoice')
            ->with(19)
            ->willReturn($invoice);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with($this->isType('string'));

        $controller = $this->getMockBuilder(Issue::class)
            ->setConstructorArgs([$invoiceService])
            ->onlyMethods(['redirect', 'getMessageManager', 'getUrl'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'back_url' => '*/backend/invoice',
                    'invoice_id' => 19,
                    default => $default,
                };
            });
        $this->setRequest($controller, $request);
        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->exactly(2))
            ->method('getUrl')
            ->willReturnCallback(static function (string $path, array $params = []): string {
                return $path . ($params === [] ? '' : ('?' . http_build_query($params)));
            });
        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/backend/invoice/view?id=19');

        $this->assertSame('', $controller->post());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while ($reflection && !$reflection->hasProperty($property)) {
            $reflection = $reflection->getParentClass();
        }
        if (!$reflection) {
            self::fail(sprintf('Property %s not found on %s', $property, $target::class));
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }

    private function setRequest(object $target, Request $request): void
    {
        try {
            $this->setProtectedProperty($target, 'request', $request);
            return;
        } catch (\PHPUnit\Framework\AssertionFailedError) {
        }

        $this->setProtectedProperty($target, '_request', $request);
    }
}
