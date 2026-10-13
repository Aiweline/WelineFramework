<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Theme\Cron\LayoutSolidifyDrain;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyJobStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifySerialKey;

final class LayoutSolidifyDrainCronContractTest extends TestCase
{
    public function testCronImplementsInterfaceAndSchedule(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Cron/LayoutSolidifyDrain.php');
        self::assertStringContainsString('implements CronTaskInterface', $src);
        self::assertStringContainsString("'theme_layout_solidify_drain'", $src);
        self::assertStringContainsString("'* * * * *'", $src);
        self::assertStringContainsString('drainPendingJobs', $src);
        self::assertTrue(is_a(LayoutSolidifyDrain::class, CronTaskInterface::class, true));
    }

    public function testJobStorePutCoalescesAndLists(): void
    {
        $root = sys_get_temp_dir() . '/weline-solidify-jobs-' . bin2hex(random_bytes(4));
        @mkdir($root, 0775, true);
        try {
            $paths = new ThemeLayoutEntityPaths($root . DIRECTORY_SEPARATOR);
            $store = new ThemeLayoutEntitySolidifyJobStore($paths);
            $key = ThemeLayoutEntitySolidifySerialKey::fromParts(
                7,
                'frontend',
                'grocery.__store__.__channel__',
                'normal',
                'homepage',
                'default',
                1385,
                2,
            );
            $fp = str_repeat('b', 64);
            $a = $store->put($key, $fp);
            $b = $store->put($key, $fp);
            self::assertTrue($a['enqueued']);
            self::assertFalse($a['coalesced']);
            self::assertFalse($b['enqueued']);
            self::assertTrue($b['coalesced']);
            self::assertTrue($store->has($key));
            $list = $store->listPending(8);
            self::assertCount(1, $list);
            self::assertSame($key->toString(), $list[0]['key']->toString());
            self::assertSame($fp, $list[0]['expected_fp']);
            $store->delete($key);
            self::assertFalse($store->has($key));
        } finally {
            $this->purge($root);
        }
    }

    public function testPurgeStaleDryRunAndApplyHonourPredicate(): void
    {
        $root = sys_get_temp_dir() . '/weline-solidify-jobs-gc-' . bin2hex(random_bytes(4));
        @mkdir($root, 0775, true);
        try {
            $paths = new ThemeLayoutEntityPaths($root . DIRECTORY_SEPARATOR);
            $store = new ThemeLayoutEntitySolidifyJobStore($paths);
            $alive = ThemeLayoutEntitySolidifySerialKey::fromParts(
                7, 'frontend', 'alive.default.default', 'normal', 'homepage', 'default', 10, 1,
            );
            $dead = ThemeLayoutEntitySolidifySerialKey::fromParts(
                391, 'frontend', 'history.test.default', 'normal', 'history-test', 'default', 1430, 2,
            );
            $store->put($alive, str_repeat('a', 64));
            $store->put($dead, str_repeat('b', 64));
            $predicate = ThemeLayoutEntitySolidifyJobStore::defaultStalePredicate(
                static fn(int $themeId): bool => $themeId === 7,
                static fn(int $themeId, int $versionId, string $scope, string $area, string $storeMode): bool => $themeId === 7,
                86400,
            );
            $dry = $store->purgeStale($predicate, false);
            self::assertCount(1, $dry['candidates']);
            self::assertSame(0, $dry['purged']);
            self::assertTrue($store->has($dead));
            $applied = $store->purgeStale($predicate, true);
            self::assertSame(1, $applied['purged']);
            self::assertFalse($store->has($dead));
            self::assertTrue($store->has($alive));
            $failed = $root . '/391/.solidify-jobs/failed';
            self::assertDirectoryExists($failed);
        } finally {
            $this->purge($root);
        }
    }

    public function testMoveToFailedRemovesPending(): void
    {
        $root = sys_get_temp_dir() . '/weline-solidify-jobs-dl-' . bin2hex(random_bytes(4));
        @mkdir($root, 0775, true);
        try {
            $paths = new ThemeLayoutEntityPaths($root . DIRECTORY_SEPARATOR);
            $store = new ThemeLayoutEntitySolidifyJobStore($paths);
            $key = ThemeLayoutEntitySolidifySerialKey::fromParts(
                9, 'frontend', 'x.default.default', 'normal', 'homepage', 'default', 1, 1,
            );
            $store->put($key, str_repeat('c', 64));
            self::assertTrue($store->moveToFailed($key, 'unit_fail'));
            self::assertFalse($store->has($key));
            self::assertFileExists($root . '/9/.solidify-jobs/failed/' . $key->hash() . '.json');
        } finally {
            $this->purge($root);
        }
    }

    private function purge(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
