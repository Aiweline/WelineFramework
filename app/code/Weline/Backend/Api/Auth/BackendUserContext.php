<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

final readonly class BackendUserContext
{
    public function __construct(
        private int $id,
        private string $username,
        private string $email,
        private string $avatar,
        private int $roleId,
        private bool $enabled,
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

    public function getRoleId(): int
    {
        return $this->roleId;
    }

    public function getIsEnabled(): bool
    {
        return $this->enabled;
    }

    public function isSandboxAccount(): bool
    {
        return $this->sandbox;
    }
}
