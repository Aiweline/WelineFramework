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
     * @return array{success: bool, message: string, needs_admin?: bool, command?: string, already_exists?: bool, elevated?: bool}
     */
    public static function addDomain(string $domain, string $ip = '127.0.0.1'): array
    {
        return HostsFileManager::addDomain($domain, $ip);
    }
}
