<?php

declare(strict_types=1);

namespace WeShop\Promotion\Test\Unit\Controller\Frontend\Coupon;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Promotion\Controller\Frontend\Coupon\Apply;
use WeShop\Promotion\Service\CouponService;
use Weline\Framework\Http\Request;

class ApplyTest extends TestCase
{
    public function testPostReturnsErrorWhenCouponCodeIsMissing(): void
    {
        $couponService = $this->createMock(CouponService::class);
        $couponService->expects($this->never())->method('applyCoupon');

        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->never())->method('getUserId');

        $request = $this->createMock(Request::class);
        $request->method('body')->willReturnMap([
            ['code', null, ''],
            ['order_total', null, null],
        ]);
        $request->method('getPost')->willReturnMap([
            ['code', null, null],
            ['order_total', null, null],
        ]);
        $request->method('getParam')->willReturnMap([
            ['code', null, null],
            ['order_total', null, null],
        ]);

        $controller = $this->getMockBuilder(Apply::class)
            ->setConstructorArgs([$couponService, $customerContext])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? true) === false
                    && ($payload['message'] ?? '') === 'Coupon code is required.';
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->post());
    }

    public function testPostUsesCustomerContextWhenApplyingCoupon(): void
    {
        $couponService = $this->createMock(CouponService::class);
        $couponService->expects($this->once())
            ->method('applyCoupon')
            ->with('SPRING25', 42, 199.5)
            ->willReturn([
                'discount' => 25.0,
            ]);

        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(42);

        $request = $this->createMock(Request::class);
        $request->method('body')->willReturnMap([
            ['code', null, 'SPRING25'],
            ['order_total', null, '199.5'],
        ]);
        $request->method('getPost')->willReturnMap([
            ['code', null, null],
            ['order_total', null, null],
        ]);
        $request->method('getParam')->willReturnMap([
            ['code', null, null],
            ['order_total', null, null],
        ]);

        $controller = $this->getMockBuilder(Apply::class)
            ->setConstructorArgs([$couponService, $customerContext])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (bool) ($payload['success'] ?? false)
                    && (float) ($payload['data']['discount'] ?? 0.0) === 25.0
                    && ($payload['data']['coupon_code'] ?? '') === 'SPRING25';
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->post());
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
