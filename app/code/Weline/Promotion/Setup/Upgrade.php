<?php

declare(strict_types=1);

namespace Weline\Promotion\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Promotion\Model\PromotionActivityTheme;
use Weline\Promotion\Model\PromotionActivityThemeLocal;
use Weline\Promotion\Model\PromotionActivityThemeProduct;
use Weline\Promotion\Service\PromotionActivityThemeService;

class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);
        foreach ([PromotionActivityTheme::class, PromotionActivityThemeLocal::class, PromotionActivityThemeProduct::class] as $modelClass) {
            $model = ObjectManager::getInstance($modelClass);
            $modelSetup->putModel($model);
            $model->upgrade($modelSetup, $context);
        }

        ObjectManager::getInstance(PromotionActivityThemeService::class)->ensureDefaultThemes();

        $this->repairActivityThemeLocalPrimaryKey($modelSetup);
    }

    private function repairActivityThemeLocalPrimaryKey(ModelSetup $modelSetup): void
    {
        $model = ObjectManager::getInstance(PromotionActivityThemeLocal::class);
        $modelSetup->putModel($model);
        $connector = $modelSetup->getConnection()->getConnector();
        if (!$connector->tableExist($model->getTable())) {
            return;
        }

        $dbType = \strtolower((string)$modelSetup->getConnection()->getConfigProvider()->getDbType());
        if (!\in_array($dbType, ['pgsql', 'postgres', 'postgresql'], true)) {
            return;
        }

        $physicalTable = $this->resolvePhysicalTableName($model->getTable());
        if ($physicalTable === '') {
            return;
        }

        $connection = $model->getConnection();
        $constraints = $connection->query(
            "SELECT c.conname, pg_get_constraintdef(c.oid) AS def
             FROM pg_constraint c
             JOIN pg_class t ON c.conrelid = t.oid
             WHERE t.relname = '{$physicalTable}' AND c.contype = 'p'
             ORDER BY c.conname",
        )->fetchArray();
        if (!\is_array($constraints)) {
            return;
        }

        $needsRepair = false;
        foreach ($constraints as $constraint) {
            if (!\is_array($constraint)) {
                continue;
            }
            $definition = (string)($constraint['def'] ?? '');
            if ($definition !== '' && !\str_contains($definition, 'local_code')) {
                $needsRepair = true;
                break;
            }
        }
        if (!$needsRepair) {
            return;
        }

        $quotedTable = 'public.' . $connector->quoteIdentifier($physicalTable);
        $connection->query(
            "ALTER TABLE {$quotedTable} DROP CONSTRAINT IF EXISTS {$connector->quoteIdentifier($physicalTable . '_pkey')}",
        );
        $connection->query(
            "ALTER TABLE {$quotedTable} ALTER COLUMN id DROP DEFAULT",
        );
        $connection->query(
            "DROP SEQUENCE IF EXISTS {$connector->quoteIdentifier($physicalTable . '_id_seq')}",
        );
        $connection->query(
            "ALTER TABLE {$quotedTable} ADD PRIMARY KEY (id, local_code)",
        );
    }

    private function resolvePhysicalTableName(string $tableName): string
    {
        $tableName = \trim($tableName, "\" \t\n\r\0\x0B");
        if (\preg_match('/^(?:public\.)?"([^"]+)"$/', $tableName, $matches) === 1) {
            return $matches[1];
        }
        if (\preg_match('/^(?:public\.)?([a-zA-Z0-9_]+)$/', $tableName, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }
}
