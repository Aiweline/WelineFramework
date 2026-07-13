<?php

declare(strict_types=1);

namespace Weline\Backend\Api\User;

final readonly class BackendUserPage
{
    /** @param list<BackendUserRecord> $users */
    public function __construct(
        private array $users,
        private mixed $pagination,
    ) {
    }

    /** @return list<BackendUserRecord> */
    public function getUsers(): array
    {
        return $this->users;
    }

    public function getPagination(): mixed
    {
        return $this->pagination;
    }
}
