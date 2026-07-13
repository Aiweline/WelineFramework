<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Api\UserData\BackendCurrentUserDataInterface;
use Weline\Backend\Model\BackendUserData;

final class BackendCurrentUserData implements BackendCurrentUserDataInterface
{
    public function __construct(
        private readonly BackendUserContextProvider $userContext,
        private readonly BackendUserData $userData,
    ) {
    }

    public function getScope(string $scope): array
    {
        $scope = trim($scope);
        $userId = $this->currentUserId();
        if ($userId <= 0 || $scope === '') {
            return [];
        }

        $row = (clone $this->userData)
            ->clearData()
            ->clearQuery()
            ->where(BackendUserData::schema_fields_BACKEND_USER_ID, $userId)
            ->where(BackendUserData::schema_fields_scope, $scope)
            ->find()
            ->fetchArray();
        if (!is_array($row) || $row === []) {
            return [];
        }

        $decoded = json_decode((string)($row[BackendUserData::schema_fields_JSON] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function clearScope(string $scope): bool
    {
        $scope = trim($scope);
        $userId = $this->currentUserId();
        if ($userId <= 0 || $scope === '') {
            return false;
        }

        return (bool)(clone $this->userData)
            ->clearData()
            ->clearQuery()
            ->where(BackendUserData::schema_fields_BACKEND_USER_ID, $userId)
            ->where(BackendUserData::schema_fields_scope, $scope)
            ->delete()
            ->fetch();
    }

    private function currentUserId(): int
    {
        return (int)($this->userContext->current()?->getId() ?? 0);
    }
}
