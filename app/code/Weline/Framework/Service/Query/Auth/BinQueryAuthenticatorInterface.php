<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Auth;

interface BinQueryAuthenticatorInterface
{
    public function authenticate(string $token): ?BinQueryAuthContext;
}
