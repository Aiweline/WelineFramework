<?php

declare(strict_types=1);

namespace Weline\Eav\Setup\Db\Migration;

use Weline\Eav\Model\EavAttribute\Group\LocalDescription as GroupLocalDescription;
use Weline\Eav\Model\EavAttribute\Set\LocalDescription as SetLocalDescription;
use Weline\Eav\Model\EavAttribute\Type\LocalDescription as TypeLocalDescription;
use Weline\Eav\Model\EavEntity\LocalDescription as EntityLocalDescription;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;

/**
 * Prod symptom: LocalModel AI could translate but Model::save() hit 25P02 because
 * ON CONFLICT (id, local_code) had no matching unique/PK (tables only PK'd on id + serial).
 */
class FixEavLocalDescriptionCompositePk20260827V123 extends AbstractMigration
{
    /**
     * @return list<class-string>
     */
    private function modelClasses(): array
    {
        return [
            SetLocalDescription::class,
            GroupLocalDescription::class,
            EntityLocalDescription::class,
            TypeLocalDescription::class,
        ];
    }

    public function getDescription(): string
    {
        return 'EAV LocalDescription 表主键改为 (id, local_code)，支持同一节点多语言行。';
    }

    public function getVersion(): string
    {
        return '1.2.3';
    }

    public function getDate(): string
    {
        return '2026-08-27';
    }

    /**
     * @return array<int, string>
     */
    public function getAffectedTables(): array
    {
        $tables = [];
        foreach ($this->modelClasses() as $class) {
            $tables[] = ObjectManager::getInstance($class)->getTable();
        }

        return $tables;
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $pdo = $connection->getLink();

        foreach ($this->modelClasses() as $class) {
            $model = ObjectManager::getInstance($class);
            $qualified = $model->getTable();
            $bare = $this->bareTableName($qualified);
            if ($bare === '' || !$this->tableExists($pdo, $bare)) {
                continue;
            }
            if ($this->hasCompositePrimaryKey($pdo, $bare)) {
                $this->dropSerialDefault($pdo, $qualified);
                continue;
            }

            $constraint = $bare . '_pkey';
            $pdo->exec(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', $qualified, $constraint));
            $this->dropSerialDefault($pdo, $qualified);
            $pdo->exec(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s PRIMARY KEY (id, local_code)',
                $qualified,
                $constraint,
            ));
        }

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    private function bareTableName(string $qualified): string
    {
        $qualified = trim($qualified);
        if (preg_match('/"([^"]+)"\s*$/', $qualified, $m)) {
            return $m[1];
        }

        if (str_contains($qualified, '.')) {
            return (string)substr($qualified, (int)strrpos($qualified, '.') + 1);
        }

        return $qualified;
    }

    private function tableExists(\PDO $pdo, string $bareTable): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table LIMIT 1',
        );
        $statement->execute(['table' => $bareTable]);

        return (bool)$statement->fetchColumn();
    }

    private function hasCompositePrimaryKey(\PDO $pdo, string $bareTable): bool
    {
        $statement = $pdo->prepare(
            <<<'SQL'
SELECT COUNT(*) FROM information_schema.table_constraints tc
JOIN information_schema.key_column_usage kcu
  ON tc.constraint_name = kcu.constraint_name
 AND tc.table_schema = kcu.table_schema
WHERE tc.table_schema = current_schema()
  AND tc.table_name = :table
  AND tc.constraint_type = 'PRIMARY KEY'
  AND kcu.column_name IN ('id', 'local_code')
SQL
        );
        $statement->execute(['table' => $bareTable]);

        return (int)$statement->fetchColumn() >= 2;
    }

    private function dropSerialDefault(\PDO $pdo, string $qualifiedTable): void
    {
        try {
            $pdo->exec(sprintf('ALTER TABLE %s ALTER COLUMN id DROP DEFAULT', $qualifiedTable));
        } catch (\Throwable) {
            // Column may already lack a serial default.
        }
    }
}
