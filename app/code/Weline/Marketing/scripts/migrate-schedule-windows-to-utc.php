<?php

declare(strict_types=1);

/**
 * One-shot migrate naive schedule windows → UTC via Framework Timezone.
 *
 * Usage:
 *   php app/code/Weline/Marketing/scripts/migrate-schedule-windows-to-utc.php
 *   php app/code/Weline/Marketing/scripts/migrate-schedule-windows-to-utc.php --dry-run
 */

use Weline\Framework\DateTime\ScheduleWindowUtcMigrator;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
/** @var ScheduleWindowUtcMigrator $migrator */
$migrator = ObjectManager::getInstance(ScheduleWindowUtcMigrator::class);
$result = $migrator->migrate($dryRun);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
