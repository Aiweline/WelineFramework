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

class AddTest extends TestCase
{
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
