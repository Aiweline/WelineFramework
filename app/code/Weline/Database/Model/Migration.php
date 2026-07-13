<?php

declare(strict_types=1);

namespace Weline\Database\Model;

/**
 * @deprecated Use the Framework-owned migration model directly.
 *
 * This compatibility subclass deliberately owns no schema declaration. Both
 * runtimes now operate on the single Framework bootstrap table/model.
 */
class Migration extends \Weline\Framework\Setup\Model\Migration
{
    /** @return list<string> */
    public function getInstalledMigrationFiles(string $moduleName): array
    {
        $rows = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED)
            ->where(self::schema_fields_FILE, '%.php', 'LIKE')
            ->select(self::schema_fields_FILE)
            ->fetchArray();

        $files = [];
        foreach ($rows as $row) {
            $file = trim((string)($row[self::schema_fields_FILE] ?? ''));
            if ($file !== '') {
                $files[$file] = true;
            }
        }
        $this->clearData();

        return array_keys($files);
    }

    public function deleteMigration(string $moduleName, string $migrationFile): bool
    {
        $items = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_FILE, $migrationFile)
            ->select()
            ->fetch()
            ->getItems();

        foreach ($items as $item) {
            $item->delete();
        }

        return true;
    }
}
