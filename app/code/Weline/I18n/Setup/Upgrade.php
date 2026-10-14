<?php

declare(strict_types=1);

namespace Weline\I18n\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\I18n\Model\Dictionary as BaseDictionary;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locale\Dictionary;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\I18n\Service\Catalog\DisplayNameCartesianSeeder;
use Weline\I18n\Service\DictionaryAreaPolicy;
use Weline\I18n\Service\LocaleDisplayRankSeedService;

/**
 * I18n DDL 增量 + 已装激活语种展示名懒切片补洞。
 */
final class Upgrade implements UpgradeInterface
{
    public const NULL_SOURCE_COVERING_INDEX = 'idx_locale_null_source_covering';

    public function setup(Setup $setup, Context $context): void
    {
        $this->ensureNullSourceCoveringIndex();
        $this->ensureLocaleDisplayRankColumn($context);
        $this->seedLocaleDisplayRanks();
        $this->backfillDictionaryAreaFromIsBackend();
        $this->syncCatalogInventoryGaps();
        $this->backfillInstalledLocaleDisplayNames();
    }

    private function ensureLocaleDisplayRankColumn(Context $context): void
    {
        try {
            /** @var Locale $locale */
            $locale = ObjectManager::getInstance(Locale::class);
            $runner = ObjectManager::make(ModelSetup::class);
            $runner->putModel($locale);
            $locale->setup($runner, $context);
        } catch (\Throwable $e) {
            w_log_warning('I18n: locale display_rank schema skipped: ' . $e->getMessage(), [], 'i18n');
        }
    }

    private function seedLocaleDisplayRanks(): void
    {
        try {
            ObjectManager::getInstance(LocaleDisplayRankSeedService::class)->seedMissingDisplayRanks();
        } catch (\Throwable $e) {
            w_log_warning('I18n: locale display_rank seed skipped: ' . $e->getMessage(), [], 'i18n');
        }
    }

    /**
     * area 列空值 → shared；exclusive 权威交给全量 collect 路径证据重铺（禁 is_backend→frontend）。
     */
    private function backfillDictionaryAreaFromIsBackend(): void
    {
        try {
            $modelSetup = ObjectManager::make(ModelSetup::class);
            /** @var BaseDictionary $model */
            $model = ObjectManager::getInstance(BaseDictionary::class);
            $modelSetup->putModel($model);
            if (!$modelSetup->tableExist()) {
                return;
            }
            $connector = $modelSetup->getConnection()->getConnector();
            $phys = self::physicalTableSql($connector, (string)$model->getTable());
            $areaCol = $connector->quoteIdentifier(BaseDictionary::schema_fields_AREA);
            $shared = $connector->quote(DictionaryAreaPolicy::AREA_SHARED);

            // 禁止对 UPDATE 结果 fetch()：大表会触发 Unbounded SELECT 门禁并中断升级。
            $connector->query(
                "UPDATE {$phys} SET {$areaCol} = {$shared} WHERE {$areaCol} IS NULL OR TRIM({$areaCol}) = ''"
            );
        } catch (\Throwable $e) {
            w_log_warning('I18n: dictionary area backfill skipped: ' . $e->getMessage(), [], 'i18n');
        }
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

    /**
     * @param mixed $fetch Connector::fetch() 结果（关联行或行列表）
     */
    private static function scalarCountFromFetch(mixed $fetch): int
    {
        if (!\is_array($fetch) || $fetch === []) {
            return 0;
        }
        $row = $fetch;
        if (\array_is_list($fetch) && isset($fetch[0]) && \is_array($fetch[0])) {
            $row = $fetch[0];
        }
        if (!\is_array($row)) {
            return (int)$row;
        }

        return (int)($row['c'] ?? $row['C'] ?? \reset($row) ?: 0);
    }

    private static function physicalTableSql(object $connector, string $table): string
    {
        $table = \trim(\str_replace('"', '', $table));
        if ($table === '') {
            return $connector->quoteIdentifier('w_i18n_dictionary');
        }
        if (\str_contains($table, '.')) {
            [$schema, $name] = \explode('.', $table, 2);

            return $connector->quoteIdentifier($schema) . '.' . $connector->quoteIdentifier($name);
        }

        return $connector->quoteIdentifier($table);
    }
}
