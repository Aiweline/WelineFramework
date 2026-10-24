<?php

declare(strict_types=1);

namespace Weline\Dropship\Setup;

use Weline\Dropship\Model\DropshipScopeWarehouseMap;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

/**
 * One-shot: copy legacy cj_* → remote_*; schema/unique key is declared by the Model.
 */
class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $this->migrateWarehouseMapLegacyColumns();
        try {
            ObjectManager::getInstance(\Weline\Product\Service\ProductCatalogEavBootstrap::class)
                ->ensureDropshipSchema();
            ObjectManager::getInstance(\Weline\Product\Service\ProductCategoryEavBootstrap::class)
                ->ensureCategorySchema();
        } catch (\Throwable) {
            // Soft: schema may already exist / EAV not ready during early install.
        }
    }

    private function migrateWarehouseMapLegacyColumns(): void
    {
        try {
            /** @var DropshipScopeWarehouseMap $model */
            $model = ObjectManager::getInstance(DropshipScopeWarehouseMap::class);
            $conn = $model->getConnection();
            $connector = $conn->getConnector();
            $quote = static function (string $ident) use ($connector): string {
                if (method_exists($connector, 'quoteIdentifier')) {
                    return (string)$connector->quoteIdentifier($ident);
                }

                return '"' . str_replace('"', '""', $ident) . '"';
            };

            $tableIdent = $quote(DropshipScopeWarehouseMap::schema_table);
            $cols = $this->listColumns($model);
            $hasLegacyCountry = \in_array('cj_country_code', $cols, true);
            $hasLegacyStorage = \in_array('cj_storage_id', $cols, true);
            $hasRemoteCountry = \in_array('remote_country_code', $cols, true);
            $hasRemoteStorage = \in_array('remote_storage_id', $cols, true);

            if ($hasLegacyCountry && $hasRemoteCountry) {
                $model->reset()->query(
                    'UPDATE ' . $tableIdent
                    . ' SET ' . $quote('remote_country_code') . ' = ' . $quote('cj_country_code')
                    . ' WHERE (' . $quote('remote_country_code') . ' IS NULL OR ' . $quote('remote_country_code') . " = '')"
                    . ' AND ' . $quote('cj_country_code') . ' IS NOT NULL AND ' . $quote('cj_country_code') . " <> ''"
                )->fetch();
            }
            if ($hasLegacyStorage && $hasRemoteStorage) {
                $model->reset()->query(
                    'UPDATE ' . $tableIdent
                    . ' SET ' . $quote('remote_storage_id') . ' = ' . $quote('cj_storage_id')
                    . ' WHERE (' . $quote('remote_storage_id') . ' IS NULL OR ' . $quote('remote_storage_id') . " = '')"
                    . ' AND ' . $quote('cj_storage_id') . ' IS NOT NULL AND ' . $quote('cj_storage_id') . " <> ''"
                )->fetch();
            }
        } catch (\Throwable $e) {
            w_log_warning('Dropship warehouse map legacy migrate: ' . $e->getMessage());
            // Do not block upgrade permanently; retry next bump if schema mid-flight.
        }
    }

    /**
     * @return list<string>
     */
    private function listColumns(DropshipScopeWarehouseMap $model): array
    {
        try {
            $pdo = $model->getConnection()->getConnector()->getLink();
            $table = DropshipScopeWarehouseMap::schema_table;
            $stmt = $pdo->prepare(
                'SELECT column_name FROM information_schema.columns'
                . ' WHERE table_name = :t OR table_name = :tp'
            );
            $prefix = (string)$model->getConnection()->getConfigProvider()->getPrefix();
            $stmt->execute([
                't' => $table,
                'tp' => $prefix !== '' ? $prefix . $table : $table,
            ]);
            $out = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $name = (string)($row['column_name'] ?? $row['COLUMN_NAME'] ?? '');
                if ($name !== '') {
                    $out[] = $name;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
