<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Api\Notification\NotificationSeedServiceInterface;
use Weline\Backend\Model\SystemNotification;
use Weline\Framework\Manager\ObjectManager;

final class NotificationSeedService implements NotificationSeedServiceInterface
{
    public function seedDefaults(string $sourceModule, array $notifications): void
    {
        /** @var SystemNotification $model */
        $model = ObjectManager::getInstance(SystemNotification::class, [], false);
        if ($model->reset()->count() > 0) {
            return;
        }

        foreach ($notifications as $notification) {
            $model->clearData()
                ->setTitle((string)($notification['title'] ?? ''))
                ->setContent((string)($notification['content'] ?? ''))
                ->setSourceModule($sourceModule)
                ->setType((string)($notification['type'] ?? 'info'))
                ->setIsIcon((bool)($notification['is_icon'] ?? true))
                ->setIsImg((bool)($notification['is_img'] ?? false))
                ->setAvatar((string)($notification['avatar'] ?? ''))
                ->save();
        }
    }
}
