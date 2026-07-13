<?php

declare(strict_types=1);

namespace Weline\Customer\Api\Auth;

use Weline\Framework\Session\Auth\AuthenticableInterface;

interface CustomerIdentityProviderInterface
{
    public function find(int $customerId): ?AuthenticableInterface;
}
