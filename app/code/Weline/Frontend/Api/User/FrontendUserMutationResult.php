<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\User;

final readonly class FrontendUserMutationResult
{
    public const SAVED = 'saved';
    public const NOT_FOUND = 'not_found';
    public const DUPLICATE_USERNAME = 'duplicate_username';
    public const PASSWORD_REQUIRED = 'password_required';
    public const DELETED = 'deleted';
    public const DELETE_FAILED = 'delete_failed';
    public const TOKEN_RESET = 'token_reset';
    public const PASSWORD_RESET = 'password_reset';

    public function __construct(
        private string $status,
    ) {
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
