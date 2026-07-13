<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Auth\BackendUserDirectoryInterface;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;

final class BackendUserDirectory implements BackendUserDirectoryInterface
{
    public function __construct(
        private readonly BackendUserContextProvider $contexts,
    ) {
    }

    public function all(): array
    {
        /** @var BackendUser $users */
        $users = ObjectManager::getInstance(BackendUser::class, [], false);
        $rows = $users->fields(BackendUser::schema_fields_ID)
            ->where(BackendUser::schema_fields_is_deleted, 0)
            ->order(BackendUser::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $result = [];
        foreach ($rows as $row) {
            $context = $this->find((int)($row[BackendUser::schema_fields_ID] ?? 0));
            if ($context !== null) {
                $result[] = $context;
            }
        }
        return $result;
    }

    public function find(int $userId): ?BackendUserContext
    {
        return $this->contexts->find($userId);
    }
}
