<?php

declare(strict_types=1);

namespace Weline\Mail\Service\Stalwart;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Api\Stalwart\StalwartAdminInterface;
use Weline\Mail\Service\StalwartManagementAdapter;

/**
 * Adapter exposing Stalwart mailbox administration through the public Mail API.
 */
final class StalwartAdmin implements StalwartAdminInterface
{
    public function ensureDomain(string $domainName): string
    {
        return ObjectManager::getInstance(StalwartManagementAdapter::class)->ensureDomain($domainName);
    }

    public function provisionOrResetAccount(string $mailbox, string $password, int $quotaMb): string
    {
        return ObjectManager::getInstance(StalwartManagementAdapter::class)
            ->provisionOrResetAccount($mailbox, $password, $quotaMb);
    }

    public function deleteAccount(string $mailbox): void
    {
        ObjectManager::getInstance(StalwartManagementAdapter::class)->deleteAccount($mailbox);
    }

    public function accountExists(string $mailbox): bool
    {
        return ObjectManager::getInstance(StalwartManagementAdapter::class)->accountExists($mailbox);
    }

    public function listLocalPartsForDomain(string $domainName): array
    {
        return ObjectManager::getInstance(StalwartManagementAdapter::class)->listLocalPartsForDomain($domainName);
    }
}
