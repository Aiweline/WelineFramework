<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Notification;

use Weline\Backend\Service\NotificationSeedService;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class NotificationSeedServiceInterfaceFactory implements FactoryObjectInterface
{
    public function create(): NotificationSeedServiceInterface
    {
        return ObjectManager::getInstance(NotificationSeedService::class);
    }
}
