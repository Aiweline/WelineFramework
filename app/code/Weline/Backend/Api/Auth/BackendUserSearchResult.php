<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

final readonly class BackendUserSearchResult
{
    /**
     * @param list<BackendUserIdentity> $users
     */
    public function __construct(
        private array $users,
        private mixed $pagination,
    ) {
    }

    /** @return list<BackendUserIdentity> */
    public function getUsers(): array
    {
        return $this->users;
    }

    public function getPagination(): mixed
    {
        return $this->pagination;
    }
}
