<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

use Weline\Acl\Api\RoleIdentityInterface;
use Weline\Framework\Session\Auth\AuthenticableInterface;

/** Runtime actor envelope; concrete Backend models remain owned by Backend. */
final readonly class BackendApiActor
{
    public function __construct(
        private AuthenticableInterface $user,
        private ?RoleIdentityInterface $role,
        private bool $enabled,
        private bool $deleted,
    ) {
    }

    public function getUser(): AuthenticableInterface
    {
        return $this->user;
    }

    public function getRole(): ?RoleIdentityInterface
    {
        return $this->role;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }
}
