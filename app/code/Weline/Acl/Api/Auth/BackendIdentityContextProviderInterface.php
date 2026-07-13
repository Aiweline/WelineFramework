<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Auth;

use Weline\Framework\Http\Request;

interface BackendIdentityContextProviderInterface
{
    /** @return array{user_id:int,role_id:int,is_enabled:int}|null */
    public function getAclContext(int $userId): ?array;

    public function currentWarmupUserId(Request $request): int;
}
