<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

/** 角色对象 Scope 授权存储。 */
interface ObjectScopeGrantStoreInterface
{
    /**
     * @return list<ObjectScopeGrantRecord>
     */
    public function findByRole(int $roleId): array;
}
