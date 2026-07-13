<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

/** Data-only remember-login token metadata; the token secret never escapes. */
final readonly class BackendRememberToken
{
    public function __construct(
        private int $userId,
        private int $expireAt,
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getExpireAt(): int
    {
        return $this->expireAt;
    }
}
