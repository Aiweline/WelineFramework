<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Service;

use Weline\ModuleManager\Api\ModuleCatalogEntry;
use Weline\ModuleManager\Api\ModuleCatalogInterface;
use Weline\ModuleManager\Model\Module;

final class ModuleCatalog implements ModuleCatalogInterface
{
    public function __construct(
        private readonly Module $module,
    ) {
    }

    public function idByName(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $module = $this->module->clearData()
            ->fields(Module::schema_fields_ID)
            ->where(Module::schema_fields_NAME, $name)
            ->find()
            ->fetch();

        return (int) $module->getId();
    }

    public function idsMatchingName(string $query): array
    {
        $rows = $this->module->clearData()
            ->fields(Module::schema_fields_ID)
            ->where(Module::schema_fields_NAME, '%' . $query . '%', 'like')
            ->select()
            ->fetchArray();

        $ids = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row[Module::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }

        $rows = $this->module->clearData()
            ->fields($this->catalogFields())
            ->where(Module::schema_fields_ID, $ids, 'IN')
            ->select()
            ->fetchArray();

        $entries = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entry = new ModuleCatalogEntry($row);
            if ($entry->getId() > 0) {
                $entries[$entry->getId()] = $entry;
            }
        }

        return $entries;
    }

    private function catalogFields(): string
    {
        return implode(',', [
            Module::schema_fields_ID,
            Module::schema_fields_NAME,
            Module::schema_fields_STATUS,
            Module::schema_fields_DESCRIPTION,
            Module::schema_fields_POSITION,
            Module::schema_fields_NAMESPACE_PATH,
            Module::schema_fields_BASE_PATH,
            Module::schema_fields_PATH,
            Module::schema_fields_VERSION,
            Module::schema_fields_LAST_VERSION,
            Module::schema_fields_ROUTER,
            Module::schema_fields_CREATE_TIME,
            Module::schema_fields_UPDATE_TIME,
        ]);
    }
}
