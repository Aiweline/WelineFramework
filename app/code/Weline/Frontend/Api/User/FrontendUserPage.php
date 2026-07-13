<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\User;

final readonly class FrontendUserPage
{
    /** @param list<FrontendUserRecord> $users */
    public function __construct(
        private array $users,
        private int $total,
        private int $page,
        private int $pageSize,
        private ?string $tokenCountError = null,
    ) {
    }

    /** @return list<FrontendUserRecord> */
    public function getUsers(): array
    {
        return $this->users;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function getTokenCountError(): ?string
    {
        return $this->tokenCountError;
    }
}
