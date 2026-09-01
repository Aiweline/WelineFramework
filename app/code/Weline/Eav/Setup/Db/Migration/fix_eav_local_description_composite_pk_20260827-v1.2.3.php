<?php

declare(strict_types=1);

namespace Weline\Eav\Setup\Db\Migration;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;

class FixEavLocalDescriptionCompositePk20260827V123 extends AbstractMigration
{
    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            'eav_attribute_set_local_description',
            'eav_attribute_group_local_description',
            'eav_entity_local_description',
            'eav_attribute_type_local_description',
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
        return $this->tables();
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $pdo = $connection->getLink();

        foreach ($this->tables() as $table) {
            if (!$this->tableExists($connection, $table)) {
                continue;
            }

            $indexName = $table . '_pkey';
            $pdo->exec(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', $table, $indexName));
            $pdo->exec(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s PRIMARY KEY (id, local_code)',
                $table,
                $indexName,
            ));
        }

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    private function tableExists(object $connection, string $table): bool
    {
        $pdo = $connection->getLink();
        $statement = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table LIMIT 1',
        );
        $statement->execute(['table' => $table]);

        return (bool)$statement->fetchColumn();
    }
}
