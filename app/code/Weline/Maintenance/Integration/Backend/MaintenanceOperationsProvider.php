<?php

declare(strict_types=1);

namespace Weline\Maintenance\Integration\Backend;

use Weline\Backend\Api\Maintenance\MaintenanceOperationsProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Maintenance\Helper\IpMatcher;
use Weline\Maintenance\Service\BackupManager;

final class MaintenanceOperationsProvider implements MaintenanceOperationsProviderInterface
{
    public function isValidCidr(string $cidr): bool
    {
        return IpMatcher::isValidCidr($cidr);
    }

    public function createBackup(string $type, int|string|null $operatorId): void
    {
        /** @var BackupManager $backupManager */
        $backupManager = ObjectManager::getInstance(BackupManager::class);
        $createdBy = \is_numeric($operatorId) ? (int)$operatorId : null;
        $backupManager->createBackup($type, $createdBy);
    }
}
