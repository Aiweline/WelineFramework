<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Authorization\ObjectScopeGrantRecord;
use Weline\Acl\Api\Authorization\ObjectScopeGrantStoreInterface;

/** 测试/进程内授权存储。 */
final class ArrayObjectScopeGrantStore implements ObjectScopeGrantStoreInterface
{
    /** @param list<ObjectScopeGrantRecord> $grants */
    public function __construct(
        private array $grants = [],
    ) {
    }

    /** @param list<ObjectScopeGrantRecord> $grants */
    public function replaceAll(array $grants): void
    {
        $this->grants = $grants;
    }

    public function findByRole(int $roleId): array
    {
        $out = [];
        foreach ($this->grants as $grant) {
            if ($grant->roleId === $roleId) {
                $out[] = $grant;
            }
        }

        return $out;
    }
}
