<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Compilation;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\CompiledPhpRegistry;
use Weline\Framework\Runtime\SoftSurvivorCatalog;

final class CompiledPhpRegistryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-compiled-php-registry-' . bin2hex(random_bytes(6));
        if (!mkdir($concurrentDirectory = $this->tempDir, 0777, true) && !is_dir($concurrentDirectory)) {
            self::fail('Failed to create temp directory for CompiledPhpRegistry tests.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                CompiledPhpRegistry::forgetForTests($file);
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function testSecondLoadDoesNotReinclude(): void
    {
        $counterFile = $this->tempDir . DIRECTORY_SEPARATOR . 'include-count.txt';
        $phpFile = $this->tempDir . DIRECTORY_SEPARATOR . 'sample.php';
        file_put_contents($counterFile, '0');
        file_put_contents($phpFile, <<<PHP
<?php
\$c = (int) file_get_contents({$this->export($counterFile)});
file_put_contents({$this->export($counterFile)}, (string) (\$c + 1));
return ['n' => \$c + 1, 'v' => 'ok'];
PHP);

        CompiledPhpRegistry::forgetForTests($phpFile);
        $first = CompiledPhpRegistry::load($phpFile);
        $second = CompiledPhpRegistry::load($phpFile);

        self::assertSame(['n' => 1, 'v' => 'ok'], $first);
        self::assertSame($first, $second);
        self::assertSame('1', file_get_contents($counterFile));
        self::assertTrue(CompiledPhpRegistry::isLoaded($phpFile));
        self::assertSame($first, CompiledPhpRegistry::peek($phpFile));
    }

    public function testMissingFileMarksLoadedWithoutIncludeLoop(): void
    {
        $missing = $this->tempDir . DIRECTORY_SEPARATOR . 'missing-registry.php';
        CompiledPhpRegistry::forgetForTests($missing);

        self::assertNull(CompiledPhpRegistry::load($missing));
        self::assertTrue(CompiledPhpRegistry::isLoaded($missing));
        self::assertNull(CompiledPhpRegistry::load($missing));
        self::assertNull(CompiledPhpRegistry::peek($missing));
    }

    public function testLanguagePathIsHardRejected(): void
    {
        $paths = [
            BP . 'generated' . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR . 'en_US.php',
            BP . 'generated/language/zh_Hans_CN.php',
            $this->tempDir . '/generated/language/bag.php',
            $this->tempDir . '/generated/foo/language/bag.php',
        ];

        foreach ($paths as $path) {
            try {
                CompiledPhpRegistry::load($path);
                self::fail('Expected RuntimeException for language path: ' . $path);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('generated/language', $e->getMessage());
            }
            try {
                CompiledPhpRegistry::isLoaded($path);
                self::fail('Expected RuntimeException on isLoaded for: ' . $path);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('generated/language', $e->getMessage());
            }
        }
    }

    public function testSoftSurvivorRegistersCompiledPhpRegistryBag(): void
    {
        self::assertTrue(
            SoftSurvivorCatalog::isRegistrySoftSurvivor(
                'Weline\\Framework\\Compilation\\CompiledPhpRegistry::$entries'
            )
        );
        self::assertSame(
            SoftSurvivorCatalog::ROLE_REGISTRY,
            SoftSurvivorCatalog::roleFor('Weline\\Framework\\Compilation\\CompiledPhpRegistry::$entries')
        );
    }

    public function testValidateFalseStoresNullAndMarksLoaded(): void
    {
        $phpFile = $this->tempDir . DIRECTORY_SEPARATOR . 'invalid-shape.php';
        file_put_contents($phpFile, "<?php\nreturn ['bad' => true];\n");
        CompiledPhpRegistry::forgetForTests($phpFile);

        $payload = CompiledPhpRegistry::load($phpFile, static fn (array $row): bool => isset($row['good']));
        self::assertNull($payload);
        self::assertTrue(CompiledPhpRegistry::isLoaded($phpFile));
        self::assertNull(CompiledPhpRegistry::load($phpFile));
    }

    public function testWarmPathsMarksMustInPathsLoaded(): void
    {
        $paths = CompiledPhpRegistry::mustInRuntimePaths();
        self::assertNotEmpty($paths);
        self::assertStringContainsString('query_providers.php', $paths[0]);
        $joined = \implode("\n", \array_map(static fn (string $p): string => \str_replace('\\', '/', $p), $paths));
        self::assertStringContainsString('module_dependencies.php', $joined);
        self::assertStringContainsString('database/driver.php', $joined);
        foreach ($paths as $path) {
            self::assertStringNotContainsString('language/', \str_replace('\\', '/', $path));
            self::assertStringNotContainsString('compile_manifest.php', $path);
        }

        $stats = CompiledPhpRegistry::warmPaths($paths);
        self::assertSame(\count($paths), $stats['warmed'] + $stats['missing'] + $stats['skipped']);
        self::assertSame([], CompiledPhpRegistry::missingLoaded($paths));

        $again = CompiledPhpRegistry::warmPaths($paths);
        self::assertSame(0, $again['warmed']);
        self::assertSame(\count($paths), $again['skipped']);
    }

    public function testWarmProviderEnabledOnlyForWlsWorker(): void
    {
        $provider = new \Weline\Framework\Runtime\Preload\Provider\CompiledRegistryWarmProvider();
        $wls = new \Weline\Framework\Runtime\Preload\WorkerPreloadContext(
            \Weline\Framework\Runtime\Preload\WorkerPreloadContext::PHASE_BOOTSTRAP,
            'worker',
            1,
            'default',
            1,
            \Weline\Framework\Runtime\RuntimeInterface::MODE_WLS,
        );
        $fpm = new \Weline\Framework\Runtime\Preload\WorkerPreloadContext(
            \Weline\Framework\Runtime\Preload\WorkerPreloadContext::PHASE_BOOTSTRAP,
            'worker',
            1,
            'default',
            1,
            \Weline\Framework\Runtime\RuntimeInterface::MODE_FPM,
        );
        self::assertTrue($provider->isEnabled($wls));
        self::assertFalse($provider->isEnabled($fpm));
    }

    public function testWorkerPreloadManagerRegistersWarmProviderFirst(): void
    {
        $manager = \Weline\Framework\Runtime\Preload\WorkerPreloadManager::createDefault();
        $ref = new \ReflectionClass($manager);
        $prop = $ref->getProperty('providers');
        $prop->setAccessible(true);
        /** @var list<object> $providers */
        $providers = $prop->getValue($manager);
        self::assertNotEmpty($providers);
        self::assertInstanceOf(
            \Weline\Framework\Runtime\Preload\Provider\CompiledRegistryWarmProvider::class,
            $providers[0]
        );
        self::assertSame(5, $providers[0]->priority());
    }

    public function testPutWriteThroughPreventsReinclude(): void
    {
        $counterFile = $this->tempDir . DIRECTORY_SEPARATOR . 'put-include-count.txt';
        $phpFile = $this->tempDir . DIRECTORY_SEPARATOR . 'modules-like.php';
        file_put_contents($counterFile, '0');
        file_put_contents($phpFile, <<<PHP
<?php
\$c = (int) file_get_contents({$this->export($counterFile)});
file_put_contents({$this->export($counterFile)}, (string) (\$c + 1));
return ['A' => ['version' => '1.0.0']];
PHP);

        CompiledPhpRegistry::forgetForTests($phpFile);
        CompiledPhpRegistry::load($phpFile);
        self::assertSame('1', file_get_contents($counterFile));

        $updated = ['A' => ['version' => '2.0.0'], 'B' => ['version' => '1.0.0']];
        CompiledPhpRegistry::put($phpFile, $updated);

        $again = CompiledPhpRegistry::load($phpFile);
        self::assertSame($updated, $again);
        self::assertSame('1', file_get_contents($counterFile), 'put must not cause a second include');
        self::assertSame($updated, CompiledPhpRegistry::peek($phpFile));
    }

    public function testCompiledPhpArrayWriterPutsIntoRegistry(): void
    {
        $phpFile = $this->tempDir . DIRECTORY_SEPARATOR . 'written-modules.php';
        CompiledPhpRegistry::forgetForTests($phpFile);
        $data = ['Weline_Framework' => ['version' => '1.0.0', 'status' => true]];
        (new \Weline\Framework\Compilation\CompiledPhpArrayWriter())->write($phpFile, $data);

        self::assertTrue(CompiledPhpRegistry::isLoaded($phpFile));
        self::assertSame($data, CompiledPhpRegistry::peek($phpFile));
        self::assertSame($data, CompiledPhpRegistry::load($phpFile));
        self::assertFileExists($phpFile);
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }
}
