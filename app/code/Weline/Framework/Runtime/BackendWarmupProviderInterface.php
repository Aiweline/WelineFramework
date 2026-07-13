<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Http\Request;

interface BackendWarmupProviderInterface
{
    public function resolveWarmupUserId(): int;

    public function installRequestContext(Request $request): void;

    public function shouldBypassLogin(Request $request): bool;
}
