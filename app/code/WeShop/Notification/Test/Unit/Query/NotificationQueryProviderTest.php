<?php

declare(strict_types=1);

namespace WeShop\Notification\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Notification\Extends\Module\Weline_Framework\Query\NotificationQueryProvider;
use WeShop\Notification\Service\NotificationService;
use Weline\Framework\Http\Url;

final class NotificationQueryProviderTest extends TestCase
{
    public function testMarkReadReturnsLoginRedirectPayloadForGuest(): void
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
            ->willReturn('/customer/account/login');

        $provider = new NotificationQueryProvider($customerContext, $notificationService, $url);
        $result = $provider->execute('markRead', ['notification_id' => 11]);

        $this->assertFalse($result['success']);
        $this->assertSame((string) __('请先登录后再继续。'), $result['message']);
        $this->assertSame('/customer/account/login', $result['data']['redirect_url']);
    }

    public function testMarkReadReturnsUnreadCountForCurrentCustomer(): void
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

        $provider = new NotificationQueryProvider(
            $customerContext,
            $notificationService,
            $this->createMock(Url::class)
        );
        $result = $provider->execute('markRead', ['notification_id' => 11]);

        $this->assertTrue($result['success']);
        $this->assertSame((string) __('通知已标记为已读。'), $result['message']);
        $this->assertSame(11, $result['data']['notification_id']);
        $this->assertSame(2, $result['data']['unread_count']);
    }
}
