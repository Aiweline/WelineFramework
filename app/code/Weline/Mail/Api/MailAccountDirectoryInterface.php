<?php

declare(strict_types=1);

namespace Weline\Mail\Api;

/**
 * Read-only account directory for outbound account resolution.
 */
interface MailAccountDirectoryInterface
{
    /**
     * Active account id for the given sender email, 0 when absent.
     */
    public function activeAccountIdByEmail(string $email): int;
}
