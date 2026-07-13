<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\User;

/** Frontend-owned CRUD/token boundary for declared administration consumers. */
interface FrontendUserAdministrationInterface
{
    public function search(string $keyword, int $page, int $pageSize): FrontendUserPage;

    public function find(int $userId): ?FrontendUserRecord;

    public function save(FrontendUserSaveCommand $command): FrontendUserMutationResult;

    public function delete(int $userId): FrontendUserMutationResult;

    public function resetToken(int $userId): FrontendUserMutationResult;

    public function resetPassword(int $userId, string $newPassword): FrontendUserMutationResult;
}
