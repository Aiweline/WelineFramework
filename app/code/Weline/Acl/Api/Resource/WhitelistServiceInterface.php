<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Resource;

interface WhitelistServiceInterface
{
    /** @return list<string> */
    public function listPaths(string $type = 'pc'): array;

    /** @param list<string> $paths */
    public function upsertPaths(array $paths, string $type = 'pc'): void;
}
