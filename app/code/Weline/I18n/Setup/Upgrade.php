<?php

declare(strict_types=1);

namespace Weline\I18n\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\I18n\Model\Locale\Dictionary;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\I18n\Service\Catalog\DisplayNameCartesianSeeder;

/**
 * I18n DDL 增量 + 已装激活语种展示名懒切片补洞。
 */
final class Upgrade implements UpgradeInterface
{
    public const NULL_SOURCE_COVERING_INDEX = 'idx_locale_null_source_covering';

    public function setup(Setup $setup, Context $context): void
    {
        $this->ensureNullSourceCoveringIndex();
        $this->syncCatalogInventoryGaps();
        $this->backfillInstalledLocaleDisplayNames();
    }

    private function syncCatalogInventoryGaps(): void
    {
        try {
            /** @var DisplayNameCartesianSeeder $seeder */
            $seeder = ObjectManager::getInstance(DisplayNameCartesianSeeder::class);
            $seeder->syncInventoryGaps(false);
        } catch (\Throwable $e) {
            w_log_warning('I18n: catalog inventory gap sync skipped: ' . $e->getMessage(), [], 'i18n');
        }
    }

    private function backfillInstalledLocaleDisplayNames(): void
    {
        try {
            /** @var DisplayNameCartesianSeeder $seeder */
            $seeder = ObjectManager::getInstance(DisplayNameCartesianSeeder::class);
            /** @var ActiveLocaleCodeProvider $active */
            $active = ObjectManager::getInstance(ActiveLocaleCodeProvider::class);
            foreach ($active->getInstalledActiveCodes() as $code) {
                $seeder->expandForInstalledLocale((string)$code);
            }
            foreach (DisplayNameCartesianSeeder::BASELINE_DISPLAY_LOCALES as $baseline) {
                $seeder->expandForInstalledLocale($baseline);
            }
        } catch (\Throwable $e) {
            w_log_warning('I18n: display-name backfill skipped: ' . $e->getMessage(), [], 'i18n');
        }
    }

    private function ensureNullSourceCoveringIndex(): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);
        /** @var Dictionary $model */
        $model = ObjectManager::getInstance(Dictionary::class);
        $modelSetup->putModel($model);
        if (!$modelSetup->tableExist()) {
            return;
        }

        $dbType = \strtolower((string)$modelSetup->getConnection()->getConfigProvider()->getDbType());
        if (!\in_array($dbType, ['pgsql', 'postgres', 'postgresql'], true)) {
            return;
        }

        $physicalTable = $this->resolvePhysicalTableName((string)$model->getTable());
        if ($physicalTable === '') {
            return;
        }

        $connector = $modelSetup->getConnection()->getConnector();
        $quotedTable = 'public.' . $connector->quoteIdentifier($physicalTable);
        $indexName = self::NULL_SOURCE_COVERING_INDEX;
        $sql = "CREATE INDEX IF NOT EXISTS \"{$indexName}\" ON {$quotedTable} (locale_code)"
            . ' INCLUDE (word, translate)'
            . " WHERE (source_module IS NULL OR source_module = '')";
        try {
            $model->getConnection()->query($sql);
        } catch (\Throwable $e) {
            w_log_warning('I18n: failed to ensure ' . $indexName . ': ' . $e->getMessage(), [], 'i18n');
        }
    }

    private function resolvePhysicalTableName(string $table): string
    {
        $table = \trim($table);
        if ($table === '') {
            return '';
        }
        $table = \str_replace('"', '', $table);
        if (\str_contains($table, '.')) {
            $parts = \explode('.', $table);

            return (string)\end($parts);
        }

        return $table;
    }
}
