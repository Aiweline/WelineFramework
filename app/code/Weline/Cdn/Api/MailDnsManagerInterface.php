<?php

declare(strict_types=1);

namespace Weline\Cdn\Api;

/**
 * Cross-module command boundary for reconciling mail DNS records.
 *
 * Mail supplies desired public records; Cdn owns provider authentication,
 * planning, writes, verification and rollback.
 */
interface MailDnsManagerInterface
{
    /**
     * @param array<int, array<string, mixed>> $desiredRecords
     * @param array<int, string> $dnsOnlyHosts
     * @return array<string, mixed> Redacted plan or apply result
     */
    public function reconcile(
        string $domain,
        array $desiredRecords,
        array $dnsOnlyHosts,
        bool $apply = false,
    ): array;
}
