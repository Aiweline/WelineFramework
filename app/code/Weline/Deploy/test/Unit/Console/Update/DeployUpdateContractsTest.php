<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Console\Update;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use ReflectionMethod as ReflectionMethod;
use Weline\Deploy\Console\Update\Core;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllDeployUpdateContractsPass().
 */
final class DeployUpdateContractsTest extends TestCase
{
    public function testAllDeployUpdateContractsPass(): void
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
            'CoreUpdateScope::testCoreUpdatePathsStayInsideWelineAndNecessaryRoots' => function () {
            $this->assert_CoreUpdateScope_testCoreUpdatePathsStayInsideWelineAndNecessaryRoots();
        },
            'CoreUpdateScope::testBusinessModulesAreExplicitlyExcludedFromCopy' => function () {
            $this->assert_CoreUpdateScope_testBusinessModulesAreExplicitlyExcludedFromCopy();
        },
            'CoreUpdateScope::testDriftDetectionComparesLastSyncedCoreTreeOnly' => function () {
            $this->assert_CoreUpdateScope_testDriftDetectionComparesLastSyncedCoreTreeOnly();
        },
            'CoreUpdateScope::testMatchingLastSyncedCoreFileIsNotDrift' => function () {
            $this->assert_CoreUpdateScope_testMatchingLastSyncedCoreFileIsNotDrift();
        },
            'CoreUpdateScope::testNoisePathsAreSkippedInDriftScan' => function () {
            $this->assert_CoreUpdateScope_testNoisePathsAreSkippedInDriftScan();
        },
            'CoreUpdateScope::testIncrementalSyncOnlyFillsMissingFilesAndDoesNotOverwriteDrift' => function () {
            $this->assert_CoreUpdateScope_testIncrementalSyncOnlyFillsMissingFilesAndDoesNotOverwriteDrift();
        },
        ];
    }

private function assert_CoreUpdateScope_testCoreUpdatePathsStayInsideWelineAndNecessaryRoots(): void
{
    $paths = $this->readPrivateConstant_CoreUpdateScope('CORE_UPDATE_PATHS');
    self::assertContains('app/code/Weline', $paths);
    self::assertContains('bin', $paths);
    self::assertNotContains('app', $paths);
    self::assertNotContains('app/code', $paths);
}

private function assert_CoreUpdateScope_testBusinessModulesAreExplicitlyExcludedFromCopy(): void
{
    $excluded = $this->readPrivateConstant_CoreUpdateScope('CORE_UPDATE_EXCLUDED_PATHS');
    self::assertContains('app/code/GuoLaiRen', $excluded);
    self::assertContains('app/code/Aiweline', $excluded);
    self::assertContains('app/code/WeShop', $excluded);
}

private function assert_CoreUpdateScope_testDriftDetectionComparesLastSyncedCoreTreeOnly(): void
{
    $core = new Core($this->createMock(\Weline\Framework\Output\Cli\Printing::class), $this->createMock(\Weline\Framework\App\System::class));
    $collect = $this->method_CoreUpdateScope('collectLocalCoreDriftAgainstLastSynced');
    $tmp = sys_get_temp_dir() . '/core-update-scope-' . bin2hex(random_bytes(4));
    $cacheFile = $tmp . '/app/code/Weline/Deploy/Console/Update/Core.php';
    $localFile = BP . 'app/code/Weline/Deploy/Console/Update/Core.php';
    self::assertTrue(is_file($localFile), 'local Core.php must exist for fixture');
    mkdir(dirname($cacheFile), 0755, true);
    // 缓存侧写入不同内容，模拟「上次同步 commit」与本地私改不一致
    file_put_contents($cacheFile, "<?php\n// last-synced baseline\n");
    // 业务路径即使写入缓存也不应进入漂移列表（不在 CORE_UPDATE_PATHS）
    $biz = $tmp . '/app/code/GuoLaiRen/PageBuilder/X.php';
    mkdir(dirname($biz), 0755, true);
    file_put_contents($biz, 'biz');
    try {
        $drifted = $collect->invoke($core, $tmp);
        self::assertContains('app/code/Weline/Deploy/Console/Update/Core.php', $drifted);
        self::assertNotContains('app/code/GuoLaiRen/PageBuilder/X.php', $drifted);
    } finally {
        $this->removeTree_CoreUpdateScope($tmp);
    }
}

private function assert_CoreUpdateScope_testMatchingLastSyncedCoreFileIsNotDrift(): void
{
    $core = new Core($this->createMock(\Weline\Framework\Output\Cli\Printing::class), $this->createMock(\Weline\Framework\App\System::class));
    $collect = $this->method_CoreUpdateScope('collectLocalCoreDriftAgainstLastSynced');
    $tmp = sys_get_temp_dir() . '/core-update-scope-' . bin2hex(random_bytes(4));
    $rel = 'app/code/Weline/Deploy/Console/Update/Core.php';
    $cacheFile = $tmp . '/' . $rel;
    $localFile = BP . $rel;
    mkdir(dirname($cacheFile), 0755, true);
    copy($localFile, $cacheFile);
    try {
        $drifted = $collect->invoke($core, $tmp);
        self::assertNotContains($rel, $drifted);
    } finally {
        $this->removeTree_CoreUpdateScope($tmp);
    }
}

private function assert_CoreUpdateScope_testNoisePathsAreSkippedInDriftScan(): void
{
    $noise = $this->method_CoreUpdateScope('isIgnorableWorkingTreeNoise');
    $skip = $this->method_CoreUpdateScope('shouldSkipCoreDriftPath');
    $core = new Core($this->createMock(\Weline\Framework\Output\Cli\Printing::class), $this->createMock(\Weline\Framework\App\System::class));
    self::assertTrue($noise->invoke($core, '.DS_Store'));
    self::assertTrue($noise->invoke($core, 'var/cache/__pycache__/core.pyc'));
    self::assertTrue($skip->invoke($core, 'app/code/GuoLaiRen/PageBuilder/X.php'));
    self::assertTrue($skip->invoke($core, 'app/etc/env.php'));
    self::assertFalse($skip->invoke($core, 'app/code/Weline/Deploy/Console/Update/Core.php'));
}

private function assert_CoreUpdateScope_testIncrementalSyncOnlyFillsMissingFilesAndDoesNotOverwriteDrift(): void
{
    $core = new Core($this->createMock(\Weline\Framework\Output\Cli\Printing::class), $this->createMock(\Weline\Framework\App\System::class));
    $sync = $this->method_CoreUpdateScope('syncIncrementalCoreFiles');
    $tmp = sys_get_temp_dir() . '/core-update-incr-' . bin2hex(random_bytes(4));
    $relExisting = 'app/code/Weline/Deploy/Console/Update/Core.php';
    $relMissing = 'app/code/Weline/Deploy/Console/Update/__core_update_missing_probe.php';
    $cacheExisting = $tmp . '/' . $relExisting;
    $cacheMissing = $tmp . '/' . $relMissing;
    $localExisting = BP . $relExisting;
    $localMissing = BP . $relMissing;
    self::assertTrue(is_file($localExisting), 'local Core.php must exist');
    self::assertFalse(is_file($localMissing), 'probe file must not already exist');
    mkdir(dirname($cacheExisting), 0755, true);
    file_put_contents($cacheExisting, "<?php\n// cache stale baseline — must NOT overwrite local\n");
    file_put_contents($cacheMissing, "<?php\n// missing probe\n");
    $before = (string) file_get_contents($localExisting);
    try {
        $sync->invoke($core, $tmp);
        self::assertSame($before, (string) file_get_contents($localExisting), 'existing drifted core must not be overwritten');
        self::assertTrue(is_file($localMissing), 'missing core file must be filled');
        self::assertSame("<?php\n// missing probe\n", (string) file_get_contents($localMissing));
    } finally {
        if (is_file($localMissing)) {
            @unlink($localMissing);
        }
        $this->removeTree_CoreUpdateScope($tmp);
    }
}

/**
 * @return list<string>
 */
private function readPrivateConstant_CoreUpdateScope(string $name): array
{
    $ref = new ReflectionClass(Core::class);
    $value = $ref->getConstant($name);
    self::assertIsArray($value);
    return \array_values(\array_map('strval', $value));
}

private function method_CoreUpdateScope(string $name): ReflectionMethod
{
    $method = new ReflectionMethod(Core::class, $name);
    $method->setAccessible(true);
    return $method;
}

private function removeTree_CoreUpdateScope(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
}
