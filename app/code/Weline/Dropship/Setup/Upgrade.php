<?php

declare(strict_types=1);

namespace Weline\Dropship\Setup;

use Weline\Dropship\Model\DropshipScopeWarehouseMap;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

/**
 * One-shot: copy cj_* → remote_* then drop legacy columns (no runtime fallback).
 */
class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $this->migrateWarehouseMapUniqueKey();
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

    private function migrateWarehouseMapUniqueKey(): void
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
            $prefix = (string)$conn->getConfigProvider()->getPrefix();
            $physical = ($prefix !== '' ? $prefix : '') . DropshipScopeWarehouseMap::schema_table;
            $pdo = $connector->getLink();
            $stmt = $pdo->prepare('SELECT indexname, indexdef FROM pg_indexes WHERE tablename = :t');
            $stmt->execute(['t' => $physical]);
            $wantCols = ['provider_code', 'website_id', 'store_id', 'remote_country_code'];
            $hasWanted = false;
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $name = (string)($row['indexname'] ?? '');
                $def = strtolower((string)($row['indexdef'] ?? ''));
                if ($name === '' || !str_contains($def, 'unique')) {
                    continue;
                }
                $hasAll = true;
                foreach ($wantCols as $col) {
                    if (!str_contains($def, $col)) {
                        $hasAll = false;
                        break;
                    }
                }
                $looksOld = str_contains($def, 'provider_code')
                    && str_contains($def, 'website_id')
                    && str_contains($def, 'store_id')
                    && !str_contains($def, 'remote_country_code');
                if ($hasAll) {
                    $hasWanted = true;
                    continue;
                }
                if ($looksOld || str_contains($name, 'uk_drop')) {
                    $pdo->exec('DROP INDEX IF EXISTS ' . $quote($name));
                }
            }
            if (!$hasWanted) {
                $idx = $quote('uk_dropship_scope_wh_country');
                $table = $quote($physical);
                $pdo->exec(
                    'CREATE UNIQUE INDEX IF NOT EXISTS ' . $idx . ' ON ' . $table
                    . ' (' . $quote('provider_code') . ', ' . $quote('website_id') . ', '
                    . $quote('store_id') . ', ' . $quote('remote_country_code') . ')'
                );
            }
        } catch (\Throwable $e) {
            w_log_warning('Dropship warehouse map unique key migrate: ' . $e->getMessage());
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

            if ($hasLegacyCountry) {
                $model->reset()->query(
                    'ALTER TABLE ' . $tableIdent . ' DROP COLUMN IF EXISTS ' . $quote('cj_country_code')
                )->fetch();
            }
            if ($hasLegacyStorage) {
                $model->reset()->query(
                    'ALTER TABLE ' . $tableIdent . ' DROP COLUMN IF EXISTS ' . $quote('cj_storage_id')
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
