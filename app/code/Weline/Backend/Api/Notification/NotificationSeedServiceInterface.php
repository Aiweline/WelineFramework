<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Notification;

interface NotificationSeedServiceInterface
{
    /** @param list<array<string, mixed>> $notifications */
    public function seedDefaults(string $sourceModule, array $notifications): void;
}
