<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

final readonly class BackendApiLoginResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_USER_NOT_FOUND = 'user_not_found';
    public const STATUS_USER_DISABLED = 'user_disabled';
    public const STATUS_PASSWORD_INVALID = 'password_invalid';
    public const STATUS_TOKEN_FAILED = 'token_failed';

    public function __construct(
        private string $status,
        private ?string $token = null,
        private ?BackendApiUser $user = null,
    ) {
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getUser(): ?BackendApiUser
    {
        return $this->user;
    }
}
