<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Console\Update;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Deploy\Console\Update\Core;

/**
 * core:update 只同步核心仓库路径；冲突以「上次同步核心 commit」为基准。
 */
final class CoreUpdateScopeContractTest extends TestCase
{
    public function testCoreUpdatePathsStayInsideWelineAndNecessaryRoots(): void
    {
        $paths = $this->readPrivateConstant('CORE_UPDATE_PATHS');
        self::assertContains('app/code/Weline', $paths);
        self::assertContains('bin', $paths);
        self::assertNotContains('app', $paths);
        self::assertNotContains('app/code', $paths);
    }

    public function testBusinessModulesAreExplicitlyExcludedFromCopy(): void
    {
        $excluded = $this->readPrivateConstant('CORE_UPDATE_EXCLUDED_PATHS');
        self::assertContains('app/code/GuoLaiRen', $excluded);
        self::assertContains('app/code/Aiweline', $excluded);
        self::assertContains('app/code/WeShop', $excluded);
    }

    public function testDriftDetectionComparesLastSyncedCoreTreeOnly(): void
    {
        $core = new Core(
            $this->createMock(\Weline\Framework\Output\Cli\Printing::class),
            $this->createMock(\Weline\Framework\App\System::class)
        );
        $collect = $this->method('collectLocalCoreDriftAgainstLastSynced');

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
            $this->removeTree($tmp);
        }
    }

    public function testMatchingLastSyncedCoreFileIsNotDrift(): void
    {
        $core = new Core(
            $this->createMock(\Weline\Framework\Output\Cli\Printing::class),
            $this->createMock(\Weline\Framework\App\System::class)
        );
        $collect = $this->method('collectLocalCoreDriftAgainstLastSynced');

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
            $this->removeTree($tmp);
        }
    }

    public function testNoisePathsAreSkippedInDriftScan(): void
    {
        $noise = $this->method('isIgnorableWorkingTreeNoise');
        $skip = $this->method('shouldSkipCoreDriftPath');
        $core = new Core(
            $this->createMock(\Weline\Framework\Output\Cli\Printing::class),
            $this->createMock(\Weline\Framework\App\System::class)
        );

        self::assertTrue($noise->invoke($core, '.DS_Store'));
        self::assertTrue($noise->invoke($core, 'var/cache/__pycache__/core.pyc'));
        self::assertTrue($skip->invoke($core, 'app/code/GuoLaiRen/PageBuilder/X.php'));
        self::assertTrue($skip->invoke($core, 'app/etc/env.php'));
        self::assertFalse($skip->invoke($core, 'app/code/Weline/Deploy/Console/Update/Core.php'));
    }

    public function testIncrementalSyncOnlyFillsMissingFilesAndDoesNotOverwriteDrift(): void
    {
        $core = new Core(
            $this->createMock(\Weline\Framework\Output\Cli\Printing::class),
            $this->createMock(\Weline\Framework\App\System::class)
        );
        $sync = $this->method('syncIncrementalCoreFiles');

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

        $before = (string)file_get_contents($localExisting);
        try {
            $sync->invoke($core, $tmp);
            self::assertSame($before, (string)file_get_contents($localExisting), 'existing drifted core must not be overwritten');
            self::assertTrue(is_file($localMissing), 'missing core file must be filled');
            self::assertSame("<?php\n// missing probe\n", (string)file_get_contents($localMissing));
        } finally {
            if (is_file($localMissing)) {
                @unlink($localMissing);
            }
            $this->removeTree($tmp);
        }
    }

    /**
     * @return list<string>
     */
    private function readPrivateConstant(string $name): array
    {
        $ref = new ReflectionClass(Core::class);
        $value = $ref->getConstant($name);
        self::assertIsArray($value);

        return \array_values(\array_map('strval', $value));
    }

    private function method(string $name): ReflectionMethod
    {
        $method = new ReflectionMethod(Core::class, $name);
        $method->setAccessible(true);

        return $method;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
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
