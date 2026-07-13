<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

interface BackendUserContextProviderInterface
{
    public function current(): ?BackendUserContext;

    public function find(int $userId): ?BackendUserContext;
}
