<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Controller\Frontend\Review;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Review\Controller\Frontend\Review\Create;
use WeShop\Review\Model\Review;
use WeShop\Review\Service\ReviewConfigService;
use WeShop\Review\Service\ReviewPurchaseEligibilityService;
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

        $reviewConfigService = $this->createMock(ReviewConfigService::class);
        $reviewConfigService->expects($this->once())
            ->method('getReviewMode')
            ->willReturn(ReviewConfigService::MODE_ORDER);
        $reviewConfigService->method('normalizeReviewMode')
            ->willReturnCallback(static fn(string $mode): string => $mode === ReviewConfigService::MODE_ANONYMOUS
                ? ReviewConfigService::MODE_ANONYMOUS
                : ReviewConfigService::MODE_ORDER);

        $purchaseEligibilityService = $this->createMock(ReviewPurchaseEligibilityService::class);
        $purchaseEligibilityService->expects($this->never())->method('customerCanReviewProduct');

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');

        $controller = $this->getMockBuilder(Create::class)
            ->setConstructorArgs([$customerContext, $reviewService, $url, $reviewConfigService, $purchaseEligibilityService])
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
            ->with($this->callback(static fn(array $payload): bool => (int) ($payload['product_id'] ?? 0) === 1001
                && (int) ($payload['customer_id'] ?? 0) === 33))
            ->willReturn($review);
        $reviewService->expects($this->once())
            ->method('buildClientReviewPayload')
            ->with($review)
            ->willReturn(['review_id' => 555, 'product_id' => 1001, 'pending' => true]);

        $url = $this->createMock(Url::class);
        $reviewConfigService = $this->createMock(ReviewConfigService::class);
        $reviewConfigService->expects($this->once())
            ->method('getReviewMode')
            ->willReturn(ReviewConfigService::MODE_ORDER);
        $reviewConfigService->method('normalizeReviewMode')
            ->willReturnCallback(static fn(string $mode): string => $mode === ReviewConfigService::MODE_ANONYMOUS
                ? ReviewConfigService::MODE_ANONYMOUS
                : ReviewConfigService::MODE_ORDER);

        $purchaseEligibilityService = $this->createMock(ReviewPurchaseEligibilityService::class);
        $purchaseEligibilityService->expects($this->once())
            ->method('customerCanReviewProduct')
            ->with(33, 1001)
            ->willReturn(true);

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
            ->setConstructorArgs([$customerContext, $reviewService, $url, $reviewConfigService, $purchaseEligibilityService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static fn(array $payload): bool => (bool) ($payload['success'] ?? false)))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('json', $controller->index());
    }

    public function testIndexCreatesAnonymousReviewForGuestWhenAnonymousModeEnabled(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $review = $this->createMock(Review::class);
        $review->expects($this->once())->method('getId')->willReturn(777);

        $reviewService = $this->createMock(ReviewService::class);
        $reviewService->expects($this->once())
            ->method('createReview')
            ->with($this->callback(static fn(array $payload): bool => (int) ($payload['product_id'] ?? 0) === 1002
                && (int) ($payload['customer_id'] ?? -1) === 0
                && (string) ($payload['content'] ?? '') === 'Anonymous review'))
            ->willReturn($review);
        $reviewService->expects($this->once())
            ->method('buildClientReviewPayload')
            ->with($review)
            ->willReturn(['review_id' => 777, 'product_id' => 1002, 'pending' => true]);

        $url = $this->createMock(Url::class);
        $url->expects($this->never())->method('getUrl');

        $reviewConfigService = $this->createMock(ReviewConfigService::class);
        $reviewConfigService->expects($this->once())
            ->method('getReviewMode')
            ->willReturn(ReviewConfigService::MODE_ANONYMOUS);
        $reviewConfigService->method('normalizeReviewMode')
            ->willReturnCallback(static fn(string $mode): string => $mode === ReviewConfigService::MODE_ANONYMOUS
                ? ReviewConfigService::MODE_ANONYMOUS
                : ReviewConfigService::MODE_ORDER);

        $purchaseEligibilityService = $this->createMock(ReviewPurchaseEligibilityService::class);
        $purchaseEligibilityService->expects($this->never())->method('customerCanReviewProduct');

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');
        $request->method('body')->willReturnMap([
            ['product_id', null, 1002],
            ['rating', null, 4],
            ['title', null, 'Guest'],
            ['content', null, 'Anonymous review'],
        ]);
        $request->method('getPost')->willReturn([]);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(Create::class)
            ->setConstructorArgs([$customerContext, $reviewService, $url, $reviewConfigService, $purchaseEligibilityService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static fn(array $payload): bool => (bool) ($payload['success'] ?? false)
                && ($payload['data']['review_mode'] ?? null) === ReviewConfigService::MODE_ANONYMOUS
                && (int) ($payload['data']['review_id'] ?? 0) === 777))
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
