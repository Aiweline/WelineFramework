<?php

declare(strict_types=1);

namespace Weline\Backend\Api\User;

/** Backend-owned administrator CRUD and role-assignment boundary. */
interface BackendUserAdministrationInterface
{
    public const FIELD_USERNAME = 'username';
    public const FIELD_EMAIL = 'email';

    public function search(string $search = ''): BackendUserPage;

    public function listWithRoles(): BackendUserPage;

    public function find(int $userId): ?BackendUserRecord;

    public function findByUsername(string $username, ?int $excludeUserId = null): ?BackendUserRecord;

    public function findByEmail(string $email, ?int $excludeUserId = null): ?BackendUserRecord;

    /** @return list<int> */
    public function idsMatchingUsername(string $query): array;

    /** @param list<int> $userIds @return array<int, string> */
    public function usernamesByIds(array $userIds): array;

    public function save(
        ?int $userId,
        string $username,
        string $email,
        ?string $password = null,
    ): BackendUserRecord;

    public function setState(int $userId, bool $enabled, ?bool $deleted = null): ?BackendUserRecord;

    public function assignRole(int $userId, ?int $roleId): void;
}
