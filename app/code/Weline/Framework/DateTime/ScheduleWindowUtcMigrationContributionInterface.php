<?php

declare(strict_types=1);

namespace Weline\Framework\DateTime;

/**
 * Owning-module schedule-window UTC migration targets.
 * Register as `schedule_window_utc_migration.<Module>` in etc/module.php.
 */
interface ScheduleWindowUtcMigrationContributionInterface
{
    public const CAPABILITY_PREFIX = 'schedule_window_utc_migration.';

    /**
     * @return list<array{
     *   id: string,
     *   model: class-string,
     *   columns: list<string>,
     *   extra?: array<string, string>
     * }>
     */
    public function targets(string $timezone): array;
}
