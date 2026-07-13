<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\User;

/** Scalar-only input for the legacy frontend-user administration form. */
final readonly class FrontendUserSaveCommand
{
    public function __construct(
        public int $userId,
        public string $username,
        public string $password,
        public string $avatar,
        public bool $resetAttempts,
        public bool $sandbox,
    ) {
    }
}
