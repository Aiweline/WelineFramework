<?php

declare(strict_types=1);

namespace WeShop\Compare\Test\Unit\Controller\Frontend\Compare;

use PHPUnit\Framework\TestCase;
use WeShop\Compare\Controller\Frontend\Compare\Add;
use WeShop\Compare\Model\Compare;
use WeShop\Compare\Service\CompareService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;

class AddTest extends TestCase
{
    public function testIndexRejectsNonPostMethod(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->never())->method('getUserId');

        $compareService = $this->createMock(CompareService::class);
        $compareService->expects($this->never())->method('addToCompare');
        $url = $this->createMock(Url::class);

        $request = $this->createMock(Request::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('isAjax')->willReturn(false);

        $controller = $this->getMockBuilder(Add::class)
            ->setConstructorArgs([$customerContext, $compareService, $url])
            ->onlyMethods(['fetchJson', 'redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? true) === false
                    && preg_match('/Invalid request method|无效的请求方法/u', (string) ($payload['message'] ?? '')) === 1;
            }))
            ->willReturn('json');
        $controller->expects($this->never())->method('redirect');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    public function testIndexReturnsLoginRedirectPayloadForGuestAjaxRequest(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $compareService = $this->createMock(CompareService::class);
        $compareService->expects($this->never())->method('addToCompare');
        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('https://example.com/customer/account/login');

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');

        $controller = $this->getMockBuilder(Add::class)
            ->setConstructorArgs([$customerContext, $compareService, $url])
            ->onlyMethods(['fetchJson', 'redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? true) === false
                    && ($payload['data']['redirect_url'] ?? null) === 'https://example.com/customer/account/login';
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    public function testIndexReturnsJsonSuccessForAjaxAdd(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(7);

        $compareItem = $this->getMockBuilder(Compare::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $compareItem->method('getId')->willReturn(11);

        $compareService = $this->createMock(CompareService::class);
        $compareService->expects($this->once())
            ->method('addToCompare')
            ->with(7, 501)
            ->willReturn($compareItem);
        $compareService->expects($this->once())
            ->method('getCompareCount')
            ->with(7)
            ->willReturn(1);
        $url = $this->createMock(Url::class);

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');
        $request->method('body')->willReturnMap([
            ['product_id', null, 501],
        ]);
        $request->method('getPost')->willReturnMap([
            ['product_id', null, null],
        ]);
        $request->method('getParam')->willReturnMap([
            ['product_id', null, null],
        ]);

        $controller = $this->getMockBuilder(Add::class)
            ->setConstructorArgs([$customerContext, $compareService, $url])
            ->onlyMethods(['fetchJson', 'redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (bool) ($payload['success'] ?? false)
                    && (int) ($payload['data']['compare_count'] ?? 0) === 1;
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    public function testIndexRedirectsGuestNonAjaxPostRequest(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(0);

        $compareService = $this->createMock(CompareService::class);
        $compareService->expects($this->never())->method('addToCompare');

        $url = $this->createMock(Url::class);
        $url->expects($this->never())->method('getUrl');

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(false);
        $request->method('getMethod')->willReturn('POST');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->callback(static fn ($msg): bool => (string) $msg !== ''))
            ->willReturnSelf();

        $controller = $this->getMockBuilder(Add::class)
            ->setConstructorArgs([$customerContext, $compareService, $url])
            ->onlyMethods(['fetchJson', 'redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->never())->method('fetchJson');
        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('customer/account/login');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('', $controller->index());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection) {
            throw new \RuntimeException("Property {$property} not found.");
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setValue($target, $value);
    }
}
