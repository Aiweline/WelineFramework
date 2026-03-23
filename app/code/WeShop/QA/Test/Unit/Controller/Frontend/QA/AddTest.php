<?php

declare(strict_types=1);

namespace WeShop\QA\Test\Unit\Controller\Frontend\QA;

use PHPUnit\Framework\TestCase;
use WeShop\QA\Controller\Frontend\QA\Add;
use WeShop\QA\Service\QAService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class AddTest extends TestCase
{
    public function testIndexReturnsLoginRedirectPayloadWhenGuest(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $qaService = $this->createMock(QAService::class);
        $qaService->expects($this->never())->method('createQuestion');

        $url = $this->createMock(Url::class);
        $url->expects($this->once())->method('getUrl')->with('customer/account/login')->willReturn('https://example/login');

        $controller = $this->getMockBuilder(Add::class)
            ->setConstructorArgs([$customerContext, $qaService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static fn(array $payload): bool => ($payload['success'] ?? true) === false))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $this->createMock(Request::class));

        $this->assertSame('json', $controller->index());
    }

    public function testIndexReturnsSuccessForAjaxAdd(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(42);

        $qaService = $this->createMock(QAService::class);
        $qaService->expects($this->once())
            ->method('createQuestion')
            ->with($this->callback(static fn(array $payload): bool => $payload['customer_id'] === 42));

        $url = $this->createMock(Url::class);

        $request = $this->createMock(Request::class);
        $request->method('body')->willReturnMap([['product_id', null, 501], ['question', null, 'Test?']]);
        $request->method('getPost')->willReturn([]);

        $controller = $this->getMockBuilder(Add::class)
            ->setConstructorArgs([$customerContext, $qaService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static fn(array $payload): bool => (bool) ($payload['success'] ?? false)))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }
}
