<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Api;

use Weline\Framework\Module\ModuleIdentityProviderInterface;
use Weline\ModuleManager\Model\Module;

final class ModuleIdentityProvider implements ModuleIdentityProviderInterface
{
    public function __construct(
        private readonly Module $module,
    ) {
    }

    public function idsByNames(array $names): array
    {
        $names = \array_values(\array_unique(\array_filter(
            \array_map(static fn(mixed $name): string => \trim((string)$name), $names),
            static fn(string $name): bool => $name !== '',
        )));
        if ($names === []) {
            return [];
        }

        $rows = $this->module->reset()
            ->fields(Module::schema_fields_ID . ',' . Module::schema_fields_NAME)
            ->where(Module::schema_fields_NAME, $names, 'IN')
            ->select()
            ->fetchArray();
        $result = [];
        foreach ((array)$rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $name = (string)($row[Module::schema_fields_NAME] ?? '');
            if ($name !== '') {
                $result[$name] = (int)($row[Module::schema_fields_ID] ?? 0);
            }
        }
        return $result;
    }

    public function namesByIds(array $ids): array
    {
        $ids = \array_values(\array_unique(\array_filter(
            \array_map('intval', $ids),
            static fn(int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }

        $rows = $this->module->reset()
            ->fields(Module::schema_fields_ID . ',' . Module::schema_fields_NAME)
            ->where(Module::schema_fields_ID, $ids, 'IN')
            ->select()
            ->fetchArray();
        $result = [];
        foreach ((array)$rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row[Module::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $result[$id] = (string)($row[Module::schema_fields_NAME] ?? '');
            }
        }
        return $result;
    }
}
