<?php

declare(strict_types=1);

namespace WeShop\Notification\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Notification\Model\Notification;
use WeShop\Notification\Service\NotificationAdminPageDataService;
use WeShop\Notification\Service\NotificationService;

class NotificationAdminPageDataServiceTest extends TestCase
{
    public function testGetListDataSanitizesFiltersAndNormalizesItems(): void
    {
        $notificationService = new class extends NotificationService {
            public array $receivedFilters = [];

            public function getAdminNotifications(int $page = 1, int $pageSize = 20, array $filters = []): array
            {
                $this->receivedFilters = $filters;

                return [
                    'items' => [[
                        Notification::schema_fields_ID => 15,
                        Notification::schema_fields_CUSTOMER_ID => 9,
                        Notification::schema_fields_TYPE => 'order',
                        Notification::schema_fields_TITLE => 'Order #WS100015 shipped',
                        Notification::schema_fields_CONTENT => 'Carrier picked up package.',
                        Notification::schema_fields_IS_READ => 0,
                        Notification::schema_fields_CREATED_AT => '2026-03-24 12:00:00',
                    ]],
                    'pagination' => ['current_page' => $page, 'page_size' => $pageSize],
                ];
            }

            public function getAdminSummary(array $filters = []): array
            {
                return [
                    'total_count' => 1,
                    'unread_count' => 1,
                    'read_count' => 0,
                ];
            }

            public function getTypeOptions(): array
            {
                return [
                    'info' => 'Info',
                    'order' => 'Order',
                ];
            }
        };

        $service = new NotificationAdminPageDataService($notificationService);
        $result = $service->getListData(2, 50, [
            'customer_id' => '9',
            'type' => ' order ',
            'is_read' => '0',
            'title' => ' shipped ',
        ]);

        $this->assertSame([
            'customer_id' => 9,
            'type' => 'order',
            'is_read' => 0,
            'title' => 'shipped',
        ], $notificationService->receivedFilters);
        $this->assertSame('Order #WS100015 shipped', $result['notifications'][0]['title']);
        $this->assertSame('Unread', $result['notifications'][0]['read_label']);
        $this->assertSame('warning', $result['notifications'][0]['read_badge_class']);
        $this->assertSame(['0' => 'Unread', '1' => 'Read'], $result['readOptions']);
        $this->assertSame(['current_page' => 2, 'page_size' => 50], $result['pagination']);
    }

    public function testGetDetailDataThrowsWhenNotificationDoesNotExist(): void
    {
        $notificationService = new class extends NotificationService {
            public function getNotificationById(int $notificationId): ?Notification
            {
                return null;
            }
        };

        $service = new NotificationAdminPageDataService($notificationService);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Notification not found.');

        $service->getDetailData(999);
    }

    public function testGetDetailDataBuildsTypeLabelAndReadLabel(): void
    {
        $notification = $this->createMock(Notification::class);
        $notification->method('getId')->willReturn(27);
        $notification->method('getData')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                Notification::schema_fields_CUSTOMER_ID => 16,
                Notification::schema_fields_TYPE => 'membership',
                Notification::schema_fields_TITLE => 'Tier upgraded',
                Notification::schema_fields_CONTENT => 'Your level is now Gold.',
                Notification::schema_fields_IS_READ => 1,
                Notification::schema_fields_CREATED_AT => '2026-03-24 13:20:00',
                default => null,
            }
        );

        $notificationService = new class($notification) extends NotificationService {
            public function __construct(private readonly Notification $notification)
            {
            }

            public function getNotificationById(int $notificationId): ?Notification
            {
                return $notificationId === 27 ? $this->notification : null;
            }

            public function getTypeOptions(): array
            {
                return ['membership' => 'Membership'];
            }
        };

        $service = new NotificationAdminPageDataService($notificationService);
        $result = $service->getDetailData(27);

        $this->assertSame(27, $result['notification']['notification_id']);
        $this->assertSame('membership', $result['notification']['type']);
        $this->assertSame('Membership', $result['notification']['type_label']);
        $this->assertSame('Read', $result['notification']['read_label']);
    }
}
