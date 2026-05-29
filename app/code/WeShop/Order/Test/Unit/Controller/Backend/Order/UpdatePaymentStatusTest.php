<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Controller\Backend\Order;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Controller\Backend\Order\UpdatePaymentStatus;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;

class UpdatePaymentStatusTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(UpdatePaymentStatus::class));
    }

    public function testPostUpdatesPaymentStatusAndRejectsExternalBackUrl(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('isValidPaymentStatus')
            ->with(OrderService::PAYMENT_STATUS_PAID)
            ->willReturn(true);
        $orderService->expects($this->once())
            ->method('updatePaymentStatus')
            ->with(77, OrderService::PAYMENT_STATUS_PAID);

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'id' => 77,
                    'payment_status' => OrderService::PAYMENT_STATUS_PAID,
                    'back_url' => 'https://evil.example/phishing',
                    default => $default,
                };
            });

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addSuccess');

        $controller = $this->getMockBuilder(UpdatePaymentStatus::class)
            ->setConstructorArgs([$orderService])
            ->onlyMethods(['redirect', 'getMessageManager', 'getUrl'])
            ->getMock();
        $controller->expects($this->once())
            ->method('getUrl')
            ->with('*/backend/order')
            ->willReturn('/admin/order/index');
        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('/admin/order/index');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('', $controller->post());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}
