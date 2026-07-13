<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Resource;

/**
 * Data-only persistence boundary for compiled backend menu resources.
 *
 * Parsing and topology stay in the owning Backend module; ACL owns every
 * query and mutation against its resource schema.
 */
interface MenuRegistryInterface
{
    /** @param list<string> $modules */
    public function listManagedMenus(array $modules = []): array;

    /** @param list<string> $sourceIds */
    public function deleteManagedMenus(array $sourceIds): void;

    /** @param list<string> $sourceIds */
    public function disableManagedMenus(array $sourceIds): void;

    public function upsertManagedMenu(string $sourceId, array $data): void;

    /** @return list<string> */
    public function getManagedChildSources(string $parentSource): array;
}
