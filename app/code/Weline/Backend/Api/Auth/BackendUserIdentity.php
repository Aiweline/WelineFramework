<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

/** Data-only backend identity exposed to declared module consumers. */
final readonly class BackendUserIdentity
{
    public function __construct(
        private int $id,
        private string $username,
        private string $email,
        private string $avatar,
        private bool $enabled,
        private bool $deleted,
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /** @return array{user_id: int, id: int, username: string, email: string, avatar: string} */
    public function toSelectorArray(): array
    {
        return [
            'user_id' => $this->id,
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'avatar' => $this->avatar,
        ];
    }
}
