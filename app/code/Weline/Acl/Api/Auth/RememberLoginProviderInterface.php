<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Auth;

use Weline\Framework\Http\Request;

interface RememberLoginProviderInterface
{
    public function restoreIfNeeded(Request $request): bool;

    /** @return array{user_id:int,role_id:int,is_enabled:int}|null */
    public function consumeRestoredAclContext(): ?array;

    public function consumeRestoredSession(): ?object;
}
