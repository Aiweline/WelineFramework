<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\Controller\Backend\Affiliate;

use PHPUnit\Framework\TestCase;
use WeShop\Affiliate\Controller\Backend\Affiliate\Delete;
use WeShop\Affiliate\Model\Affiliate;
use WeShop\Affiliate\Service\AffiliateService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;

class DeleteTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Delete::class));
    }

    public function testControllerHasPostAndGetMethods(): void
    {
        $reflection = new \ReflectionClass(Delete::class);
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->hasMethod('get'));
    }

    public function testGetRejectsDeleteWithoutCallingAffiliateService(): void
    {
        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->never())
            ->method('getAffiliateRecord');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('删除分销记录必须使用 POST 请求。');

        $controller = $this->getMockBuilder(Delete::class)
            ->setConstructorArgs([$affiliateService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('/backend/affiliate');

        $this->setControllerRequest($controller, $this->createRequestMock());
        $this->setControllerUrl($controller);

        $this->assertSame('', $controller->get());
    }

    public function testGetRespectsProvidedBackUrl(): void
    {
        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->never())
            ->method('getAffiliateRecord');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError');

        $controller = $this->getMockBuilder(Delete::class)
            ->setConstructorArgs([$affiliateService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('/backend/custom-return');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'back_url' => '/backend/custom-return',
        ]));
        $this->setControllerUrl($controller);

        $this->assertSame('', $controller->get());
    }

    public function testPostStillDeletesValidAffiliate(): void
    {
        $affiliate = $this->getMockBuilder(Affiliate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['delete'])
            ->getMock();
        $affiliate->expects($this->once())
            ->method('delete');

        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->once())
            ->method('getAffiliateRecord')
            ->with(7)
            ->willReturn($affiliate);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with('Affiliate deleted successfully.');

        $controller = $this->getMockBuilder(Delete::class)
            ->setConstructorArgs([$affiliateService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('/backend/affiliate');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 7,
        ]));
        $this->setControllerUrl($controller);

        $this->assertSame('', $controller->post());
    }

    public function testPostRejectsMissingAffiliateIdBeforeServiceLookup(): void
    {
        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->never())
            ->method('getAffiliateRecord');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Invalid affiliate ID.');

        $controller = $this->getMockBuilder(Delete::class)
            ->setConstructorArgs([$affiliateService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('/backend/affiliate');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 0,
        ]));
        $this->setControllerUrl($controller);

        $this->assertSame('', $controller->post());
    }

    private function createRequestMock(array $params = []): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParam'])
            ->getMock();
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);

        return $request;
    }

    private function setControllerRequest(object $controller, Request $request): void
    {
        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('request') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate request property.');
        }

        $property = $reflection->getProperty('request');
        $property->setAccessible(true);
        $property->setValue($controller, $request);
    }

    private function setControllerUrl(object $controller): void
    {
        $url = $this->getMockBuilder(Url::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBackendUrl'])
            ->getMock();
        $url->method('getBackendUrl')
            ->with('*/backend/affiliate')
            ->willReturn('/backend/affiliate');

        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('_url') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate _url property.');
        }

        $property = $reflection->getProperty('_url');
        $property->setAccessible(true);
        $property->setValue($controller, $url);
    }
}
