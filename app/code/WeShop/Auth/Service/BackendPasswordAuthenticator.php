<?php

declare(strict_types=1);

namespace WeShop\Auth\Service;

use Weline\Backend\Model\BackendUser;

class BackendPasswordAuthenticator
{
    public function __construct(
        private readonly BackendUser $backendUser
    ) {
    }

    public function authenticate(string $username, string $password): BackendUser
    {
        $username = trim($username);

        $backendUser = $this->backendUser->reset()
            ->where(BackendUser::schema_fields_username, $username)
            ->find()
            ->fetch();

        if (!$backendUser->getId() && str_contains($username, '@')) {
            $backendUser = $this->backendUser->reset()
                ->where(BackendUser::schema_fields_email, $username)
                ->find()
                ->fetch();
        }

        if (
            !$backendUser->getId()
            || !$backendUser->getIsEnabled()
            || !password_verify($password, (string) $backendUser->getPassword())
        ) {
            throw new \RuntimeException((string) __('Invalid credentials.'));
        }

        return $backendUser;
    }
}
