<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

/** Data-only backend API identity. */
final readonly class BackendApiUser
{
    public function __construct(
        private int $id,
        private string $username,
        private string $email,
        private string $avatar,
        private bool $enabled,
        private string $loginIp,
        private string $loginTime,
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

    public function getIsEnabled(): bool
    {
        return $this->enabled;
    }

    public function getLoginIp(): string
    {
        return $this->loginIp;
    }

    public function getLoginTime(): string
    {
        return $this->loginTime;
    }

    public function isSandboxAccount(): bool
    {
        return $this->sandbox;
    }
}
