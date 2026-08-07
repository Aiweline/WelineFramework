<?php
declare(strict_types=1);

namespace Weline\Server\Api\System;

use Weline\Server\Service\HostsFileManager;

/**
 * Public boundary for the Server-owned, platform-aware hosts writer.
 */
final class HostsWriter
{
    /**
     * @return array{success: bool, message: string, needs_admin?: bool, already_exists?: bool, repaired?: bool, ip?: string, status?: string, error_code?: string}
     */
    public static function addDomain(string $domain, string $ip = '127.0.0.1'): array
    {
        return HostsFileManager::addDomain($domain, $ip);
    }
}
