<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

/** Exact source_id authorization boundary for non-route transports. */
interface ResourceAuthorizationServiceInterface
{
    public function isSourceAllowed(int $roleId, string $sourceId): bool;
}
