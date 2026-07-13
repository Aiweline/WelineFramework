<?php

declare(strict_types=1);

namespace Weline\Admin\Api\Notification;

interface SystemNotificationDirectoryInterface
{
    /** @return list<SystemNotificationRecord> */
    public function listUnread(): array;
}
