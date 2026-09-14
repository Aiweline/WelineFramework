<?php

declare(strict_types=1);

namespace Weline\Framework\DateTime;

use Weline\Framework\Manager\ObjectManager;

/**
 * One-shot: treat existing naive schedule windows as website-local, rewrite to UTC.
 */
final class ScheduleWindowUtcMigrator
{
    public const MARKER_PATH_SUFFIX = '/var/schedule_windows_utc_migrated_v1';

    /**
     * @return array{skipped:bool,reason?:string,updated:array<string,int>,dry_run:bool}
     */
    public function migrate(bool $dryRun = false, ?string $timezone = null): array
    {
        $marker = $this->markerPath();
        if (is_file($marker)) {
            return [
                'skipped' => true,
                'reason' => 'already_migrated',
                'updated' => [],
                'dry_run' => $dryRun,
            ];
        }

        $tz = Timezone::resolveWebsiteTimezone($timezone);
        $updated = [
            'marketing_campaign' => $this->migrateTableColumns(
                'Weline\\Marketing\\Model\\Campaign\\Campaign',
                ['start_date', 'end_date'],
                $tz,
                $dryRun,
            ),
            'marketing_rule' => $this->migrateTableColumns(
                'Weline\\Marketing\\Model\\Rule\\Rule',
                ['start_date', 'end_date'],
                $tz,
                $dryRun,
            ),
            'marketing_coupon' => $this->migrateTableColumns(
                'Weline\\Marketing\\Model\\Coupon\\Coupon',
                ['start_date', 'end_date'],
                $tz,
                $dryRun,
            ),
            'theme_layout_schedule' => $this->migrateTableColumns(
                'Weline\\Theme\\Model\\ThemeLayoutSchedule',
                ['starts_at', 'ends_at'],
                $tz,
                $dryRun,
                ['timezone' => $tz],
            ),
            'promotion_activity_theme' => $this->migrateTableColumns(
                'Weline\\Promotion\\Model\\PromotionActivityTheme',
                ['starts_at', 'ends_at'],
                $tz,
                $dryRun,
            ),
        ];

        if (!$dryRun) {
            $dir = dirname($marker);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            file_put_contents($marker, Timezone::utcNowSql() . ' tz=' . $tz . PHP_EOL);
        }

        return [
            'skipped' => false,
            'updated' => $updated,
            'dry_run' => $dryRun,
            'timezone' => $tz,
        ];
    }

    /**
     * @param list<string> $columns
     * @param array<string, string> $extraSet
     */
    private function migrateTableColumns(
        string $modelClass,
        array $columns,
        string $timezone,
        bool $dryRun,
        array $extraSet = [],
    ): int {
        if (!class_exists($modelClass)) {
            return 0;
        }
        try {
            $model = ObjectManager::getInstance($modelClass);
        } catch (\Throwable) {
            return 0;
        }
        $rows = $model->clear()->clearQuery()->select()->fetchArray();
        if (!is_array($rows)) {
            return 0;
        }
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pk = property_exists($model, 'schema_primary_key') || defined($modelClass . '::schema_primary_key')
                ? (string)$modelClass::schema_primary_key
                : 'id';
            $id = (int)($row[$pk] ?? $row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $changes = $extraSet;
            foreach ($columns as $column) {
                $raw = trim((string)($row[$column] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $utc = Timezone::migrateNaiveLocalToUtcSql($raw, $timezone);
                if ($utc === $raw) {
                    continue;
                }
                $changes[$column] = $utc;
            }
            if ($changes === []) {
                continue;
            }
            ++$count;
            if ($dryRun) {
                continue;
            }
            try {
                $entity = ObjectManager::getInstance($modelClass);
                $entity->clear()->clearQuery()->load($id);
                if (!(int)$entity->getId()) {
                    continue;
                }
                foreach ($changes as $field => $value) {
                    $entity->setData($field, $value);
                }
                $entity->save();
            } catch (\Throwable) {
                // Best-effort per row.
            }
        }

        return $count;
    }

    private function markerPath(): string
    {
        $root = defined('BP') ? (string)BP : dirname(__DIR__, 5);

        return rtrim($root, '/') . self::MARKER_PATH_SUFFIX;
    }
}
