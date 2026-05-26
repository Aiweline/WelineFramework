<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Controller\Backend\Payment;

use PHPUnit\Framework\TestCase;
use WeShop\Payment\Controller\Backend\Payment\Save;
use WeShop\Payment\Service\PaymentManagementService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;

class SaveTest extends TestCase
{
    public function testPostSavesPaymentSettingsAndRedirectsBackToManagementPage(): void
    {
        $service = $this->createMock(PaymentManagementService::class);
        $service->expects($this->once())
            ->method('save')
            ->with([
                'default_method' => 'paypal',
                'methods' => [
                    'paypal' => ['enabled' => '1'],
                ],
                'test_method' => '',
                'scope_type' => 'global',
                'scope_code' => 'default',
                'environment' => 'sandbox',
            ])
            ->willReturn(['default_method' => 'paypal']);

        $request = $this->createMock(Request::class);
        $request->expects($this->once())->method('isPost')->willReturn(true);
        $request->expects($this->exactly(10))
            ->method('getPost')
            ->willReturnMap([
                ['default_method', '', 'paypal'],
                ['methods', [], ['paypal' => ['enabled' => '1']]],
                ['test_method', '', ''],
                ['scope_type', 'global', 'global'],
                ['scope_code', 'default', 'default'],
                ['environment', 'sandbox', 'sandbox'],
                ['tab', 'credentials', 'credentials'],
            ]);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with($this->callback(static function (mixed $message): bool {
                return in_array((string) $message, ['Payment settings saved successfully.', '支付设置已保存。'], true);
            }));

        $controller = $this->getMockBuilder(Save::class)
            ->setConstructorArgs([$service])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->atLeastOnce())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/backend/payment?tab=credentials&scope_type=global&scope_code=default&environment=sandbox');

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
