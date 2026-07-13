<?php

declare(strict_types=1);

namespace Weline\Admin\Api\Notification;

use Weline\Admin\Service\SystemNotificationDirectory;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class SystemNotificationDirectoryInterfaceFactory implements FactoryObjectInterface
{
    public function create(): SystemNotificationDirectoryInterface
    {
        return ObjectManager::getInstance(SystemNotificationDirectory::class);
    }
}
