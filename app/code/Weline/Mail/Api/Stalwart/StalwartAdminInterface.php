<?php

declare(strict_types=1);

namespace Weline\Mail\Api\Stalwart;

/**
 * Public boundary for mailbox provisioning on the managed Stalwart server.
 */
interface StalwartAdminInterface
{
    public function ensureDomain(string $domainName): string;

    public function provisionOrResetAccount(string $mailbox, string $password, int $quotaMb): string;

    public function deleteAccount(string $mailbox): void;

    public function accountExists(string $mailbox): bool;

    /** @return list<string> */
    public function listLocalPartsForDomain(string $domainName): array;
}
