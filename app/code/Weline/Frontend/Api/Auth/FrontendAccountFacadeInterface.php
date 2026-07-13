<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\Auth;

/**
 * Frontend-owned account boundary for trusted in-process identity integrations.
 *
 * Implementations must keep ORM models and session persistence inside Frontend.
 */
interface FrontendAccountFacadeInterface
{
    public function search(string $search = '', int $page = 1, int $pageSize = 20): FrontendUserSearchResult;

    public function find(int $userId): ?FrontendUserIdentity;

    public function findByUsernameOrEmail(string $username, string $email): ?FrontendUserIdentity;

    public function loginTrustedIdentity(FrontendUserIdentity $identity, string $avatar = ''): FrontendUserIdentity;
}
