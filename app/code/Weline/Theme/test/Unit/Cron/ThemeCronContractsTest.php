<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Theme\Cron\LayoutSolidifyDrain;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyJobStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifySerialKey;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeCronContractsPass().
 */
final class ThemeCronContractsTest extends TestCase
{
    public function testAllThemeCronContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'LayoutSolidifyDrainCron::testCronImplementsInterfaceAndSchedule' => function () {
            $this->assert_LayoutSolidifyDrainCron_testCronImplementsInterfaceAndSchedule();
        },
            'LayoutSolidifyDrainCron::testJobStorePutCoalescesAndLists' => function () {
            $this->assert_LayoutSolidifyDrainCron_testJobStorePutCoalescesAndLists();
        },
            'LayoutSolidifyDrainCron::testJobStoreSingleSlotAcrossVersionBump' => function () {
            $this->assert_LayoutSolidifyDrainCron_testJobStoreSingleSlotAcrossVersionBump();
        },
            'LayoutSolidifyDrainCron::testFoldLegacyMultiVersionJobsCollapsesOldHashFiles' => function () {
            $this->assert_LayoutSolidifyDrainCron_testFoldLegacyMultiVersionJobsCollapsesOldHashFiles();
        },
            'LayoutSolidifyDrainCron::testPurgeStaleDryRunAndApplyHonourPredicate' => function () {
            $this->assert_LayoutSolidifyDrainCron_testPurgeStaleDryRunAndApplyHonourPredicate();
        },
            'LayoutSolidifyDrainCron::testMoveToFailedRemovesPending' => function () {
            $this->assert_LayoutSolidifyDrainCron_testMoveToFailedRemovesPending();
        },
        ];
    }

private function assert_LayoutSolidifyDrainCron_testCronImplementsInterfaceAndSchedule(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Cron/LayoutSolidifyDrain.php');
    self::assertStringContainsString('implements CronTaskInterface', $src);
    self::assertStringContainsString("'theme_layout_solidify_drain'", $src);
    self::assertStringContainsString("'* * * * *'", $src);
    self::assertStringContainsString('drainPendingJobs', $src);
    self::assertTrue(is_a(LayoutSolidifyDrain::class, CronTaskInterface::class, true));
}

private function assert_LayoutSolidifyDrainCron_testJobStorePutCoalescesAndLists(): void
{
    $root = sys_get_temp_dir() . '/weline-solidify-jobs-' . bin2hex(random_bytes(4));
    @mkdir($root, 0775, true);
    try {
        $paths = new ThemeLayoutEntityPaths($root . DIRECTORY_SEPARATOR);
        $store = new ThemeLayoutEntitySolidifyJobStore($paths);
        $key = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'grocery.__store__.__channel__', 'normal', 'homepage', 'default', 1385, 2);
        $fp = str_repeat('b', 64);
        $fp2 = str_repeat('c', 64);
        $a = $store->put($key, $fp);
        $b = $store->put($key, $fp2);
        self::assertTrue($a['enqueued']);
        self::assertFalse($a['coalesced']);
        self::assertFalse($b['enqueued']);
        self::assertTrue($b['coalesced']);
        self::assertTrue($b['overwritten']);
        self::assertTrue($store->has($key));
        $list = $store->listPending(8);
        self::assertCount(1, $list);
        self::assertSame($key->toString(), $list[0]['key']->toString());
        self::assertSame($fp2, $list[0]['expected_fp']);
        $store->delete($key);
        self::assertFalse($store->has($key));
    } finally {
        $this->purge_LayoutSolidifyDrainCron($root);
    }
}

private function assert_LayoutSolidifyDrainCron_testJobStoreSingleSlotAcrossVersionBump(): void
{
    $root = sys_get_temp_dir() . '/weline-solidify-slot-' . bin2hex(random_bytes(4));
    @mkdir($root, 0775, true);
    try {
        $paths = new ThemeLayoutEntityPaths($root . DIRECTORY_SEPARATOR);
        $store = new ThemeLayoutEntitySolidifyJobStore($paths);
        $v1 = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'grocery.__store__.__channel__', 'normal', 'homepage', 'default', 10, 1);
        $v2 = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'grocery.__store__.__channel__', 'normal', 'homepage', 'default', 11, 2);
        self::assertSame($v1->slotHash(), $v2->slotHash());
        self::assertNotSame($v1->toString(), $v2->toString());
        $store->put($v1, str_repeat('a', 64));
        $store->put($v2, str_repeat('b', 64));
        $list = $store->listPending(8);
        self::assertCount(1, $list);
        self::assertSame(11, $list[0]['key']->themeVersionId);
        self::assertSame(str_repeat('b', 64), $list[0]['expected_fp']);
        self::assertFileExists($root . '/7/.solidify-jobs/' . $v1->slotHash() . '.json');
    } finally {
        $this->purge_LayoutSolidifyDrainCron($root);
    }
}

private function assert_LayoutSolidifyDrainCron_testFoldLegacyMultiVersionJobsCollapsesOldHashFiles(): void
{
    $root = sys_get_temp_dir() . '/weline-solidify-fold-' . bin2hex(random_bytes(4));
    $jobDir = $root . '/7/.solidify-jobs';
    @mkdir($jobDir, 0775, true);
    try {
        $paths = new ThemeLayoutEntityPaths($root . DIRECTORY_SEPARATOR);
        $store = new ThemeLayoutEntitySolidifyJobStore($paths);
        $older = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 's.default.default', 'normal', 'homepage', 'default', 1, 1);
        $newer = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 's.default.default', 'normal', 'homepage', 'default', 2, 2);
        // Simulate legacy filenames: sha256 of full toString (pre-slot era).
        $legacyOlder = $jobDir . '/' . hash('sha256', $older->toString()) . '.json';
        $legacyNewer = $jobDir . '/' . hash('sha256', $newer->toString()) . '.json';
        foreach ([[$legacyOlder, $older, '2026-10-15T10:00:00+00:00', 'a'], [$legacyNewer, $newer, '2026-10-15T11:00:00+00:00', 'b']] as [$path, $key, $queued, $fpChar]) {
            file_put_contents($path, json_encode(['serial' => $key->toString(), 'theme_id' => $key->themeId, 'area' => $key->area, 'scope' => $key->canonicalScope, 'store_mode' => $key->storeMode, 'layout_type' => $key->layoutType, 'layout_option' => $key->layoutOption, 'theme_version_id' => $key->themeVersionId, 'content_revision' => $key->contentRevision, 'expected_fp' => str_repeat($fpChar, 64), 'queued_at' => $queued], JSON_THROW_ON_ERROR));
        }
        $report = $store->foldLegacyMultiVersionJobs();
        self::assertGreaterThanOrEqual(1, $report['slots']);
        $list = $store->listPending(8);
        self::assertCount(1, $list);
        self::assertSame(2, $list[0]['key']->themeVersionId);
        self::assertSame(str_repeat('b', 64), $list[0]['expected_fp']);
        self::assertFileExists($jobDir . '/' . $newer->slotHash() . '.json');
    } finally {
        $this->purge_LayoutSolidifyDrainCron($root);
    }
}

private function assert_LayoutSolidifyDrainCron_testPurgeStaleDryRunAndApplyHonourPredicate(): void
{
    $root = sys_get_temp_dir() . '/weline-solidify-jobs-gc-' . bin2hex(random_bytes(4));
    @mkdir($root, 0775, true);
    try {
        $paths = new ThemeLayoutEntityPaths($root . DIRECTORY_SEPARATOR);
        $store = new ThemeLayoutEntitySolidifyJobStore($paths);
        $alive = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'alive.default.default', 'normal', 'homepage', 'default', 10, 1);
        $dead = ThemeLayoutEntitySolidifySerialKey::fromParts(391, 'frontend', 'history.test.default', 'normal', 'history-test', 'default', 1430, 2);
        $store->put($alive, str_repeat('a', 64));
        $store->put($dead, str_repeat('b', 64));
        $predicate = ThemeLayoutEntitySolidifyJobStore::defaultStalePredicate(static fn(int $themeId): bool => $themeId === 7, static fn(int $themeId, int $versionId, string $scope, string $area, string $storeMode): bool => $themeId === 7, 86400);
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
        $this->purge_LayoutSolidifyDrainCron($root);
    }
}

private function assert_LayoutSolidifyDrainCron_testMoveToFailedRemovesPending(): void
{
    $root = sys_get_temp_dir() . '/weline-solidify-jobs-dl-' . bin2hex(random_bytes(4));
    @mkdir($root, 0775, true);
    try {
        $paths = new ThemeLayoutEntityPaths($root . DIRECTORY_SEPARATOR);
        $store = new ThemeLayoutEntitySolidifyJobStore($paths);
        $key = ThemeLayoutEntitySolidifySerialKey::fromParts(9, 'frontend', 'x.default.default', 'normal', 'homepage', 'default', 1, 1);
        $store->put($key, str_repeat('c', 64));
        self::assertTrue($store->moveToFailed($key, 'unit_fail'));
        self::assertFalse($store->has($key));
        self::assertFileExists($root . '/9/.solidify-jobs/failed/' . $key->slotHash() . '.json');
    } finally {
        $this->purge_LayoutSolidifyDrainCron($root);
    }
}

private function purge_LayoutSolidifyDrainCron(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}
}
