<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Menu;

/** Data-only backend menu lookup contract. */
interface MenuReaderInterface
{
    public function getMenuTreeByRoleId(int $roleId): array;

    public function getMenuTreeByUserId(int $userId): array;

    public function hasMenuEntry(int $roleId): bool;

    public function getDefaultEntryRoute(int $roleId): ?string;

    public function findMenuNodeByRoute(int $roleId, string $routePath): ?array;
}
