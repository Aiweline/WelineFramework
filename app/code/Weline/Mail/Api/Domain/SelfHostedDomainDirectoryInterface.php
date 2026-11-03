<?php

declare(strict_types=1);

namespace Weline\Mail\Api\Domain;

/**
 * Read-only view of locally-managed mail domains (for SMTP self-host import).
 */
interface SelfHostedDomainDirectoryInterface
{
    /**
     * @return list<array{domain: string, hostname: string}>
     */
    public function listSelfHostedDomains(): array;
}
