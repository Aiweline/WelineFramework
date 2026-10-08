<?php

declare(strict_types=1);

namespace Weline\Framework\DateTime;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;

/**
 * One-shot: treat existing naive schedule windows as website-local, rewrite to UTC.
 * Table targets come from owning-module {@see ScheduleWindowUtcMigrationContributionInterface} provides.
 */
final class ScheduleWindowUtcMigrator
{
    public const MARKER_PATH_SUFFIX = '/var/schedule_windows_utc_migrated_v1';

    /**
     * @return array{skipped:bool,reason?:string,updated:array<string,int>,dry_run:bool,timezone?:string}
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
        $updated = [];
        foreach ($this->contributions() as $contribution) {
            try {
                foreach ($contribution->targets($tz) as $target) {
                    if (!\is_array($target)) {
                        continue;
                    }
                    $id = \trim((string)($target['id'] ?? ''));
                    $model = (string)($target['model'] ?? '');
                    $columns = $target['columns'] ?? [];
                    if ($id === '' || $model === '' || !\is_array($columns) || $columns === []) {
                        continue;
                    }
                    $extra = $target['extra'] ?? [];
                    if (!\is_array($extra)) {
                        $extra = [];
                    }
                    /** @var list<string> $columnList */
                    $columnList = \array_values(\array_filter(\array_map('strval', $columns)));
                    /** @var array<string, string> $extraSet */
                    $extraSet = [];
                    foreach ($extra as $k => $v) {
                        $extraSet[(string)$k] = (string)$v;
                    }
                    $updated[$id] = $this->migrateTableColumns($model, $columnList, $tz, $dryRun, $extraSet);
                }
            } catch (\Throwable) {
                continue;
            }
        }

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
     * @return list<ScheduleWindowUtcMigrationContributionInterface>
     */
    private function contributions(): array
    {
        $out = [];
        try {
            $registry = ObjectManager::getInstance(ServiceProviderRegistry::class);
            if (!$registry instanceof ServiceProviderRegistry) {
                return [];
            }
            $prefix = ScheduleWindowUtcMigrationContributionInterface::CAPABILITY_PREFIX;
            foreach ($registry->implementationsWithPrefix($prefix) as $implementation) {
                try {
                    $instance = ObjectManager::getInstance($implementation);
                    if ($instance instanceof ScheduleWindowUtcMigrationContributionInterface) {
                        $out[] = $instance;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
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
