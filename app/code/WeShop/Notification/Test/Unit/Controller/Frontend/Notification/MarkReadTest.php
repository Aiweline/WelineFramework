<?php

declare(strict_types=1);

namespace WeShop\Notification\Test\Unit\Controller\Frontend\Notification;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Notification\Controller\Frontend\Notification\MarkRead;
use WeShop\Notification\Service\NotificationService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class MarkReadTest extends TestCase
{
    public function testIndexReturnsLoginRedirectPayloadForGuestRequest(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->never())->method('markAsRead');

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('https://example.com/customer/account/login');

        $controller = $this->getMockBuilder(MarkRead::class)
            ->setConstructorArgs([$customerContext, $notificationService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? true) === false
                    && (string) ($payload['message'] ?? '') === (string) __('请先登录后再继续。')
                    && ($payload['data']['redirect_url'] ?? null) === 'https://example.com/customer/account/login';
            }))
            ->willReturn('json');

        $this->assertSame('json', $controller->index());
    }

    public function testIndexMarksNotificationAndReturnsUnreadCount(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(7);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->once())
            ->method('markAsRead')
            ->with(11, 7)
            ->willReturn(true);
        $notificationService->expects($this->once())
            ->method('getUnreadCount')
            ->with(7)
            ->willReturn(2);

        $url = $this->createMock(Url::class);

        $request = $this->createMock(Request::class);
        $request->method('body')->willReturnMap([
            ['notification_id', null, 11],
            ['item_id', null, null],
        ]);
        $request->method('getPost')->willReturnMap([
            ['notification_id', null, null],
            ['item_id', null, null],
        ]);
        $request->method('getParam')->willReturnMap([
            ['notification_id', null, null],
            ['item_id', null, null],
        ]);

        $controller = $this->getMockBuilder(MarkRead::class)
            ->setConstructorArgs([$customerContext, $notificationService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (bool) ($payload['success'] ?? false)
                    && (int) ($payload['data']['notification_id'] ?? 0) === 11
                    && (int) ($payload['data']['unread_count'] ?? 999) === 2;
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
