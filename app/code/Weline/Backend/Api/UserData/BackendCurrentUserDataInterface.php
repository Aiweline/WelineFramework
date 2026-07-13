<?php

declare(strict_types=1);

namespace Weline\Backend\Api\UserData;

/**
 * Scalar/array-only boundary for data scoped to the authenticated backend user.
 */
interface BackendCurrentUserDataInterface
{
    /** @return array<string, mixed> */
    public function getScope(string $scope): array;

    public function clearScope(string $scope): bool;
}
