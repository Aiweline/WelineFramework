<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

/** Data-only directory for cross-module backend-user selectors. */
interface BackendUserDirectoryInterface
{
    /** @return list<BackendUserContext> */
    public function all(): array;

    public function find(int $userId): ?BackendUserContext;
}
