<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Console\Cron\Task;

use PHPUnit\Framework\TestCase;
use Weline\Cron\Console\Cron\Task\Run;

/** WS3-C: cron process entry warms SystemConfig module maps + NamespaceVersion IN. */
final class CronTaskRunWarmupContractTest extends TestCase
{
    public function testManagedChildWarmsConfigAndNamespaceBeforeExecute(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 5) . '/Console/Cron/Task/Run.php',
        );
        self::assertStringContainsString('warmupBackgroundProcessCaches($task)', $src);
        self::assertStringContainsString('function warmupBackgroundProcessCaches', $src);
        self::assertStringContainsString('warmupModuleMaps($modules)', $src);
        self::assertStringContainsString('prefetchProcessVector(', $src);
        self::assertTrue(class_exists(Run::class));
    }
}
