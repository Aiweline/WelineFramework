<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Scope\ScopeCatalogInterface;
use Weline\Acl\Model\Acl;
use Weline\Framework\Manager\ObjectManager;

final class ScopeCatalog implements ScopeCatalogInterface
{
    public function listExposableRows(): array
    {
        return $this->newAclModel()->reset()
            ->where(Acl::schema_fields_API_EXPOSABLE, 1)
            ->where(Acl::schema_fields_IS_ENABLE, 1)
            ->order(Acl::schema_fields_MODULE, 'ASC')
            ->order(Acl::schema_fields_SCOPE_GROUP, 'ASC')
            ->order(Acl::schema_fields_ACCESS_MODE, 'ASC')
            ->select()
            ->fetchArray();
    }

    public function getExposableRowsBySourceIds(array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        return $this->newAclModel()->reset()
            ->where(Acl::schema_fields_SOURCE_ID, $sourceIds, 'in')
            ->where(Acl::schema_fields_API_EXPOSABLE, 1)
            ->where(Acl::schema_fields_IS_ENABLE, 1)
            ->select()
            ->fetchArray();
    }

    public function getEnabledRowsBySourceIds(array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        return $this->newAclModel()->reset()
            ->where(Acl::schema_fields_SOURCE_ID, $sourceIds, 'in')
            ->where(Acl::schema_fields_IS_ENABLE, 1)
            ->select()
            ->fetchArray();
    }

    public function normalizeAccessMode(?string $accessMode = null, ?string $httpMethod = null): string
    {
        return Acl::normalizeAccessMode($accessMode, $httpMethod);
    }

    private function newAclModel(): Acl
    {
        return ObjectManager::getInstance(Acl::class, [], false);
    }
}
