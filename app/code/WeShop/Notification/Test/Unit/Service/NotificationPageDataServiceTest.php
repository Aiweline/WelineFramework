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
                    'target_url' => '/product/view?id=958&review_id=743&reply_id=1#review-reply-1',
                    'is_read' => 0,
                    'created_at' => '2026-03-24 10:00:00',
                ],
                [
                    'notification_id' => 12,
                    'type' => 'review_reply',
                    'title' => 'Review reply mention',
                    'content' => 'A customer mentioned you in a review reply.',
                    'is_read' => 1,
                    'created_at' => '2026-03-24 09:00:00',
                ],
            ]);
        $notificationService->expects($this->once())
            ->method('getUnreadCount')
            ->with(7)
            ->willReturn(1);
        $notificationService->expects($this->once())
            ->method('getTypeOptions')
            ->willReturn([
                'order' => '订单',
                'review_reply' => '商品评价回复',
            ]);

        $service = new NotificationPageDataService($notificationService);
        $result = $service->build(7);

        $this->assertSame(2, $result['notification_count']);
        $this->assertSame(1, $result['notification_unread_count']);
        $this->assertSame('Order shipped', $result['notifications'][0]['title']);
        $this->assertSame('订单', $result['notifications'][0]['type_label']);
        $this->assertSame('/product/view?id=958&review_id=743&reply_id=1#review-reply-1', $result['notifications'][0]['target_url']);
        $this->assertSame('review_reply', $result['notifications'][1]['type']);
        $this->assertSame('商品评价回复', $result['notifications'][1]['type_label']);
    }
}
