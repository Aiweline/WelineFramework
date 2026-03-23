<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Controller\Frontend\Review;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Review\Controller\Frontend\Review\Create;
use WeShop\Review\Model\Review;
use WeShop\Review\Service\ReviewService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class CreateTest extends TestCase
{
    public function testIndexReturnsLoginRedirectPayloadForGuests(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $reviewService = $this->createMock(ReviewService::class);
        $reviewService->expects($this->never())->method('createReview');

        $url = $this->createMock(Url::class);
        $url->expects($this->once())->method('getUrl')->with('customer/account/login')->willReturn('/customer/account/login');

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');

        $controller = $this->getMockBuilder(Create::class)
            ->setConstructorArgs([$customerContext, $reviewService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static fn(array $payload): bool => ($payload['success'] ?? true) === false))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('json', $controller->index());
    }

    public function testIndexCreatesReviewAndReturnsPayload(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(33);

        $review = $this->createMock(Review::class);
        $review->expects($this->once())->method('getId')->willReturn(555);

        $reviewService = $this->createMock(ReviewService::class);
        $reviewService->expects($this->once())
            ->method('createReview')
            ->with($this->callback(static fn(array $payload): bool => (int) ($payload['product_id'] ?? 0) === 1001))
            ->willReturn($review);

        $url = $this->createMock(Url::class);

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');
        $request->method('body')->willReturnMap([
            ['product_id', null, 1001],
            ['rating', null, 5],
            ['title', null, 'Great'],
            ['content', null, 'Very good quality'],
        ]);
        $request->method('getPost')->willReturn([]);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(Create::class)
            ->setConstructorArgs([$customerContext, $reviewService, $url])
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
