<?php

declare(strict_types=1);

namespace Weline\Customer\Api\Auth;

/** Data-only customer identity; password/session internals never cross the module boundary. */
final readonly class CustomerIdentity
{
    public function __construct(
        private int $id,
        private string $username,
        private string $email,
        private string $avatar,
        private bool $sandbox,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAvatar(): string
    {
        return $this->avatar;
    }

    public function isSandboxAccount(): bool
    {
        return $this->sandbox;
    }
}
