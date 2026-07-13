<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Api;

/** Read-only module identity and metadata catalog for declared module consumers. */
interface ModuleCatalogInterface
{
    public function idByName(string $name): int;

    /** @return list<int> */
    public function idsMatchingName(string $query): array;

    /** @param list<int> $ids @return array<int, ModuleCatalogEntry> */
    public function byIds(array $ids): array;
}
