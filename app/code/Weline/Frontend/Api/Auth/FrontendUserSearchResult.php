<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\Auth;

final readonly class FrontendUserSearchResult
{
    /**
     * @param list<FrontendUserIdentity> $users
     */
    public function __construct(
        private array $users,
        private mixed $pagination,
    ) {
    }

    /** @return list<FrontendUserIdentity> */
    public function getUsers(): array
    {
        return $this->users;
    }

    public function getPagination(): mixed
    {
        return $this->pagination;
    }
}
