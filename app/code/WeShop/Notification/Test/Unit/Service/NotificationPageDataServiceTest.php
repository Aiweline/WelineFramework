<?php

declare(strict_types=1);

namespace WeShop\Notification\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Notification\Service\NotificationPageDataService;
use WeShop\Notification\Service\NotificationService;

class NotificationPageDataServiceTest extends TestCase
{
    public function testBuildMapsNotificationItemsAndUnreadCount(): void
    {
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->once())
            ->method('getCustomerNotifications')
            ->with(7, 30)
            ->willReturn([
                [
                    'notification_id' => 11,
                    'type' => 'order',
                    'title' => 'Order shipped',
                    'content' => 'Your order has left the warehouse.',
                    'is_read' => 0,
                    'created_at' => '2026-03-24 10:00:00',
                ],
                [
                    'notification_id' => 12,
                    'type' => 'promotion',
                    'title' => 'Coupon available',
                    'content' => 'A new coupon is now active.',
                    'is_read' => 1,
                    'created_at' => '2026-03-24 09:00:00',
                ],
            ]);
        $notificationService->expects($this->once())
            ->method('getUnreadCount')
            ->with(7)
            ->willReturn(1);

        $service = new NotificationPageDataService($notificationService);
        $result = $service->build(7);

        $this->assertSame(2, $result['notification_count']);
        $this->assertSame(1, $result['notification_unread_count']);
        $this->assertSame('Order shipped', $result['notifications'][0]['title']);
        $this->assertSame('promotion', $result['notifications'][1]['type']);
    }
}
