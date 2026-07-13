<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

/**
 * Backend-owned account boundary for trusted in-process identity integrations.
 *
 * Implementations must keep ORM models and session persistence inside Backend.
 */
interface BackendAccountFacadeInterface
{
    public function search(string $search = '', int $page = 1, int $pageSize = 20): BackendUserSearchResult;

    public function find(int $userId): ?BackendUserIdentity;

    public function findByUsernameOrEmail(string $username, string $email): ?BackendUserIdentity;

    public function loginTrustedIdentity(BackendUserIdentity $identity, string $avatar = ''): BackendUserIdentity;
}
