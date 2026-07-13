<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth;

interface BackendSessionUserProviderInterface
{
    public function findEnabledBySessionId(string $sessionId): ?object;
}
