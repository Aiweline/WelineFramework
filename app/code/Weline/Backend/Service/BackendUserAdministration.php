<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Acl\Api\Role\RoleCatalogInterface;
use Weline\Backend\Api\User\BackendUserAdministrationInterface;
use Weline\Backend\Api\User\BackendUserPage;
use Weline\Backend\Api\User\BackendUserRecord;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;

final class BackendUserAdministration implements BackendUserAdministrationInterface
{
    public function __construct(
        private readonly BackendUser $userPrototype,
        private readonly UserRole $userRolePrototype,
        private readonly RoleCatalogInterface $roleCatalog,
    ) {
    }

    public function search(string $search = ''): BackendUserPage
    {
        $users = $this->newUser();
        if ($search) {
            $users->concat_like('username,email', '%' . $search . '%');
        }

        $collection = $users->order()
            ->pagination()
            ->select()
            ->fetch();

        return $this->mapPage($collection->getItems(), $collection->getPagination());
    }

    public function listWithRoles(): BackendUserPage
    {
        $collection = $this->newUser()
            ->order('main_table.create_time')
            ->pagination()
            ->select()
            ->fetch();

        return $this->mapPage($collection->getItems(), $collection->getPagination());
    }

    public function find(int $userId): ?BackendUserRecord
    {
        if ($userId <= 0) {
            return null;
        }

        $user = $this->newUser()->load($userId);
        return $user->getId() ? $this->mapUser($user) : null;
    }

    public function findByUsername(string $username, ?int $excludeUserId = null): ?BackendUserRecord
    {
        return $this->findByField(BackendUser::schema_fields_username, $username, $excludeUserId);
    }

    public function findByEmail(string $email, ?int $excludeUserId = null): ?BackendUserRecord
    {
        return $this->findByField(BackendUser::schema_fields_email, $email, $excludeUserId);
    }

    public function idsMatchingUsername(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $rows = $this->newUser()
            ->fields(BackendUser::schema_fields_ID)
            ->where(BackendUser::schema_fields_username, '%' . $query . '%', 'like')
            ->select()
            ->fetchArray();

        $ids = [];
        foreach ($rows as $row) {
            $userId = (int)($row[BackendUser::schema_fields_ID] ?? 0);
            if ($userId > 0) {
                $ids[$userId] = $userId;
            }
        }

        return array_values($ids);
    }

    public function usernamesByIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn(int $userId): bool => $userId > 0,
        )));
        if ($userIds === []) {
            return [];
        }

        $rows = $this->newUser()
            ->fields([BackendUser::schema_fields_ID, BackendUser::schema_fields_username])
            ->where(BackendUser::schema_fields_ID, $userIds, 'in')
            ->select()
            ->fetchArray();

        $usernames = [];
        foreach ($rows as $row) {
            $userId = (int)($row[BackendUser::schema_fields_ID] ?? 0);
            if ($userId > 0) {
                $usernames[$userId] = (string)($row[BackendUser::schema_fields_username] ?? '');
            }
        }

        return $usernames;
    }

    public function save(
        ?int $userId,
        string $username,
        string $email,
        ?string $password = null,
    ): BackendUserRecord {
        $user = $this->newUser();
        if ($userId !== null) {
            $user->setId($userId);
        }
        $user->setUsername($username)->setEmail($email);
        if ($password !== null && $password !== '') {
            $user->setPassword($password);
        }
        $user->save(true);

        return $this->mapUser($user);
    }

    public function setState(int $userId, bool $enabled, ?bool $deleted = null): ?BackendUserRecord
    {
        $user = $this->newUser()->load($userId);
        if (!$user->getId()) {
            return null;
        }

        $user->setIsEnabled($enabled);
        if ($deleted !== null) {
            $user->setIsDeleted($deleted);
        }
        $user->save();

        return $this->mapUser($user);
    }

    public function assignRole(int $userId, ?int $roleId): void
    {
        $userRole = $this->newUserRole();
        $existing = $userRole->where(UserRole::schema_fields_USER_ID, $userId)->select()->fetch();
        $items = $existing->getItems();

        if (count($items) === 1 && $roleId !== null) {
            $row = reset($items);
            $primaryKey = $userRole->getPrimaryKey();
            $relationId = is_array($row)
                ? ($row['id'] ?? $row[$primaryKey] ?? 0)
                : ($row instanceof UserRole ? $row->getData($primaryKey) : 0);
            $userRole->clearData()->load($relationId)->setRoleId($roleId)->save(true);
            return;
        }

        if (count($items) !== 1) {
            $userRole->where(UserRole::schema_fields_USER_ID, $userId)->delete()->fetch();
            if ($roleId !== null) {
                $userRole->clearData()->setUserId($userId)->setRoleId($roleId)->save(true);
            }
            return;
        }

        $userRole->where(UserRole::schema_fields_USER_ID, $userId)->delete()->fetch();
    }

    private function findByField(string $field, string $value, ?int $excludeUserId): ?BackendUserRecord
    {
        $user = $this->newUser()->where($field, $value);
        if (!empty($excludeUserId)) {
            $user->where(BackendUser::schema_fields_ID, $excludeUserId, '!=');
        }
        $user->find()->fetch();

        return $user->getId() ? $this->mapUser($user) : null;
    }

    /** @param array<int,mixed> $items */
    private function mapPage(array $items, mixed $pagination): BackendUserPage
    {
        $rows = [];
        $userIds = [];
        foreach ($items as $item) {
            $row = $item instanceof BackendUser ? $item->getData() : (is_array($item) ? $item : []);
            $userId = (int)($row[BackendUser::schema_fields_ID] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $rows[] = $row;
            $userIds[] = $userId;
        }

        $rolesByUser = $this->roleIdsByUser($userIds);
        $roleNames = $this->roleNames();
        $records = [];
        foreach ($rows as $row) {
            $userId = (int)$row[BackendUser::schema_fields_ID];
            $roleId = $rolesByUser[$userId] ?? ($userId === 1 ? 1 : 0);
            $records[] = $this->recordFromRow($row, $roleId, $roleNames[$roleId] ?? '');
        }

        return new BackendUserPage($records, $pagination);
    }

    private function mapUser(BackendUser $user): BackendUserRecord
    {
        $userId = (int)$user->getId();
        $roleId = $this->roleIdsByUser([$userId])[$userId] ?? ($userId === 1 ? 1 : 0);
        $roleName = $this->roleNames()[$roleId] ?? '';

        return $this->recordFromRow($user->getData(), $roleId, $roleName);
    }

    /** @param array<string,mixed> $row */
    private function recordFromRow(array $row, int $roleId, string $roleName): BackendUserRecord
    {
        return new BackendUserRecord(
            (int)($row[BackendUser::schema_fields_ID] ?? 0),
            (string)($row[BackendUser::schema_fields_username] ?? ''),
            (string)($row[BackendUser::schema_fields_email] ?? ''),
            (string)($row[BackendUser::schema_fields_avatar] ?? ''),
            (int)($row[BackendUser::schema_fields_attempt_times] ?? 0),
            (bool)($row[BackendUser::schema_fields_is_deleted] ?? false),
            (bool)($row[BackendUser::schema_fields_is_enabled] ?? false),
            (bool)($row[BackendUser::schema_fields_is_sandbox] ?? false),
            (string)($row['create_time'] ?? ''),
            $roleId,
            $roleName,
        );
    }

    /** @param list<int> $userIds @return array<int,int> */
    private function roleIdsByUser(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn(int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        $rows = $this->newUserRole()
            ->where(UserRole::schema_fields_USER_ID, $userIds, 'in')
            ->select()
            ->fetchArray();
        $result = [];
        foreach ($rows as $row) {
            $userId = (int)($row[UserRole::schema_fields_USER_ID] ?? 0);
            if ($userId > 0 && !isset($result[$userId])) {
                $result[$userId] = (int)($row[UserRole::schema_fields_ROLE_ID] ?? 0);
            }
        }
        return $result;
    }

    /** @return array<int,string> */
    private function roleNames(): array
    {
        $result = [];
        foreach ($this->roleCatalog->list() as $role) {
            $result[$role->getId()] = $role->getName();
        }
        return $result;
    }

    private function newUser(): BackendUser
    {
        return (clone $this->userPrototype)->clearData()->clearQuery();
    }

    private function newUserRole(): UserRole
    {
        return (clone $this->userRolePrototype)->clearData()->clearQuery();
    }
}
