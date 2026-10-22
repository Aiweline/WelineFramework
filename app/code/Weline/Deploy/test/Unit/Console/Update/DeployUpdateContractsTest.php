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
            'CoreUpdateScope::testRequestedForceIsIgnoredWhenCommitsDifferButLocalCoreIsClean' => function () {
            $this->assert_CoreUpdateScope_testRequestedForceIsIgnoredWhenCommitsDifferButLocalCoreIsClean();
        },
            'CoreUpdateScope::testRequestedForceStaysWhenLocalDriftOrCommitsAreIncomparable' => function () {
            $this->assert_CoreUpdateScope_testRequestedForceStaysWhenLocalDriftOrCommitsAreIncomparable();
        },
            'CoreUpdateScope::testRelaxForceUsesCacheCommitAgainstLocalCore' => function () {
            $this->assert_CoreUpdateScope_testRelaxForceUsesCacheCommitAgainstLocalCore();
        },
            'CoreUpdateCache::testBrokenCacheIsReplacedByShallowCloneNotFullInit' => function () {
            $this->assert_CoreUpdateCache_testBrokenCacheIsReplacedByShallowCloneNotFullInit();
        },
            'CoreUpdateCache::testTransferSharesOneFiveMinuteBudget' => function () {
            $this->assert_CoreUpdateCache_testTransferSharesOneFiveMinuteBudget();
        },
            'CoreUpdateCache::testIndexPackRaceKeepsOnlyARealCommit' => function () {
            $this->assert_CoreUpdateCache_testIndexPackRaceKeepsOnlyARealCommit();
        },
            'CoreUpdateActivity::testCoreUpdateHoldsSystemUpdateLock' => function () {
            $this->assert_CoreUpdateActivity_testCoreUpdateHoldsSystemUpdateLock();
        },
            'CoreUpdateRemove::testVanishedChildDirectoryDoesNotAbortForceDelete' => function () {
            $this->assert_CoreUpdateRemove_testVanishedChildDirectoryDoesNotAbortForceDelete();
        },
        ];
    }

private function assert_CoreUpdateRemove_testVanishedChildDirectoryDoesNotAbortForceDelete(): void
{
    $fileLines = file((string) (new ReflectionMethod(Core::class, 'removeDirectory'))->getFileName()) ?: [];
    $body = '';
    foreach (['removeDirectory', 'removeDirectoryEntries'] as $methodName) {
        $method = new ReflectionMethod(Core::class, $methodName);
        $body .= implode('', array_slice(
            $fileLines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }
    self::assertStringNotContainsString('RecursiveDirectoryIterator', $body);
    self::assertStringContainsString('opendir', $body);

    $core = new Core($this->createMock(\Weline\Framework\Output\Cli\Printing::class), $this->createMock(\Weline\Framework\App\System::class));
    $remove = $this->method_CoreUpdateScope('removeDirectory');
    $tmp = sys_get_temp_dir() . '/core-update-rm-' . bin2hex(random_bytes(4));
    $debug = $tmp . '/app/code/Weline/Framework/Output/Debug';
    mkdir($debug . '/nested', 0755, true);
    file_put_contents($debug . '/nested/a.txt', 'a');
    file_put_contents($tmp . '/app/code/Weline/Framework/Output/plain.txt', 'b');
    symlink($tmp . '/missing-target', $tmp . '/app/code/Weline/Framework/Output/Gone');
    unlink($debug . '/nested/a.txt');
    rmdir($debug . '/nested');
    rmdir($debug);

    $remove->invoke($core, $tmp);
    self::assertFalse(is_dir($tmp));
    $remove->invoke($core, $tmp);
    self::assertFalse(file_exists($tmp));
}

private function assert_CoreUpdateCache_testBrokenCacheIsReplacedByShallowCloneNotFullInit(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Deploy/Console/Update/Core.php');
    self::assertStringContainsString("'clone', '--depth', '1', '--single-branch'", $src);
    self::assertStringContainsString("'-c', 'pack.threads=1'", $src);
    self::assertStringContainsString('shallowCloneUsableAfterIndexPackRace', $src);
    self::assertStringContainsString('invalid index-pack output', $src);
    self::assertStringContainsString('replaceCacheWithFreshClone', $src);
    self::assertStringContainsString('GIT_TERMINAL_PROMPT', $src);
    self::assertStringContainsString('git timed out after', $src);
    self::assertStringNotContainsString("['init']", $src);
    self::assertStringNotContainsString("['fetch', 'origin']", $src);
    self::assertStringNotContainsString("['fetch', '--tags']", $src);
}

private function assert_CoreUpdateCache_testTransferSharesOneFiveMinuteBudget(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Deploy/Console/Update/Core.php');
    self::assertStringContainsString('private const GIT_TRANSFER_TIMEOUT_SECONDS = 300;', $src);
    self::assertStringContainsString('armGitTransferBudget', $src);
    self::assertStringContainsString('runGitTransfer', $src);
    self::assertStringNotContainsString('GIT_FETCH_TIMEOUT_SECONDS', $src);
    self::assertStringNotContainsString('GIT_CLONE_TIMEOUT_SECONDS', $src);
    self::assertStringNotContainsString('GIT_CLONE_STALL_SECONDS', $src);
    self::assertStringNotContainsString('git produced no output for', $src);

    $core = new Core($this->createMock(\Weline\Framework\Output\Cli\Printing::class), $this->createMock(\Weline\Framework\App\System::class));
    $remain = new ReflectionMethod(Core::class, 'gitTransferSecondsRemaining');
    $remain->setAccessible(true);
    $left = $remain->invoke($core);
    self::assertGreaterThan(290, $left);
    self::assertLessThanOrEqual(300, $left);

    $deadline = new \ReflectionProperty(Core::class, 'gitTransferDeadlineAt');
    $deadline->setAccessible(true);
    $deadline->setValue($core, time() - 1);
    self::assertSame(0, $remain->invoke($core));

    $run = new ReflectionMethod(Core::class, 'runGitTransfer');
    $run->setAccessible(true);
    $output = [];
    $code = $run->invokeArgs($core, [getcwd() ?: '.', ['--version'], &$output]);
    self::assertSame(124, $code);
    self::assertContains('git transfer budget exhausted', $output);
}

private function assert_CoreUpdateCache_testIndexPackRaceKeepsOnlyARealCommit(): void
{
    $core = new Core($this->createMock(\Weline\Framework\Output\Cli\Printing::class), $this->createMock(\Weline\Framework\App\System::class));
    $usable = new ReflectionMethod(Core::class, 'shallowCloneUsableAfterIndexPackRace');
    $usable->setAccessible(true);
    $fatal = [
        'fatal: could not open tmp_pack_dyU5xq for reading: No such file or directory',
        'fatal: fetch-pack: invalid index-pack output',
    ];
    $tmp = sys_get_temp_dir() . '/core-update-race-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    try {
        self::assertFalse($usable->invoke($core, $tmp, $fatal));
        self::assertFalse($usable->invoke($core, $tmp, array_merge($fatal, ['git timed out after 300 seconds'])));
        $this->git_CoreUpdateScope($tmp, 'init -b dev');
        file_put_contents($tmp . '/keep.txt', 'ok');
        $this->git_CoreUpdateScope($tmp, 'add keep.txt');
        $this->git_CoreUpdateScope($tmp, '-c user.email=core-update@example.test -c user.name=core-update commit -m keep');
        self::assertTrue($usable->invoke($core, $tmp, $fatal));
        self::assertFalse($usable->invoke($core, $tmp, ['fatal: fetch-pack: invalid index-pack output']));
    } finally {
        $this->removeTree_CoreUpdateScope($tmp);
    }
}

private function assert_CoreUpdateActivity_testCoreUpdateHoldsSystemUpdateLock(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Deploy/Console/Update/Core.php');
    self::assertStringContainsString("SystemUpdateActivity::acquire('core:update')", $src);
    self::assertStringContainsString("SystemUpdateActivity::release('core:update')", $src);
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

private function assert_CoreUpdateScope_testRequestedForceIsIgnoredWhenCommitsDifferButLocalCoreIsClean(): void
{
    $core = new Core($this->createMock(\Weline\Framework\Output\Cli\Printing::class), $this->createMock(\Weline\Framework\App\System::class));
    $decide = $this->method_CoreUpdateScope('shouldHonorRequestedForce');
    $last = str_repeat('a', 40);
    $remote = str_repeat('b', 40);
    self::assertFalse($decide->invoke($core, $last, $remote, true, []));
    self::assertFalse($decide->invoke($core, $last, $last, true, []));
}

private function assert_CoreUpdateScope_testRequestedForceStaysWhenLocalDriftOrCommitsAreIncomparable(): void
{
    $core = new Core($this->createMock(\Weline\Framework\Output\Cli\Printing::class), $this->createMock(\Weline\Framework\App\System::class));
    $decide = $this->method_CoreUpdateScope('shouldHonorRequestedForce');
    $last = str_repeat('a', 40);
    $remote = str_repeat('b', 40);
    self::assertTrue($decide->invoke($core, $last, $remote, true, ['app/code/Weline/Deploy/Console/Update/Core.php']));
    self::assertTrue($decide->invoke($core, '', $remote, true, []));
    self::assertTrue($decide->invoke($core, $last, '', true, []));
    self::assertTrue($decide->invoke($core, 'deadbeef', $remote, true, []));
    self::assertTrue($decide->invoke($core, $last, $remote, false, []));
}

private function assert_CoreUpdateScope_testRelaxForceUsesCacheCommitAgainstLocalCore(): void
{
    $root = sys_get_temp_dir() . '/core-up-force-' . bin2hex(random_bytes(4));
    $bare = $root . '/remote.git';
    $cache = $root . '/cache';
    $rel = 'app/code/Weline/Deploy/Console/Update/Core.php';
    $local = BP . $rel;
    self::assertTrue(is_file($local));
    try {
        $this->git_CoreUpdateScope('', 'init --bare -b dev ' . escapeshellarg($bare));
        $this->git_CoreUpdateScope('', 'init -b dev ' . escapeshellarg($cache));
        $cacheFile = $cache . '/' . $rel;
        mkdir(dirname($cacheFile), 0755, true);
        copy($local, $cacheFile);
        $this->git_CoreUpdateScope($cache, 'add -- ' . escapeshellarg($rel));
        $this->git_CoreUpdateScope($cache, '-c user.email=core-update@example.test -c user.name=core-update commit -m baseline');
        $this->git_CoreUpdateScope($cache, 'remote add origin ' . escapeshellarg($bare));
        $this->git_CoreUpdateScope($cache, 'push origin HEAD:dev');

        $core = new Core($this->createMock(\Weline\Framework\Output\Cli\Printing::class), $this->createMock(\Weline\Framework\App\System::class));
        $relax = $this->method_CoreUpdateScope('relaxForceWhenLocalCoreHasNoConflict');
        $this->setPrivate_CoreUpdateScope($core, 'repoCandidates', [$bare]);
        $this->setPrivate_CoreUpdateScope($core, 'forceUpdate', true);
        $relax->invoke($core, $cache, 'dev');
        self::assertFalse($this->readPrivate_CoreUpdateScope($core, 'forceUpdate'));
        self::assertTrue($this->readPrivate_CoreUpdateScope($core, 'forceRelaxedBecauseClean'));

        $ahead = $root . '/ahead';
        $this->git_CoreUpdateScope('', 'clone ' . escapeshellarg($bare) . ' ' . escapeshellarg($ahead));
        file_put_contents($ahead . '/README-force-probe.txt', "ahead\n");
        $this->git_CoreUpdateScope($ahead, 'add -- README-force-probe.txt');
        $this->git_CoreUpdateScope($ahead, '-c user.email=core-update@example.test -c user.name=core-update commit -m ahead');
        $this->git_CoreUpdateScope($ahead, 'push origin HEAD:dev');
        $this->setPrivate_CoreUpdateScope($core, 'forceUpdate', true);
        $this->setPrivate_CoreUpdateScope($core, 'forceRelaxedBecauseClean', false);
        $relax->invoke($core, $cache, 'dev');
        self::assertFalse($this->readPrivate_CoreUpdateScope($core, 'forceUpdate'), 'remote tip ahead of a clean core must not keep -f');
        self::assertTrue($this->readPrivate_CoreUpdateScope($core, 'forceRelaxedBecauseClean'));

        file_put_contents($cacheFile, "<?php\n// drifted baseline\n");
        $this->git_CoreUpdateScope($cache, 'add -- ' . escapeshellarg($rel));
        $this->git_CoreUpdateScope($cache, '-c user.email=core-update@example.test -c user.name=core-update commit -m drift');
        $this->setPrivate_CoreUpdateScope($core, 'forceUpdate', true);
        $this->setPrivate_CoreUpdateScope($core, 'forceRelaxedBecauseClean', false);
        $relax->invoke($core, $cache, 'dev');
        self::assertTrue($this->readPrivate_CoreUpdateScope($core, 'forceUpdate'));
        self::assertFalse($this->readPrivate_CoreUpdateScope($core, 'forceRelaxedBecauseClean'));
    } finally {
        $this->removeTree_CoreUpdateScope($root);
    }
}

private function git_CoreUpdateScope(string $cwd, string $args): void
{
    $command = 'git ' . ($cwd !== '' ? '-C ' . escapeshellarg($cwd) . ' ' : '') . $args;
    exec($command . ' 2>&1', $output, $code);
    self::assertSame(0, $code, $command . "\n" . implode("\n", $output));
}

private function setPrivate_CoreUpdateScope(object $object, string $property, mixed $value): void
{
    $ref = new \ReflectionProperty($object, $property);
    $ref->setAccessible(true);
    $ref->setValue($object, $value);
}

private function readPrivate_CoreUpdateScope(object $object, string $property): mixed
{
    $ref = new \ReflectionProperty($object, $property);
    $ref->setAccessible(true);
    return $ref->getValue($object);
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
