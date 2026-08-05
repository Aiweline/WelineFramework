<?php

declare(strict_types=1);

namespace Weline\Acl\Service\Resource;

use Weline\Acl\Model\RoleAccess;
use Weline\Framework\Manager\ObjectManager;

/** Renames role_access rows before orphan cleanup deletes old source_ids (D-3). */
final class SourceIdRenameMigrator
{
    /**
     * @param array<string,string> $map old_source_id => new_source_id
     * @return int number of role_access rows rewritten
     */
    public function migrate(array $map): int
    {
        if ($map === []) {
            return 0;
        }
        /** @var RoleAccess $roleAccess */
        $roleAccess = ObjectManager::getInstance(RoleAccess::class);
        $changed = 0;
        foreach ($map as $old => $new) {
            $old = \trim((string)$old);
            $new = \trim((string)$new);
            if ($old === '' || $new === '' || $old === $new) {
                continue;
            }
            $rows = $roleAccess->reset()
                ->where(RoleAccess::schema_fields_SOURCE_ID, $old)
                ->select()
                ->fetchArray();
            foreach ($rows as $row) {
                $roleId = (int)($row[RoleAccess::schema_fields_ROLE_ID] ?? 0);
                if ($roleId <= 0) {
                    continue;
                }
                $exists = $roleAccess->reset()
                    ->where(RoleAccess::schema_fields_ROLE_ID, $roleId)
                    ->where(RoleAccess::schema_fields_SOURCE_ID, $new)
                    ->find()
                    ->fetch();
                if ((string)$exists->getData(RoleAccess::schema_fields_SOURCE_ID) === $new) {
                    $roleAccess->reset()
                        ->where(RoleAccess::schema_fields_ROLE_ID, $roleId)
                        ->where(RoleAccess::schema_fields_SOURCE_ID, $old)
                        ->delete()
                        ->fetch();
                } else {
                    $roleAccess->reset()
                        ->where(RoleAccess::schema_fields_ROLE_ID, $roleId)
                        ->where(RoleAccess::schema_fields_SOURCE_ID, $old)
                        ->update([RoleAccess::schema_fields_SOURCE_ID => $new])
                    ->fetch();
                }
                ++$changed;
            }
        }
        return $changed;
    }
}
