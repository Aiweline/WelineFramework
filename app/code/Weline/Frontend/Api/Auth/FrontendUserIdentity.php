<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\Auth;

/** Data-only legacy frontend-user identity. */
final readonly class FrontendUserIdentity
{
    public function __construct(
        private int $id,
        private string $username,
        private string $email,
        private string $avatar,
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
