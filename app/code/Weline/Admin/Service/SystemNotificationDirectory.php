<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Admin\Api\Notification\SystemNotificationDirectoryInterface;
use Weline\Admin\Api\Notification\SystemNotificationRecord;
use Weline\Admin\Model\System\SystemNotification;

final class SystemNotificationDirectory implements SystemNotificationDirectoryInterface
{
    public function __construct(
        private readonly SystemNotification $notificationModel,
    ) {
    }

    public function listUnread(): array
    {
        $rows = (clone $this->notificationModel)->reset()
            ->where(SystemNotification::schema_fields_is_read, false)
            ->select()
            ->fetchArray();
        $records = [];
        foreach ($rows as $row) {
            $records[] = new SystemNotificationRecord(
                (int)($row[SystemNotification::schema_fields_ID] ?? 0),
                (string)($row[SystemNotification::schema_fields_title] ?? ''),
                (string)($row[SystemNotification::schema_fields_content] ?? ''),
                (bool)($row[SystemNotification::schema_fields_is_read] ?? false),
                (int)($row[SystemNotification::schema_fields_is_img] ?? 0),
                (int)($row[SystemNotification::schema_fields_is_icon] ?? 0),
                (string)($row[SystemNotification::schema_fields_avatar] ?? ''),
                (string)($row['create_time'] ?? ''),
            );
        }
        return $records;
    }
}
